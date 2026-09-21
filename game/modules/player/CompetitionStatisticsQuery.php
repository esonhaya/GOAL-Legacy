<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Match\PlayerMatchRatingService;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Persistence\PlayerCompetitionStatisticsRepository;

/**
 * Reads one competition/Season from the two existing fidelity boundaries.
 *
 * Controlled Players retain completed Match rows; World-fidelity Players
 * retain compact competition aggregates. A detailed Player/Club key wins over
 * its compact counterpart so the two representations cannot be double-counted.
 */
final class CompetitionStatisticsQuery
{
    /** @return list<array<string, int|float|string|null>> */
    public function forCompetitionSeason(DatabaseInterface $database, string $competitionId, SeasonId|string $seasonId): array
    {
        $season = $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId);
        $result = [];
        $detailedKeys = [];
        $ratings = new PlayerMatchRatingService();
        $detailedEvidence = (new PlayerMatchStatRepository($database, false))->completedCompetitionRatingEvidence($season, $competitionId);
        $playerIds = [];
        foreach ($detailedEvidence as $evidence) {
            $playerIds[(string) $evidence['player_id']] = true;
        }
        $compact = (new PlayerCompetitionStatisticsRepository($database, false))->byCompetitionSeason($competitionId, $season);
        foreach ($compact as $row) {
            $playerIds[(string) $row['player_id']] = true;
        }
        $players = [];
        if ($playerIds !== []) {
            foreach ((new PlayerRepository($database))->byIds(array_keys($playerIds)) as $player) {
                $players[$player->id()->value()] = $player->primaryPosition()->value;
            }
        }

        foreach ($detailedEvidence as $evidence) {
            $playerId = (string) $evidence['player_id'];
            $clubId = (string) $evidence['club_id'];
            $key = $playerId . '|' . $clubId;
            $position = $players[$playerId] ?? (string) $evidence['position'];
            $result[$key] ??= $this->emptyAggregate($playerId, $clubId, $position);
            $stat = $evidence['stat'];
            ++$result[$key]['appearances'];
            $result[$key]['starts'] += $stat->started() ? 1 : 0;
            $result[$key]['minutes'] += $stat->minutes();
            foreach ([
                'goals' => 'goals',
                'assists' => 'assists',
                'shots' => 'shots',
                'shots_on_target' => 'shotsOnTarget',
                'saves' => 'saves',
                'clean_sheets' => 'cleanSheets',
                'tackles' => 'tackles',
                'interceptions' => 'interceptions',
                'blocks' => 'blocks',
                'passes_attempted' => 'passesAttempted',
                'passes_completed' => 'passesCompleted',
                'fouls_committed' => 'foulsCommitted',
                'yellow_cards' => 'yellowCards',
                'red_cards' => 'redCards',
            ] as $field => $method) {
                $result[$key][$field] += $stat->{$method}();
            }
            $rating = $ratings->rate($stat, PlayerPosition::from($position));
            if ($rating !== null) {
                ++$result[$key]['rated_appearances'];
                $result[$key]['rating_total'] += $rating;
            }
            $detailedKeys[$key] = true;
        }

        foreach ($compact as $row) {
            $key = (string) $row['player_id'] . '|' . (string) $row['club_id'];
            if (isset($detailedKeys[$key])) {
                continue;
            }
            $row['position'] = $players[(string) $row['player_id']] ?? null;
            $result[$key] = $row;
        }

        foreach ($result as &$row) {
            $rated = (int) ($row['rated_appearances'] ?? 0);
            $row['average_match_rating'] = $rated === 0 ? null : round((float) ($row['rating_total'] ?? 0) / $rated, 2);
        }
        unset($row);
        ksort($result, SORT_STRING);

        return array_values($result);
    }

    /** @return array<string, int|float|string|null> */
    private function emptyAggregate(string $playerId, string $clubId, string $position): array
    {
        return [
            'player_id' => $playerId,
            'season_id' => null,
            'competition_id' => null,
            'club_id' => $clubId,
            'position' => $position,
            'appearances' => 0,
            'starts' => 0,
            'minutes' => 0,
            'goals' => 0,
            'assists' => 0,
            'shots' => 0,
            'shots_on_target' => 0,
            'saves' => 0,
            'clean_sheets' => 0,
            'tackles' => 0,
            'interceptions' => 0,
            'blocks' => 0,
            'passes_attempted' => 0,
            'passes_completed' => 0,
            'fouls_committed' => 0,
            'yellow_cards' => 0,
            'red_cards' => 0,
            'rated_appearances' => 0,
            'rating_total' => 0.0,
            'average_match_rating' => null,
        ];
    }
}
