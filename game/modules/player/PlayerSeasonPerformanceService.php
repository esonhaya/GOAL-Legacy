<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Persistence\ClubSquadRepository;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\SeasonPerformanceAssessment;
use Goal\Legacy\Modules\World\Domain\SeasonId;

/** Derives one immutable Season assessment from authoritative Match statistics. */
final class PlayerSeasonPerformanceService
{
    /** @return array<string, SeasonPerformanceAssessment> */
    public function assessMany(DatabaseInterface $database, SeasonId|string $seasonId): array
    {
        $season = $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId);
        new MatchRepository($database);
        $expected = (new MatchRepository($database))->completedCountsByClub($season);
        $result = [];
        foreach ((new PlayerMatchStatRepository($database))->seasonAggregates($season) as $playerId => $aggregate) {
            $result[$playerId] = $this->build($aggregate, $expected[$aggregate['club_id']] ?? 0);
        }

        return $result;
    }

    public function assess(DatabaseInterface $database, PlayerId|string $playerId, SeasonId|string $seasonId, ?ClubId $clubId = null): SeasonPerformanceAssessment
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $season = $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId);
        new MatchRepository($database);
        $aggregates = (new PlayerMatchStatRepository($database))->seasonAggregatesForPlayer($id, $season);
        $selected = null;
        if ($clubId !== null) {
            $selected = $aggregates[$clubId->value()] ?? null;
        }
        $selected ??= array_values($aggregates)[0] ?? null;
        if ($selected === null) {
            $membership = (new ClubSquadRepository($database))->byPlayer($id, $season)[0] ?? null;
            $club = $clubId?->value() ?? $membership?->clubId()->value();
            $expected = $club === null ? 0 : ((new MatchRepository($database))->completedCountsByClub($season)[$club] ?? 0);
            $selected = ['club_id' => $club ?? '', 'appearances' => 0, 'starts' => 0, 'minutes' => 0, 'goals' => 0];
            return $this->build($selected, $expected);
        }

        $expected = (new MatchRepository($database))->completedCountsByClub($season)[$selected['club_id']] ?? 0;

        return $this->build($selected, $expected);
    }

    /** @param array{club_id:string,appearances:int,starts:int,minutes:int,goals:int} $aggregate */
    private function build(array $aggregate, int $expectedMatches): SeasonPerformanceAssessment
    {
        $appearances = $aggregate['appearances'];
        $starts = $aggregate['starts'];
        $minutes = $aggregate['minutes'];
        $goals = $aggregate['goals'];
        $appearanceShare = $expectedMatches > 0 ? min(1.0, $appearances / $expectedMatches) : 0.0;
        $startShare = $expectedMatches > 0 ? min(1.0, $starts / $expectedMatches) : 0.0;
        $minutesShare = $expectedMatches > 0 ? min(1.0, $minutes / ($expectedMatches * 90)) : 0.0;
        $score = (int) round(($minutesShare * 70) + ($startShare * 20) + min(10, $goals * 2));
        $statistics = [
            'appearances' => $appearances,
            'starts' => $starts,
            'minutes' => $minutes,
            'goals' => $goals,
            'expected_matches' => $expectedMatches,
            'appearance_share' => round($appearanceShare, 4),
            'start_share' => round($startShare, 4),
            'minutes_share' => round($minutesShare, 4),
        ];
        if ($expectedMatches === 0) {
            return new SeasonPerformanceAssessment('insufficient_evidence', 0, $statistics, 'no_completed_club_matches');
        }
        if ($appearances === 0) {
            return new SeasonPerformanceAssessment('stagnant', 0, $statistics, 'no_match_appearances');
        }
        if ($minutesShare >= 0.65 && $startShare >= 0.45 && $score >= 55) {
            return new SeasonPerformanceAssessment('breakout', $score, $statistics, 'high_normalized_participation');
        }
        if ($minutesShare >= 0.45 && $startShare >= 0.25 && $score >= 40) {
            return new SeasonPerformanceAssessment('strong', $score, $statistics, 'strong_normalized_participation');
        }
        if ($minutesShare >= 0.20 || $startShare >= 0.10) {
            return new SeasonPerformanceAssessment('steady', $score, $statistics, 'regular_match_opportunity');
        }

        return new SeasonPerformanceAssessment('limited', $score, $statistics, 'limited_match_opportunity');
    }
}
