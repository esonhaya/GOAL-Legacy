<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Events\EventDispatcherInterface;
use Goal\Legacy\Core\Events\GenericEvent;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\Domain\AvailabilityAssessment;
use Goal\Legacy\Modules\Player\Domain\AvailabilityEventNames;
use Goal\Legacy\Modules\Player\Domain\AvailabilityStatus;
use Goal\Legacy\Modules\Player\Domain\Injury;
use Goal\Legacy\Modules\Player\Domain\InjuryCategory;
use Goal\Legacy\Modules\Player\Domain\InjurySeverity;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\TrainingIntensity;
use Goal\Legacy\Modules\Player\Persistence\PlayerAvailabilityRepository;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

final class PlayerAvailabilityService
{
    private const LIMITED_FATIGUE = 50;
    private const UNAVAILABLE_FATIGUE = 85;
    private const MATCH_LOAD_PER_MINUTE = 2 / 3;

    public function __construct(private readonly ?EventDispatcherInterface $events = null)
    {
    }

    public function assess(DatabaseInterface $database, PlayerId|string $playerId, SimulationDate $date): AvailabilityAssessment
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $repository = new PlayerAvailabilityRepository($database);
        $injury = $repository->activeInjuryAt($id, $date);
        $fatigue = $repository->fatigueAt($id, $date);
        if ($injury !== null || $fatigue >= self::UNAVAILABLE_FATIGUE) {
            return new AvailabilityAssessment($id, AvailabilityStatus::Unavailable, $fatigue, $date, $injury);
        }
        if ($fatigue >= self::LIMITED_FATIGUE) {
            return new AvailabilityAssessment($id, AvailabilityStatus::Limited, $fatigue, $date);
        }

        return new AvailabilityAssessment($id, AvailabilityStatus::Available, $fatigue, $date);
    }

    /** @return list<array{event:string,payload:array<string,mixed>}> */
    /** @param list<\Goal\Legacy\Modules\Match\Domain\PlayerMatchStat>|null $stats */
    public function applyMatchInTransaction(DatabaseInterface $database, GameMatch $match, ?array $stats = null, bool $persistMatchSources = true): array
    {
        $repository = new PlayerAvailabilityRepository($database);
        $changes = [];
        foreach ($stats ?? (new PlayerMatchStatRepository($database))->byMatch($match->id()) as $stat) {
            if (!$stat->appeared() || $stat->minutes() < 1) {
                continue;
            }
            $player = $stat->playerId();
            $sourceId = $match->id()->value() . ':' . $player->value();
            $before = $repository->fatigueAt($player, $match->scheduledDate());
            $this->applyLoadInTransaction($repository, $player, $match->scheduledDate(), 'match', $sourceId, (int) round($stat->minutes() * self::MATCH_LOAD_PER_MINUTE), $persistMatchSources);
            if ($repository->activeInjuryAt($player, $match->scheduledDate()) !== null || $repository->hasSource($player, 'injury', $sourceId)) {
                continue;
            }
            $injury = $this->determineInjury($player, $match, $before, $sourceId);
            if ($injury === null) {
                continue;
            }
            $repository->saveInjuryInTransaction($injury);
            $repository->recordSourceInTransaction($player, 'injury', $sourceId, 0, $match->scheduledDate());
            $changes[] = ['event' => AvailabilityEventNames::INJURED, 'payload' => $injury->toArray()];
        }

        return $changes;
    }

    /** @return list<array{event:string,payload:array<string,mixed>}> */
    public function applyTrainingInTransaction(DatabaseInterface $database, PlayerId $playerId, string $sourceId, SimulationDate $date, int $weeks, TrainingIntensity $intensity = TrainingIntensity::Normal): array
    {
        $repository = new PlayerAvailabilityRepository($database);
        if ($repository->hasSource($playerId, 'training', $sourceId)) {
            return [];
        }
        $assessment = $this->assess($database, $playerId, $date);
        if ($assessment->isUnavailable()) {
            $repository->recordSourceInTransaction($playerId, 'training', $sourceId, 0, $date);

            return [];
        }
        $before = $assessment->fatigue();
        $this->applyLoadInTransaction($repository, $playerId, $date, 'training', $sourceId, max(0, $weeks) * $intensity->loadPerWeek());
        $injury = $this->determineTrainingInjury($playerId, $date, $sourceId, $before, $intensity);
        if ($injury === null) {
            return [];
        }
        $repository->saveInjuryInTransaction($injury);
        $repository->recordSourceInTransaction($playerId, 'injury', 'training:' . $sourceId, 0, $date);

        return [['event' => AvailabilityEventNames::INJURED, 'payload' => $injury->toArray()]];
    }

    /** @return list<array{event:string,payload:array<string,mixed>}> */
    public function reconcileInTransaction(DatabaseInterface $database, SimulationDate $date): array
    {
        $repository = new PlayerAvailabilityRepository($database);
        $changes = [];
        foreach ($repository->dueActiveInjuries($date) as $injury) {
            $repository->markRecoveredInTransaction($injury, $date);
            $changes[] = ['event' => AvailabilityEventNames::RECOVERED, 'payload' => $injury->recovered($date)->toArray()];
        }

        return $changes;
    }

    /** @param list<array{event:string,payload:array<string,mixed>}> $changes */
    public function dispatchChanges(array $changes): void
    {
        if ($this->events === null) {
            return;
        }
        foreach ($changes as $change) {
            $this->events->dispatch(new GenericEvent($change['event'], $change['payload']));
        }
    }

    /** @return list<Injury> */
    public function injuries(DatabaseInterface $database, PlayerId|string $playerId): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);

        return (new PlayerAvailabilityRepository($database))->byPlayer($id);
    }

    private function applyLoadInTransaction(PlayerAvailabilityRepository $repository, PlayerId $playerId, SimulationDate $date, string $sourceType, string $sourceId, int $load, bool $persistSource = true): void
    {
        if ($persistSource && $repository->hasSource($playerId, $sourceType, $sourceId)) {
            return;
        }
        $state = $repository->state($playerId);
        $before = $repository->fatigueAt($playerId, $date);
        $repository->saveStateInTransaction($playerId, $before + max(0, $load), $date, $state['revision'] + 1);
        if ($persistSource) {
            $repository->recordSourceInTransaction($playerId, $sourceType, $sourceId, max(0, $load), $date);
        }
    }

    private function determineInjury(PlayerId $playerId, GameMatch $match, int $fatigueBefore, string $sourceId): ?Injury
    {
        $riskPercent = min(8, 1 + intdiv($fatigueBefore, 15));
        $risk = $this->unit('risk|' . $sourceId);
        if ($risk >= ($riskPercent / 100)) {
            return null;
        }
        $severityRoll = $this->unit('severity|' . $sourceId);
        $severity = $severityRoll < 0.70 ? InjurySeverity::Minor : ($severityRoll < 0.95 ? InjurySeverity::Moderate : InjurySeverity::Major);
        $categories = InjuryCategory::cases();
        $category = $categories[(int) floor($this->unit('category|' . $sourceId) * count($categories)) % count($categories)];
        $start = $match->scheduledDate();

        return new Injury(hash('sha256', 'injury|' . $sourceId), $playerId, 'match', $match->id()->value(), $category, $severity, $start, $start->addDays($severity->durationDays()));
    }

    private function determineTrainingInjury(PlayerId $playerId, SimulationDate $date, string $sourceId, int $fatigueBefore, TrainingIntensity $intensity): ?Injury
    {
        if ($intensity !== TrainingIntensity::Intense) {
            return null;
        }
        $riskPercent = min(6, 1 + intdiv($fatigueBefore, 20));
        if ($this->unit('training-risk|' . $sourceId) >= ($riskPercent / 100)) {
            return null;
        }
        $severityRoll = $this->unit('training-severity|' . $sourceId);
        $severity = $severityRoll < 0.85 ? InjurySeverity::Minor : InjurySeverity::Moderate;
        $categories = InjuryCategory::cases();
        $category = $categories[(int) floor($this->unit('training-category|' . $sourceId) * count($categories)) % count($categories)];

        return new Injury(hash('sha256', 'injury|training|' . $sourceId), $playerId, 'training', $sourceId, $category, $severity, $date, $date->addDays($severity->durationDays()));
    }

    private function unit(string $key): float
    {
        return hexdec(substr(hash('sha256', $key), 0, 12)) / 281474976710655;
    }
}
