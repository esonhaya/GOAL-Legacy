<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Match\PlayerMatchRatingService;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;

final class PlayerFormService
{
    /** @return array{appearances:int,goals:int,average_score:int,last_score:int|null,average_match_rating:float|null,rated_appearances:int,classification:string} */
    public function recent(DatabaseInterface $database, PlayerId|string $playerId, int $limit = 5): array
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
