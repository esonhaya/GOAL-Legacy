<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Events\EventDispatcherInterface;
use Goal\Legacy\Core\Events\GenericEvent;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\PlayerSelection;
use Goal\Legacy\Modules\Match\Domain\SelectionStatus;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunity;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunityType;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunityStatus;
use Goal\Legacy\Modules\Player\Persistence\CareerEvaluationRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;

final class ClubExpectationService
{
    public function __construct(private readonly ClubService $clubService, private readonly ?EventDispatcherInterface $events = null)
    {
    }

    /** @return array<string, int|string> */
    public function expectation(DatabaseInterface $database, ClubSquadMembership $membership): array
    {
        return ['expected_score' => $membership->role()->expectationScore(), 'role' => $membership->role()->value, 'club_id' => $membership->clubId()->value(), 'player_id' => $membership->playerId()->value()];
    }

    /** @return list<array<string, mixed>> */
    public function evaluateMatch(DatabaseInterface $database, GameMatch $match): array
    {
        $selectionRepository = new MatchSelectionRepository($database);
        $selections = $selectionRepository->byMatch($match->id());
        $stats = [];
        foreach ((new PlayerMatchStatRepository($database))->byMatch($match->id()) as $stat) { $stats[$stat->playerId()->value()] = $stat; }
        $evaluations = $database->transaction(function () use ($database, $match, $selections, $stats): array {
            $evaluationRepository = new CareerEvaluationRepository($database);
            $playerRepository = new PlayerRepository($database);
            $squadRepository = $this->clubService->squadRepository($database);
            $opportunities = new CareerOpportunityService($this->events);
            $result = [];
            foreach ($selections as $selection) {
                if ($evaluationRepository->exists($match->id(), $selection->playerId())) { continue; }
                $membership = $this->membership($squadRepository->byPlayer($selection->playerId(), $match->seasonId()), $selection->clubId()->value());
                if ($membership === null) { continue; }
                if ($selection->status() === SelectionStatus::Unavailable) { continue; }
                $stat = $stats[$selection->playerId()->value()] ?? null;
                if ($stat === null || !$stat->appeared()) { continue; }
                $evaluation = (new PlayerPerformanceEvaluator())->evaluate($stat, $match);
                $expected = $membership->role()->expectationScore();
                $status = $evaluation->score() >= $expected + 10 ? 'exceeding' : ($evaluation->score() >= $expected - 5 ? 'meeting' : ($evaluation->score() >= $expected - 15 ? 'below' : 'significantly_below'));
                $evaluationRepository->saveInTransaction(['match_id' => $match->id()->value(), 'player_id' => $selection->playerId()->value(), 'club_id' => $selection->clubId()->value(), 'occurred_date' => $match->scheduledDate()->toIsoString(), 'evaluation_score' => $evaluation->score(), 'expectation_status' => $status]);
                $row = ['player_id' => $selection->playerId()->value(), 'club_id' => $selection->clubId()->value(), 'score' => $evaluation->score(), 'status' => $status, 'role' => $membership->role()->value];
                $roleChange = $this->transitionRole($database, $match, $membership, $opportunities);
                if ($roleChange !== null) { $row['role_change'] = $roleChange; }
                $result[] = $row;
            }
            return $result;
        });
        foreach ($evaluations as $evaluation) {
            $this->events?->dispatch(new GenericEvent('club.expectation_evaluated', $evaluation));
            if (isset($evaluation['role_change'])) { $this->events?->dispatch(new GenericEvent('club.player_role_changed', $evaluation['role_change'])); }
        }

        return $evaluations;
    }

    /** @return array<string, mixed> */
    public function latest(DatabaseInterface $database, ClubSquadMembership $membership): array
    {
        $latest = (new CareerEvaluationRepository($database))->latest($membership->playerId(), $membership->clubId());
        $base = $this->expectation($database, $membership);
        $base['last_evaluation_score'] = $latest === null ? null : (int) $latest['evaluation_score'];
        $base['expectation_status'] = $latest['expectation_status'] ?? 'unassessed';

        return $base;
    }

    /** @return array<string, string>|null */
    private function transitionRole(DatabaseInterface $database, GameMatch $match, ClubSquadMembership $membership, CareerOpportunityService $opportunities): ?array
    {
        $rows = array_slice((new CareerEvaluationRepository($database))->byPlayer($membership->playerId(), $membership->clubId()), 0, 2);
        if (count($rows) < 2) { return null; }
        $appearances = 0; $starts = 0; $scores = [];
        $selectionRepository = new MatchSelectionRepository($database);
        foreach ($rows as $row) {
            $scores[] = (int) $row['evaluation_score'];
            $selection = array_values(array_filter($selectionRepository->byMatch((string) $row['match_id']), static fn (PlayerSelection $value): bool => $value->playerId()->value() === $membership->playerId()->value()))[0] ?? null;
            if ($selection?->status() === SelectionStatus::Starter) { ++$starts; }
            if ((int) $row['evaluation_score'] > 0) { ++$appearances; }
        }
        $average = (int) round(array_sum($scores) / count($scores));
        $next = null;
        if ($membership->role() === SquadRole::Prospect && $appearances >= 2 && $average >= 65) { $next = $membership->role()->promoted(); }
        elseif ($membership->role() === SquadRole::Rotation && $starts >= 2 && $average >= 65) { $next = $membership->role()->promoted(); }
        elseif ($membership->role() === SquadRole::Regular && $starts >= 2 && $average >= 75) { $next = $membership->role()->promoted(); }
        elseif ($appearances === 0 && $membership->role()->demoted() !== null) { $next = $membership->role()->demoted(); }
        if ($next !== null && $next !== $membership->role()) {
            $this->clubService->squadRepository($database)->updateRole($membership, $next, $match->scheduledDate()->toIsoString(), 'evaluation');
            $opportunity = new CareerOpportunity(hash('sha256', 'role|' . $match->id()->value() . '|' . $membership->playerId()->value() . '|' . $next->value), $membership->playerId(), CareerOpportunityType::RoleIncrease, $membership->clubId(), null, $match->scheduledDate(), null, CareerOpportunityStatus::Open, ['average_score' => $average, 'new_role' => $next->value], 'role|' . $match->id()->value() . '|' . $membership->playerId()->value() . '|' . $next->value);
            $opportunities->createInTransaction($database, $opportunity);
            return ['player_id' => $membership->playerId()->value(), 'club_id' => $membership->clubId()->value(), 'old_role' => $membership->role()->value, 'new_role' => $next->value];
        }

        return null;
    }

    private function membership(array $memberships, string $clubId): ?ClubSquadMembership
    {
        foreach ($memberships as $membership) { if ($membership->clubId()->value() === $clubId) { return $membership; } }

        return null;
    }
}
