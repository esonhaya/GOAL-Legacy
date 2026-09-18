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
use Goal\Legacy\Modules\World\Persistence\PlayerSeasonStatisticsRepository;

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
        $detailedKeys = [];
        foreach ($stats->byPlayer($id) as $stat) {
            $match = $matches->get($stat->matchId());
            if ($match->seasonId()->value() !== $season->value() || !$stat->appeared() || $this->isInternational($database, $match->competitionId()->value())) {
                continue;
            }
            ++$result['appearances'];
            $result['starts'] += $stat->started() ? 1 : 0;
            $result['minutes'] += $stat->minutes();
            $result['goals'] += $stat->goals();
            $detailedKeys[$season->value() . ':' . $stat->clubId()->value()] = true;
        }
        foreach ((new PlayerSeasonStatisticsRepository($database, false))->byPlayerSeason($id->value(), $season) as $row) {
            if (isset($detailedKeys[$this->compactKey($row)])) { continue; }
            $result['appearances'] += (int) ($row['appearances'] ?? 0);
            $result['starts'] += (int) ($row['starts'] ?? 0);
            $result['minutes'] += (int) ($row['minutes'] ?? 0);
            $result['goals'] += (int) ($row['goals'] ?? 0);
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
     * Detailed controlled-Player evidence for one competition. Compact world
     * aggregates intentionally do not pretend to contain per-competition
     * evidence; callers receive an empty line when no detailed rows exist.
     * @return array<string, int|float|null>
     */
    public function seasonCompetitionDetailed(DatabaseInterface $database, PlayerId|string $playerId, SeasonId|string $seasonId, string $competitionId): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $season = $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId);
        $matches = new MatchRepository($database);
        $stats = new PlayerMatchStatRepository($database);
        $players = new PlayerRepository($database);
        $ratings = new PlayerMatchRatingService();
        $result = $this->emptyDetailed();
        $ratingTotal = 0.0;
        $position = $players->get($id)->primaryPosition();
        foreach ($stats->byPlayer($id) as $stat) {
            $match = $matches->get($stat->matchId());
            if ($match->seasonId()->value() !== $season->value() || $match->competitionId()->value() !== $competitionId || !$stat->appeared()) {
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

    /**
     * @return array<string, int|float|null>
     */
    public function careerDetailed(DatabaseInterface $database, PlayerId|string $playerId): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $stats = new PlayerMatchStatRepository($database);
        $players = new PlayerRepository($database);
        $ratings = new PlayerMatchRatingService();
        $matches = new MatchRepository($database);
        $result = $this->emptyDetailed();
        $ratingTotal = 0.0;
        $detailedKeys = [];

        foreach ($stats->byPlayer($id) as $stat) {
            $match = $matches->get($stat->matchId());
            if (!$stat->appeared() || $this->isInternational($database, $match->competitionId()->value())) {
                continue;
            }
            ++$result['appearances'];
            $result['starts'] += $stat->started() ? 1 : 0;
            $result['minutes'] += $stat->minutes();
            $this->addStatEvidence($result, $stat);
            $detailedKeys[$match->seasonId()->value() . ':' . $stat->clubId()->value()] = true;
            $rating = $ratings->rate($stat, $players->get($id)->primaryPosition());
            if ($rating !== null) {
                $ratingTotal += $rating;
                ++$result['rated_appearances'];
            }
        }
        foreach ((new PlayerSeasonStatisticsRepository($database, false))->byPlayer($id) as $row) {
            if (isset($detailedKeys[$this->compactKey($row)])) { continue; }
            $this->addCompactRow($result, $row, $ratingTotal);
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
        $detailedKeys = [];

        foreach ($stats->byPlayer($playerId) as $stat) {
            $match = $matches->get($stat->matchId());
            if ($match->seasonId()->value() !== $seasonId->value() || !$stat->appeared() || $this->isInternational($database, $match->competitionId()->value())) {
                continue;
            }
            ++$result['appearances'];
            $result['starts'] += $stat->started() ? 1 : 0;
            $result['minutes'] += $stat->minutes();
            $this->addStatEvidence($result, $stat);
            $detailedKeys[$match->seasonId()->value() . ':' . $stat->clubId()->value()] = true;
            $rating = $ratings->rate($stat, $position);
            if ($rating !== null) {
                $ratingTotal += $rating;
                ++$result['rated_appearances'];
            }
        }
        foreach ((new PlayerSeasonStatisticsRepository($database, false))->byPlayerSeason($playerId->value(), $seasonId) as $row) {
            if (isset($detailedKeys[$this->compactKey($row)])) { continue; }
            $this->addCompactRow($result, $row, $ratingTotal);
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

    /** @param list<array<string, int|float|string>> $rows @return array<string, int|float|null> */
    private function compactRowsToDetailed(array $rows): array
    {
        $result = $this->emptyDetailed();
        $ratingTotal = 0.0;
        foreach ($rows as $row) {
            foreach (array_keys($result) as $key) {
                if (in_array($key, ['average_match_rating'], true)) {
                    continue;
                }
                if ($key === 'rated_appearances') {
                    $result[$key] += (int) ($row[$key] ?? 0);
                    continue;
                }
                $result[$key] += (int) ($row[$key] ?? 0);
            }
            $ratingTotal += (float) ($row['rating_total'] ?? 0.0);
            $result['average_match_rating'] = ($result['rated_appearances'] ?? 0) > 0
                ? round($ratingTotal / (int) $result['rated_appearances'], 2)
                : null;
        }

        return $result;
    }

    /** @param array<string, int|float|null> $result @param array<string, int|float|string> $row */
    private function addCompactRow(array &$result, array $row, float &$ratingTotal): void
    {
        foreach (array_keys($result) as $key) {
            if ($key === 'average_match_rating') { continue; }
            $result[$key] += (int) ($row[$key] ?? 0);
        }
        $ratingTotal += (float) ($row['rating_total'] ?? 0.0);
    }

    /** @param array<string, int|float|string> $row */
    private function compactKey(array $row): string
    {
        return (string) $row['season_id'] . ':' . (string) $row['club_id'];
    }

    private function isInternational(DatabaseInterface $database, string $competitionId): bool
    {
        $statement = $database->connection()->prepare('SELECT type FROM competition_records WHERE id = :id');
        $statement->execute(['id' => $competitionId]);

        return $statement->fetchColumn() === 'international';
    }

    /** @return array{appearances:int, starts:int, minutes:int, goals:int} */
    private function aggregate(DatabaseInterface $database, PlayerId|string $playerId): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $result = ['appearances' => 0, 'starts' => 0, 'minutes' => 0, 'goals' => 0];
        $matches = new MatchRepository($database);
        $detailedKeys = [];
        foreach ((new PlayerMatchStatRepository($database))->byPlayer($id) as $stat) {
            $match = $matches->get($stat->matchId());
            if (!$stat->appeared() || $this->isInternational($database, $match->competitionId()->value())) {
                continue;
            }
            ++$result['appearances'];
            $result['starts'] += $stat->started() ? 1 : 0;
            $result['minutes'] += $stat->minutes();
            $result['goals'] += $stat->goals();
            $detailedKeys[$match->seasonId()->value() . ':' . $stat->clubId()->value()] = true;
        }
        foreach ((new PlayerSeasonStatisticsRepository($database, false))->byPlayer($id) as $row) {
            if (isset($detailedKeys[$this->compactKey($row)])) { continue; }
            $result['appearances'] += (int) ($row['appearances'] ?? 0);
            $result['starts'] += (int) ($row['starts'] ?? 0);
            $result['minutes'] += (int) ($row['minutes'] ?? 0);
            $result['goals'] += (int) ($row['goals'] ?? 0);
        }

        return $result;
    }
}
