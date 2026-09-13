<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use PDO;

final class PlayerMatchStatRepository
{
    private const TABLE = 'match_player_stats';
    public function __construct(private readonly DatabaseInterface $database)
    { $this->database->connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (match_id TEXT NOT NULL, player_id TEXT NOT NULL, club_id TEXT NOT NULL, appeared INTEGER NOT NULL, started INTEGER NOT NULL, minutes INTEGER NOT NULL, goals INTEGER NOT NULL, PRIMARY KEY (match_id, player_id))'); $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_match_stats_player ON ' . self::TABLE . ' (player_id, match_id)'); $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_match_stats_club ON ' . self::TABLE . ' (club_id, match_id)'); }
    /** @param list<PlayerMatchStat> $stats */
    public function replaceForMatch(array $stats): void { $this->database->transaction(function () use ($stats): void { $this->replaceForMatchInTransaction($stats); }); }
    /** @param list<PlayerMatchStat> $stats */
    public function replaceForMatchInTransaction(array $stats): void { if ($stats === []) { return; } $matchId = $stats[0]->matchId()->value(); $this->database->connection()->prepare('DELETE FROM ' . self::TABLE . ' WHERE match_id = :match_id')->execute(['match_id' => $matchId]); $statement = $this->database->connection()->prepare('INSERT INTO ' . self::TABLE . ' (match_id, player_id, club_id, appeared, started, minutes, goals) VALUES (:match_id, :player_id, :club_id, :appeared, :started, :minutes, :goals)'); foreach ($stats as $stat) { $statement->execute($stat->toArray()); } }
    /** @return list<PlayerMatchStat> */
    public function byMatch(string|MatchId $id): array { $matchId = $id instanceof MatchId ? $id : new MatchId($id); $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE match_id = :match_id ORDER BY club_id ASC, player_id ASC'); $statement->execute(['match_id' => $matchId->value()]); return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC)); }
    /** @return list<PlayerMatchStat> */
    public function byPlayer(string|PlayerId $id): array { $playerId = $id instanceof PlayerId ? $id : new PlayerId($id); $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE player_id = :player_id ORDER BY match_id ASC'); $statement->execute(['player_id' => $playerId->value()]); return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC)); }
    /** @param list<array<string, mixed>> $rows @return list<PlayerMatchStat> */
    private function hydrateRows(array $rows): array { return array_map(static fn (array $row): PlayerMatchStat => new PlayerMatchStat(new MatchId((string) $row['match_id']), new PlayerId((string) $row['player_id']), new ClubId((string) $row['club_id']), (bool) $row['appeared'], (bool) $row['started'], (int) $row['minutes'], (int) $row['goals']), $rows); }
}
