<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Events\EventDispatcherInterface;
use Goal\Legacy\Core\Events\GenericEvent;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\Domain\DevelopmentApplicationResult;
use Goal\Legacy\Modules\Player\Domain\DevelopmentEventNames;
use Goal\Legacy\Modules\Player\Domain\DevelopmentHistoryEntry;
use Goal\Legacy\Modules\Player\Domain\DevelopmentProfile;
use Goal\Legacy\Modules\Player\Domain\DevelopmentState;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\TrainingFocus;
use Goal\Legacy\Modules\Player\Domain\TrainingRequest;
use Goal\Legacy\Modules\Player\Persistence\PlayerDevelopmentRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

final class PlayerDevelopmentService
{
    public function __construct(private readonly ?EventDispatcherInterface $events = null)
    {
    }

    public function applyTraining(DatabaseInterface $database, TrainingRequest $request): DevelopmentApplicationResult
    {
        $result = $database->transaction(fn (): DevelopmentApplicationResult => $this->applyTrainingInTransaction($database, $request));
        $this->dispatchTrainingResult($result);

        return $result;
    }

    /** Must be called inside the caller's existing transaction. */
    public function applyTrainingInTransaction(DatabaseInterface $database, TrainingRequest $request): DevelopmentApplicationResult
    {
        return $this->applyStimulusInTransaction(
            $database,
            $request->playerId(),
            $request->endDate(),
            'training',
            $request->blockId(),
            $this->trainingStimulus($database, $request),
            $request->focus(),
        );
    }

    /** Must be called inside the caller's existing transaction. */
    public function skipTrainingInTransaction(DatabaseInterface $database, TrainingRequest $request): DevelopmentApplicationResult
    {
        $development = new PlayerDevelopmentRepository($database);
        if ($development->hasSource($request->playerId(), 'training', $request->blockId())) {
            $entry = $development->bySource($request->playerId(), 'training', $request->blockId());
            if ($entry === null) {
                throw new \RuntimeException('Training source was reported as processed but could not be loaded.');
            }

            return DevelopmentApplicationResult::skipped($request->playerId(), 'training', $request->blockId(), $entry->afterOverall());
        }
        $player = (new PlayerRepository($database))->get($request->playerId());
        $state = $development->state($request->playerId());
        $development->saveStateInTransaction($state->withProgress($state->progress(), $request->endDate(), $request->focus()));
        $development->saveHistoryInTransaction(new DevelopmentHistoryEntry(hash('sha256', $request->playerId()->value() . '|training|' . $request->blockId()), $request->playerId(), $request->endDate(), 'training', $request->blockId(), [], $player->overallRating(), $player->overallRating()));

        return DevelopmentApplicationResult::skipped($request->playerId(), 'training', $request->blockId(), $player->overallRating());
    }

    public function dispatchTrainingResult(DevelopmentApplicationResult $result): void
    {
        if (!$result->applied() || $this->events === null) {
            return;
        }
        $this->events->dispatch(new GenericEvent(DevelopmentEventNames::TRAINING_COMPLETED, $result->toArray()));
        $this->events->dispatch(new GenericEvent(DevelopmentEventNames::PLAYER_DEVELOPED, $result->toArray()));
    }

    /** @return list<DevelopmentApplicationResult> */
    public function applyMatch(DatabaseInterface $database, GameMatch $match): array
    {
        $results = $database->transaction(fn (): array => $this->applyMatchInTransaction($database, $match));
        $this->dispatchResults($results);

        return $results;
    }

    /** Must be called inside the caller's existing transaction. */
    /** @return list<DevelopmentApplicationResult> */
    public function applyMatchInTransaction(DatabaseInterface $database, GameMatch $match): array
    {
        $players = new PlayerMatchStatRepository($database)->byMatch($match->id());
        $results = [];
        foreach ($players as $stat) {
            if (!$stat->appeared() || $stat->minutes() < 1) {
                continue;
            }
            $results[] = $this->applyStimulusInTransaction(
                $database,
                $stat->playerId(),
                $match->scheduledDate(),
                'match',
                $match->id()->value() . ':' . $stat->playerId()->value(),
                $stat->minutes() * 12,
                TrainingFocus::Balanced,
            );
        }

        return $results;
    }

    public function state(DatabaseInterface $database, PlayerId|string $playerId): DevelopmentState
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);

        return (new PlayerDevelopmentRepository($database))->state($id);
    }

    /** @return list<DevelopmentHistoryEntry> */
    public function history(DatabaseInterface $database, PlayerId|string $playerId): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);

        return (new PlayerDevelopmentRepository($database))->byPlayer($id);
    }

    private function trainingStimulus(DatabaseInterface $database, TrainingRequest $request): int
    {
        $player = (new PlayerRepository($database))->get($request->playerId());
        $weeks = max(1, intdiv($request->startDate()->daysUntil($request->endDate()), 7));

        return $weeks * 1500 * $this->curvePercent($player, $request->endDate()) * $this->potentialPercent($player) / 10000;
    }

    private function applyStimulusInTransaction(
        DatabaseInterface $database,
        PlayerId $playerId,
        SimulationDate $date,
        string $source,
        string $sourceId,
        int|float $stimulus,
        ?TrainingFocus $focus,
    ): DevelopmentApplicationResult {
        $development = new PlayerDevelopmentRepository($database);
        if ($development->hasSource($playerId, $source, $sourceId)) {
            $entry = $development->bySource($playerId, $source, $sourceId);
            if ($entry === null) {
                throw new \RuntimeException('Development source was reported as processed but could not be loaded.');
            }

            return new DevelopmentApplicationResult($playerId, $source, $sourceId, $entry->attributeDeltas(), $entry->beforeOverall(), $entry->afterOverall(), false);
        }
        $players = new PlayerRepository($database);
        $player = $players->get($playerId);
        $state = $development->state($playerId);
        $before = $player->overallRating();
        $progress = $state->progress();
        $weights = $this->weights($focus);
        $totalWeight = array_sum($weights);
        $allocated = (int) floor($stimulus);
        foreach ($weights as $attribute => $weight) {
            $progress[$attribute] = ($progress[$attribute] ?? 0) + intdiv($allocated * $weight, $totalWeight);
        }
        [$updated, $nextProgress, $deltas] = $this->applyAttributePoints($player, $progress);
        $players->saveInTransaction($updated);
        $development->saveStateInTransaction($state->withProgress($nextProgress, $date, $focus));
        $after = $updated->overallRating();
        $entry = new DevelopmentHistoryEntry(
            hash('sha256', $playerId->value() . '|' . $source . '|' . $sourceId),
            $playerId,
            $date,
            $source,
            $sourceId,
            $deltas,
            $before,
            $after,
        );
        $development->saveHistoryInTransaction($entry);

        return new DevelopmentApplicationResult($playerId, $source, $sourceId, $deltas, $before, $after, true);
    }

    /** @return array<string, int> */
    private function weights(?TrainingFocus $focus): array
    {
        $weights = array_fill_keys(['pace', 'shooting', 'passing', 'dribbling', 'defending', 'physicality'], 1);
        if ($focus !== null && $focus !== TrainingFocus::Balanced) {
            $weights[$focus->value] = 4;
        }

        return $weights;
    }

    /** @return array{0: Player, 1: array<string, int>, 2: array<string, int>} */
    private function applyAttributePoints(Player $player, array $progress): array
    {
        $attributes = $player->attributes()->toArray();
        $deltas = array_fill_keys(array_keys($attributes), 0);
        foreach ($attributes as $name => $value) {
            $points = intdiv((int) ($progress[$name] ?? 0), 1000);
            for ($step = 0; $step < $points; ++$step) {
                if ($attributes[$name] >= 99) {
                    break;
                }
                $candidate = $attributes;
                ++$candidate[$name];
                $candidatePlayer = $player->withAttributes(new PlayerAttributeSet(...array_values($candidate)));
                if ($candidatePlayer->overallRating() > $player->potential()) {
                    break;
                }
                $attributes = $candidate;
                ++$deltas[$name];
            }
            $progress[$name] = (int) ($progress[$name] ?? 0) - ($deltas[$name] * 1000);
        }

        return [$player->withAttributes(new PlayerAttributeSet(...array_values($attributes))), $progress, array_filter($deltas, static fn (int $value): bool => $value > 0)];
    }

    private function curvePercent(Player $player, SimulationDate $date): int
    {
        $age = $player->ageAt($date);
        return match ($player->developmentProfile()) {
            DevelopmentProfile::LateBloomer => $age < 18 ? 35 : ($age <= 20 ? 55 : ($age <= 24 ? 75 : ($age <= 28 ? 100 : ($age <= 32 ? 65 : 0)))),
            DevelopmentProfile::Prodigy => $age < 18 ? 100 : ($age <= 20 ? 125 : ($age <= 24 ? 90 : ($age <= 28 ? 45 : ($age <= 32 ? 15 : 0)))),
            DevelopmentProfile::Regular => $age < 18 ? 70 : ($age <= 20 ? 100 : ($age <= 24 ? 100 : ($age <= 28 ? 65 : ($age <= 32 ? 25 : 0)))),
        };
    }

    private function potentialPercent(Player $player): int
    {
        $gap = $player->potential() - $player->overallRating();

        return $gap <= 0 ? 0 : ($gap <= 3 ? 25 : ($gap <= 8 ? 55 : 100));
    }

    /** @param list<DevelopmentApplicationResult> $results */
    private function dispatchResults(array $results): void
    {
        if ($this->events === null) {
            return;
        }
        foreach ($results as $result) {
            if ($result->applied()) {
                $this->events->dispatch(new GenericEvent(DevelopmentEventNames::PLAYER_DEVELOPED, $result->toArray()));
            }
        }
    }
}
