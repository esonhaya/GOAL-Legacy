<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Persistence\ClubSquadRepository;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Match\PlayerMatchRatingService;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\Domain\SeasonPerformanceAssessment;
use Goal\Legacy\Modules\World\Domain\SeasonId;

/** Derives the canonical player-season assessment from completed Match evidence. */
final class PlayerSeasonPerformanceService
{
    /** @return array<string, SeasonPerformanceAssessment> */
    public function assessMany(DatabaseInterface $database, SeasonId|string $seasonId): array
    {
        $season = $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId);
        new PlayerRepository($database);
        $expected = (new MatchRepository($database))->completedCountsByClub($season);
        $aggregates = [];
        $clubsByPlayer = [];
        $ratings = new PlayerMatchRatingService();
        (new PlayerMatchStatRepository($database))->eachCompletedSeasonRatingEvidence($season, function (array $row) use (&$aggregates, &$clubsByPlayer, $ratings): void {
            $playerId = $row['player_id'];
            if (!isset($aggregates[$playerId])) {
                $aggregates[$playerId] = $this->emptyAggregate(null, 0);
                $clubsByPlayer[$playerId] = [];
            }
            $stat = $row['stat'];
            $clubsByPlayer[$playerId][$row['club_id']] = true;
            ++$aggregates[$playerId]['appearances'];
            $aggregates[$playerId]['starts'] += $stat->started() ? 1 : 0;
            $aggregates[$playerId]['minutes'] += $stat->minutes();
            $aggregates[$playerId]['goals'] += $stat->goals();
            $rating = $ratings->rate($stat, PlayerPosition::from($row['position']));
            if ($rating !== null) {
                $aggregates[$playerId]['rating_total'] += $rating;
                ++$aggregates[$playerId]['rated_appearances'];
            }
        });
        $result = [];
        foreach ($aggregates as $playerId => $aggregate) {
            $aggregate['expected_matches'] = max(array_map(static fn (string $club): int => $expected[$club] ?? 0, array_keys($clubsByPlayer[$playerId])) ?: [0]);
            $aggregate['average_match_rating'] = $aggregate['rated_appearances'] === 0 ? null : $aggregate['rating_total'] / $aggregate['rated_appearances'];
            $result[$playerId] = $this->build($aggregate);
        }

        return $result;
    }

    public function assess(DatabaseInterface $database, PlayerId|string $playerId, SeasonId|string $seasonId, ?ClubId $clubId = null): SeasonPerformanceAssessment
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $season = $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId);
        new PlayerRepository($database);
        $expected = (new MatchRepository($database))->completedCountsByClub($season);
        $evidence = (new PlayerMatchStatRepository($database))->completedSeasonRatingEvidence($season, $id);
        if ($evidence === []) {
            $membership = (new ClubSquadRepository($database))->byPlayer($id, $season)[0] ?? null;
            $club = $clubId?->value() ?? $membership?->clubId()->value();
            return $this->build($this->emptyAggregate($club, $club === null ? 0 : ($expected[$club] ?? 0)));
        }

        // Club context remains useful to callers, but the assessment itself is
        // player + season scoped so a transfer cannot discard earlier Matches.
        return $this->build($this->aggregate($evidence, $expected));
    }

    /** @param list<array{player_id:string,club_id:string,position:string,stat:\Goal\Legacy\Modules\Match\Domain\PlayerMatchStat}> $evidence @param array<string,int> $expectedByClub @return array<string,int|float|null> */
    private function aggregate(array $evidence, array $expectedByClub): array
    {
        $ratings = new PlayerMatchRatingService();
        $clubs = [];
        $aggregate = $this->emptyAggregate(null, 0);
        foreach ($evidence as $row) {
            $stat = $row['stat'];
            $clubs[$row['club_id']] = true;
            ++$aggregate['appearances'];
            $aggregate['starts'] += $stat->started() ? 1 : 0;
            $aggregate['minutes'] += $stat->minutes();
            $aggregate['goals'] += $stat->goals();
            $rating = $ratings->rate($stat, PlayerPosition::from($row['position']));
            if ($rating !== null) { $aggregate['rating_total'] += $rating; ++$aggregate['rated_appearances']; }
        }
        $aggregate['expected_matches'] = max(array_map(static fn (string $club): int => $expectedByClub[$club] ?? 0, array_keys($clubs)) ?: [0]);
        $aggregate['average_match_rating'] = $aggregate['rated_appearances'] === 0 ? null : $aggregate['rating_total'] / $aggregate['rated_appearances'];

        return $aggregate;
    }

    /** @return array<string,int|float|null> */
    private function emptyAggregate(?string $clubId, int $expectedMatches): array
    {
        return ['club_id' => $clubId ?? '', 'appearances' => 0, 'starts' => 0, 'minutes' => 0, 'goals' => 0, 'expected_matches' => $expectedMatches, 'rated_appearances' => 0, 'rating_total' => 0.0, 'average_match_rating' => null];
    }

    /** @param array<string,int|float|null> $aggregate */
    private function build(array $aggregate): SeasonPerformanceAssessment
    {
        $appearances = (int) $aggregate['appearances'];
        $starts = (int) $aggregate['starts'];
        $minutes = (int) $aggregate['minutes'];
        $goals = (int) $aggregate['goals'];
        $expectedMatches = (int) $aggregate['expected_matches'];
        $ratedAppearances = (int) $aggregate['rated_appearances'];
        $average = $aggregate['average_match_rating'];
        $appearanceShare = $expectedMatches > 0 ? min(1.0, $appearances / $expectedMatches) : 0.0;
        $startShare = $expectedMatches > 0 ? min(1.0, $starts / $expectedMatches) : 0.0;
        $minutesShare = $expectedMatches > 0 ? min(1.0, $minutes / ($expectedMatches * 90)) : 0.0;
        $score = $average === null ? 0 : (int) round(min(100, ($average * 7.5) + ($minutesShare * 20) + ($startShare * 5)));
        $statistics = [
            'appearances' => $appearances,
            'starts' => $starts,
            'minutes' => $minutes,
            'goals' => $goals,
            'expected_matches' => $expectedMatches,
            'rated_appearances' => $ratedAppearances,
            'average_match_rating' => $average === null ? null : round((float) $average, 2),
            'appearance_share' => round($appearanceShare, 4),
            'start_share' => round($startShare, 4),
            'minutes_share' => round($minutesShare, 4),
        ];
        if ($expectedMatches === 0) {
            return new SeasonPerformanceAssessment('insufficient_evidence', 0, $statistics, 'no_completed_club_matches');
        }
        if ($appearances === 0 || $ratedAppearances === 0) {
            return new SeasonPerformanceAssessment('insufficient_evidence', 0, $statistics, 'no_rated_appearances');
        }
        if ($minutes < 30 && (float) $average >= 7.0) {
            return new SeasonPerformanceAssessment('insufficient_evidence', $score, $statistics, 'short_high_quality_appearance');
        }
        if ((float) $average >= 7.8 && $minutesShare >= 0.65 && $startShare >= 0.45) {
            return new SeasonPerformanceAssessment('breakout', $score, $statistics, 'sustained_exceptional_match_ratings');
        }
        if ((float) $average >= 6.8 && $minutesShare >= 0.45 && $startShare >= 0.25) {
            return new SeasonPerformanceAssessment('strong', $score, $statistics, 'sustained_strong_match_ratings');
        }
        if ((float) $average >= 5.8 && ($minutesShare >= 0.20 || $startShare >= 0.10)) {
            return new SeasonPerformanceAssessment('steady', $score, $statistics, 'credible_match_quality_and_participation');
        }
        if ((float) $average < 5.3 && ($minutesShare >= 0.20 || $startShare >= 0.10)) {
            return new SeasonPerformanceAssessment('stagnant', $score, $statistics, 'sustained_weak_match_quality');
        }

        return new SeasonPerformanceAssessment('limited', $score, $statistics, 'limited_quality_or_opportunity');
    }
}
