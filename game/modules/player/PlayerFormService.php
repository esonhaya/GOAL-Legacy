<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Match\PlayerMatchRatingService;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerEvaluationRepository;
use Goal\Legacy\Modules\Club\Domain\ClubId;

final class PlayerFormService
{
    /** @return array{appearances:int,goals:int,average_score:int,last_score:int|null,average_match_rating:float|null,rated_appearances:int,classification:string} */
    public function recent(DatabaseInterface $database, PlayerId|string $playerId, int $limit = 5, ?string $clubId = null): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        new PlayerRepository($database);
        $evidence = (new PlayerMatchStatRepository($database))->recentCompletedRatingEvidence($id, max(1, $limit));
        $ratings = new PlayerMatchRatingService();
        $chronological = array_reverse($evidence);
        $weighted = 0.0;
        $weights = 0.0;
        $goals = 0;
        $last = null;
        foreach ($chronological as $index => $row) {
            $rating = $ratings->rate($row['stat'], PlayerPosition::from($row['position']));
            if ($rating === null) { continue; }
            $weight = 1.0 + (($index / max(1, count($chronological) - 1)) * 0.3);
            $weighted += $rating * $weight;
            $weights += $weight;
            $goals += $row['stat']->goals();
            $last = $rating;
        }
        $average = $weights === 0.0 ? null : $weighted / $weights;
        $ratedAppearances = $weights === 0.0 ? 0 : count($chronological);
        if ($ratedAppearances === 0 && $clubId !== null) {
            $compact = (new CareerEvaluationRepository($database))->recentForPlayer($id, new ClubId($clubId));
            if ($compact !== []) {
                $scores = array_map(static fn (array $row): int => (int) ($row['evaluation_score'] ?? 0), $compact);
                $averageScore = (int) round(array_sum($scores) / count($scores));
                $latestScore = $scores[0] ?? null;

                return [
                    'appearances' => count($scores),
                    'goals' => 0,
                    'average_score' => $averageScore,
                    'last_score' => $latestScore,
                    'average_match_rating' => null,
                    'rated_appearances' => 0,
                    'classification' => count($scores) < 2 ? 'insufficient_evidence' : match (true) {
                        $averageScore >= 85 => 'excellent',
                        $averageScore >= 75 => 'good',
                        $averageScore >= 60 => 'neutral',
                        $averageScore >= 45 => 'poor',
                        default => 'very_poor',
                    },
                    'source' => 'compact_evaluation',
                ];
            }
        }
        $classification = $ratedAppearances < 2 ? 'insufficient_evidence' : match (true) {
            $average >= 7.5 => 'excellent',
            $average >= 6.5 => 'good',
            $average >= 5.5 => 'neutral',
            $average >= 4.5 => 'poor',
            default => 'very_poor',
        };

        return ['appearances' => $ratedAppearances, 'goals' => $goals, 'average_score' => $average === null ? 0 : (int) round($average * 10), 'last_score' => $last === null ? null : (int) round($last * 10), 'average_match_rating' => $average === null ? null : round($average, 2), 'rated_appearances' => $ratedAppearances, 'classification' => $classification];
    }
}
