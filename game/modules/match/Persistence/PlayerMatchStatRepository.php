<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use PDO;

final class PlayerMatchStatRepository
{
    private const TABLE = 'match_player_stats';
    public function __construct(private readonly DatabaseInterface $database)
    { SchemaInitializationGuard::run($this->database->connection(), self::class, function (): void { $this->database->connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (match_id TEXT NOT NULL, player_id TEXT NOT NULL, club_id TEXT NOT NULL, appeared INTEGER NOT NULL, started INTEGER NOT NULL, minutes INTEGER NOT NULL, goals INTEGER NOT NULL, PRIMARY KEY (match_id, player_id))'); $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_match_stats_player ON ' . self::TABLE . ' (player_id, match_id)'); $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_match_stats_club ON ' . self::TABLE . ' (club_id, match_id)'); }); }
    /** @param list<PlayerMatchStat> $stats */
    public function replaceForMatch(array $stats): void { $this->database->transaction(function () use ($stats): void { $this->replaceForMatchInTransaction($stats); }); }
    /** @param list<PlayerMatchStat> $stats */
    public function replaceForMatchInTransaction(array $stats): void { if ($stats === []) { return; } $matchId = $stats[0]->matchId()->value(); $this->database->connection()->prepare('DELETE FROM ' . self::TABLE . ' WHERE match_id = :match_id')->execute(['match_id' => $matchId]); $statement = $this->database->connection()->prepare('INSERT INTO ' . self::TABLE . ' (match_id, player_id, club_id, appeared, started, minutes, goals) VALUES (:match_id, :player_id, :club_id, :appeared, :started, :minutes, :goals)'); foreach ($stats as $stat) { $statement->execute($stat->toArray()); } }
    /** @return list<PlayerMatchStat> */
    public function byMatch(string|MatchId $id): array { $matchId = $id instanceof MatchId ? $id : new MatchId($id); $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE match_id = :match_id ORDER BY club_id ASC, player_id ASC'); $statement->execute(['match_id' => $matchId->value()]); return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC)); }
    /** @return list<PlayerMatchStat> */
    public function byPlayer(string|PlayerId $id): array { $playerId = $id instanceof PlayerId ? $id : new PlayerId($id); $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE player_id = :player_id ORDER BY match_id ASC'); $statement->execute(['player_id' => $playerId->value()]); return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC)); }
    /** @return array<string, array{club_id:string,appearances:int,starts:int,minutes:int,goals:int}> */
    public function seasonAggregates(SeasonId $seasonId): array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT stats.player_id, stats.club_id, SUM(stats.appeared) AS appearances, SUM(stats.started) AS starts, SUM(stats.minutes) AS minutes, SUM(stats.goals) AS goals '
            . 'FROM ' . self::TABLE . ' stats JOIN match_records matches ON matches.id = stats.match_id '
            . 'WHERE matches.season_id = :season_id AND matches.status = :status AND stats.appeared = 1 '
            . 'GROUP BY stats.player_id, stats.club_id'
        );
        $statement->execute(['season_id' => $seasonId->value(), 'status' => 'completed']);
        $aggregates = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $playerId = (string) $row['player_id'];
            $aggregates[$playerId] = [
                'club_id' => (string) $row['club_id'],
                'appearances' => (int) $row['appearances'],
                'starts' => (int) $row['starts'],
                'minutes' => (int) $row['minutes'],
                'goals' => (int) $row['goals'],
            ];
        }

        return $aggregates;
    }

    /** @return array<string, array{club_id:string,appearances:int,starts:int,minutes:int,goals:int}> */
    public function seasonAggregatesForPlayer(PlayerId $playerId, SeasonId $seasonId): array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT stats.club_id, SUM(stats.appeared) AS appearances, SUM(stats.started) AS starts, SUM(stats.minutes) AS minutes, SUM(stats.goals) AS goals '
            . 'FROM ' . self::TABLE . ' stats JOIN match_records matches ON matches.id = stats.match_id '
            . 'WHERE stats.player_id = :player_id AND matches.season_id = :season_id AND matches.status = :status AND stats.appeared = 1 '
            . 'GROUP BY stats.club_id'
        );
        $statement->execute(['player_id' => $playerId->value(), 'season_id' => $seasonId->value(), 'status' => 'completed']);
        $aggregates = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $clubId = (string) $row['club_id'];
            $aggregates[$clubId] = [
                'club_id' => $clubId,
                'appearances' => (int) $row['appearances'],
                'starts' => (int) $row['starts'],
                'minutes' => (int) $row['minutes'],
                'goals' => (int) $row['goals'],
            ];
        }

        return $aggregates;
    }
    /** @param list<array<string, mixed>> $rows @return list<PlayerMatchStat> */
    private function hydrateRows(array $rows): array { return array_map(static fn (array $row): PlayerMatchStat => new PlayerMatchStat(new MatchId((string) $row['match_id']), new PlayerId((string) $row['player_id']), new ClubId((string) $row['club_id']), (bool) $row['appeared'], (bool) $row['started'], (int) $row['minutes'], (int) $row['goals']), $rows); }
}
