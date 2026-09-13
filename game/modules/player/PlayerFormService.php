<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\Domain\PlayerId;

final class PlayerFormService
{
    /** @return array{appearances:int, goals:int, average_score:int, last_score:int|null} */
    public function recent(DatabaseInterface $database, PlayerId|string $playerId, int $limit = 5): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $matches = new MatchRepository($database);
        $stats = new PlayerMatchStatRepository($database)->byPlayer($id);
        $evaluated = [];
        $evaluator = new PlayerPerformanceEvaluator();
        foreach (array_reverse($stats) as $stat) {
            if (!$stat->appeared()) { continue; }
            $match = $matches->get($stat->matchId());
            $evaluation = $evaluator->evaluate($stat, $match);
            $evaluated[] = ['date' => $match->scheduledDate()->toIsoString(), 'goals' => $stat->goals(), 'score' => $evaluation->score()];
            if (count($evaluated) >= max(1, $limit)) { break; }
        }
        $scores = array_column($evaluated, 'score');

        return ['appearances' => count($evaluated), 'goals' => array_sum(array_column($evaluated, 'goals')), 'average_score' => $scores === [] ? 0 : (int) round(array_sum($scores) / count($scores)), 'last_score' => $scores[0] ?? null];
    }
}
