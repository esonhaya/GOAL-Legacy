<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Simulation;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Simulation\SimulationCheckpoint;
use Goal\Legacy\Core\Simulation\SimulationResult;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerAvailabilityRepository;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use RuntimeException;

/** Runs bounded Matches through WorldService and MatchService only. */
final class GoalMatchRunner
{
    public function __construct(private readonly CoreServices $services) {}

    public function one(DatabaseInterface $database, string $saveId, string $playerId, ?string $matchId = null): SimulationResult
    {
        return $this->many($database, $saveId, $playerId, 1, $matchId);
    }

    public function many(DatabaseInterface $database, string $saveId, string $playerId, int $count = 1, ?string $firstMatchId = null): SimulationResult
    {
        if ($count < 1 || $count > 10) {
            throw new RuntimeException('Simulation Lab Match runs are limited to 1-10 Matches.');
        }
        $checkpoints = [];
        $completed = [];
        for ($index = 0; $index < $count; ++$index) {
            $match = $this->nextMatch($database, $saveId, $playerId, $index === 0 ? $firstMatchId : null);
            if ($match === null) {
                break;
            }
            $worldService = $this->services->worldModule()->service();
            $worldService->advanceToDate($database, $saveId, $match->scheduledDate());
            $before = $this->state($database, $playerId, $match->id()->value(), null);
            $resolved = $this->services->matchModule()->service()->simulateDue($database, $match->scheduledDate());
            $resolvedMatch = null;
            foreach ($resolved as $candidate) {
                if ($candidate->id()->value() === $match->id()->value()) {
                    $resolvedMatch = $candidate;
                    break;
                }
            }
            if ($resolvedMatch === null) {
                throw new RuntimeException('Canonical Match runner did not resolve the requested fixture.');
            }
            $after = $this->state($database, $playerId, $resolvedMatch->id()->value(), $resolvedMatch);
            $checkpoints[] = new SimulationCheckpoint('MATCH_' . ($index + 1) . '_BEFORE', 'GOAL_MATCH', $before, ['source' => 'GoalMatchRunner']);
            $checkpoints[] = new SimulationCheckpoint('MATCH_' . ($index + 1), 'GOAL_MATCH', $after, ['source' => 'GoalMatchRunner']);
            $completed[] = $resolvedMatch->id()->value();
        }
        if ($completed === []) {
            throw new RuntimeException('No scheduled controlled-Club Match is available in the current Season.');
        }

        return new SimulationResult('PASS', $checkpoints, [], ['matches_requested' => $count, 'matches_completed' => count($completed), 'match_ids' => $completed]);
    }

    private function nextMatch(DatabaseInterface $database, string $saveId, string $playerId, ?string $matchId): ?object
    {
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->load($database, $saveId);
        $seasonId = $world->currentSeasonId();
        if ($seasonId === null) {
            throw new RuntimeException('The save has no active Season.');
        }
        $membership = (new \Goal\Legacy\Modules\Club\Persistence\ClubSquadRepository($database))->byPlayer($playerId, $seasonId)[0] ?? null;
        if ($membership === null) {
            throw new RuntimeException('The controlled Player has no active Club membership.');
        }
        $currentDate = $world->currentDate($worldService->calendar());
        $matches = (new MatchRepository($database))->byClub($membership->clubId(), $seasonId);
        foreach ($matches as $match) {
            if ($match->status() !== MatchStatus::Scheduled || $match->scheduledDate()->isBefore($currentDate)) {
                continue;
            }
            if ($matchId !== null && $match->id()->value() !== $matchId) {
                continue;
            }
            return $match;
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function state(DatabaseInterface $database, string $playerId, string $matchId, ?object $match): array
    {
        $state = ['player_id' => $playerId, 'match_id' => $matchId];
        if ($match === null) {
            return $state;
        }
        $service = $this->services->matchModule()->service();
        $summary = $service->playerSummary($database, $matchId, $playerId);
        $selection = null;
        foreach ($service->selectionRepository($database)->byMatch($matchId) as $candidate) {
            if ($candidate->playerId()->value() === $playerId) {
                $selection = $candidate->toArray();
                break;
            }
        }
        $assessment = (new \Goal\Legacy\Modules\Player\PlayerAvailabilityService())->assess($database, $playerId, $match->scheduledDate());
        $state += [
            'scheduled_date' => $match->scheduledDate()->toIsoString(),
            'status' => $match->status()->value,
            'result' => $match->result()?->toArray(),
            'selection' => $selection,
            'summary' => $summary,
            'availability' => $assessment->toArray(),
        ];

        return $state;
    }
}
