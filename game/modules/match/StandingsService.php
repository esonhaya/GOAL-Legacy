<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\World\Domain\SeasonId;

final class StandingsService
{
    public function __construct(private readonly ClubService $clubService) {}

    /** @return list<array<string, int|string>> */
    public function table(DatabaseInterface $database, CompetitionId $competitionId, SeasonId $seasonId): array
    {
        $rows = [];
        foreach ($this->clubService->membershipRepository($database)->byCompetition($competitionId, $seasonId) as $membership) {
            $id = $membership->clubId()->value();
            $rows[$id] = ['club_id' => $id, 'played' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'goals_for' => 0, 'goals_against' => 0, 'goal_difference' => 0, 'points' => 0];
        }
        foreach ((new MatchRepository($database))->completedByCompetition($competitionId, $seasonId) as $match) { $this->apply($rows, $match); }
        foreach ($rows as &$row) { $row['goal_difference'] = $row['goals_for'] - $row['goals_against']; }
        unset($row);
        uasort($rows, static fn (array $a, array $b): int => ($b['points'] <=> $a['points']) ?: ($b['goal_difference'] <=> $a['goal_difference']) ?: ($b['goals_for'] <=> $a['goals_for']) ?: strcmp((string) $a['club_id'], (string) $b['club_id']));
        return array_values($rows);
    }

    /** @param array<string, array<string, int|string>> $rows */
    private function apply(array &$rows, GameMatch $match): void
    {
        if ($match->status() !== MatchStatus::Completed || $match->result() === null) { return; }
        $home = $match->homeClubId()->value(); $away = $match->awayClubId()->value(); $homeGoals = $match->result()->homeGoals(); $awayGoals = $match->result()->awayGoals();
        if (!isset($rows[$home], $rows[$away])) { return; }
        $rows[$home]['played']++; $rows[$away]['played']++; $rows[$home]['goals_for'] += $homeGoals; $rows[$home]['goals_against'] += $awayGoals; $rows[$away]['goals_for'] += $awayGoals; $rows[$away]['goals_against'] += $homeGoals;
        if ($homeGoals === $awayGoals) { $rows[$home]['draws']++; $rows[$away]['draws']++; $rows[$home]['points']++; $rows[$away]['points']++; return; }
        $winner = $homeGoals > $awayGoals ? $home : $away; $loser = $winner === $home ? $away : $home; $rows[$winner]['wins']++; $rows[$winner]['points'] += 3; $rows[$loser]['losses']++;
    }
}
