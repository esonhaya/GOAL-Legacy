<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Match\PlayerMatchRatingService;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
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

    /**
     * The presentation read model needs the complete factual season line.
     * Keep the original four-field method stable for older callers while
     * exposing the same persisted Match evidence through this richer view.
     *
     * @return array<string, int|float|null>
     */
    public function seasonDetailed(DatabaseInterface $database, PlayerId|string $playerId, SeasonId|string $seasonId): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $season = $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId);

        return $this->aggregateDetailed($database, $id, $season);
    }

    /**
     * @return array<string, int|float|null>
     */
    public function careerDetailed(DatabaseInterface $database, PlayerId|string $playerId): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $stats = new PlayerMatchStatRepository($database);
        $players = new PlayerRepository($database);
        $ratings = new PlayerMatchRatingService();
        $result = $this->emptyDetailed();
        $ratingTotal = 0.0;

        foreach ($stats->byPlayer($id) as $stat) {
            if (!$stat->appeared()) {
                continue;
            }
            ++$result['appearances'];
            $result['starts'] += $stat->started() ? 1 : 0;
            $result['minutes'] += $stat->minutes();
            $this->addStatEvidence($result, $stat);
            $rating = $ratings->rate($stat, $players->get($id)->primaryPosition());
            if ($rating !== null) {
                $ratingTotal += $rating;
                ++$result['rated_appearances'];
            }
        }
        $result['average_match_rating'] = $result['rated_appearances'] === 0
            ? null
            : round($ratingTotal / $result['rated_appearances'], 2);

        return $result;
    }

    /** @return array<string, int|float|null> */
    private function aggregateDetailed(DatabaseInterface $database, PlayerId $playerId, SeasonId $seasonId): array
    {
        $matches = new MatchRepository($database);
        $stats = new PlayerMatchStatRepository($database);
        $players = new PlayerRepository($database);
        $ratings = new PlayerMatchRatingService();
        $result = $this->emptyDetailed();
        $ratingTotal = 0.0;
        $position = $players->get($playerId)->primaryPosition();

        foreach ($stats->byPlayer($playerId) as $stat) {
            $match = $matches->get($stat->matchId());
            if ($match->seasonId()->value() !== $seasonId->value() || !$stat->appeared()) {
                continue;
            }
            ++$result['appearances'];
            $result['starts'] += $stat->started() ? 1 : 0;
            $result['minutes'] += $stat->minutes();
            $this->addStatEvidence($result, $stat);
            $rating = $ratings->rate($stat, $position);
            if ($rating !== null) {
                $ratingTotal += $rating;
                ++$result['rated_appearances'];
            }
        }
        $result['average_match_rating'] = $result['rated_appearances'] === 0
            ? null
            : round($ratingTotal / $result['rated_appearances'], 2);

        return $result;
    }

    /** @return array<string, int|float|null> */
    private function emptyDetailed(): array
    {
        return [
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
            'average_match_rating' => null,
        ];
    }

    /** @param array<string, int|float|null> $result */
    private function addStatEvidence(array &$result, \Goal\Legacy\Modules\Match\Domain\PlayerMatchStat $stat): void
    {
        foreach ([
            'goals' => $stat->goals(),
            'assists' => $stat->assists(),
            'shots' => $stat->shots(),
            'shots_on_target' => $stat->shotsOnTarget(),
            'saves' => $stat->saves(),
            'clean_sheets' => $stat->cleanSheets(),
            'tackles' => $stat->tackles(),
            'interceptions' => $stat->interceptions(),
            'blocks' => $stat->blocks(),
            'passes_attempted' => $stat->passesAttempted(),
            'passes_completed' => $stat->passesCompleted(),
            'fouls_committed' => $stat->foulsCommitted(),
            'yellow_cards' => $stat->yellowCards(),
            'red_cards' => $stat->redCards(),
        ] as $key => $value) {
            $result[$key] += $value;
        }
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
