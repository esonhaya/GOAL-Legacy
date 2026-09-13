<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\World\Domain\SeasonId;

final class PlayerCareerStatisticsService
{
    /** @return array{appearances:int, starts:int, minutes:int, goals:int} */
    public function career(DatabaseInterface $database, PlayerId|string $playerId): array
    {
        return $this->aggregate($database, $playerId);
    }

    /** @return array{appearances:int, starts:int, minutes:int, goals:int} */
    public function season(DatabaseInterface $database, PlayerId|string $playerId, SeasonId|string $seasonId): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $season = $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId);
        $matches = new MatchRepository($database);
        $stats = new PlayerMatchStatRepository($database);
        $result = ['appearances' => 0, 'starts' => 0, 'minutes' => 0, 'goals' => 0];
        foreach ($stats->byPlayer($id) as $stat) {
            $match = $matches->get($stat->matchId());
            if ($match->seasonId()->value() !== $season->value() || !$stat->appeared()) {
                continue;
            }
            ++$result['appearances'];
            $result['starts'] += $stat->started() ? 1 : 0;
            $result['minutes'] += $stat->minutes();
            $result['goals'] += $stat->goals();
        }

        return $result;
    }

    /** @return array{appearances:int, starts:int, minutes:int, goals:int} */
    private function aggregate(DatabaseInterface $database, PlayerId|string $playerId): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $result = ['appearances' => 0, 'starts' => 0, 'minutes' => 0, 'goals' => 0];
        foreach ((new PlayerMatchStatRepository($database))->byPlayer($id) as $stat) {
            if (!$stat->appeared()) {
                continue;
            }
            ++$result['appearances'];
            $result['starts'] += $stat->started() ? 1 : 0;
            $result['minutes'] += $stat->minutes();
            $result['goals'] += $stat->goals();
        }

        return $result;
    }
}
