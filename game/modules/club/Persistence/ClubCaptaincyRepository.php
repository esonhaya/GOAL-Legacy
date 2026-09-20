<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Club\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use PDO;

/** Compact Club/Season captain appointments. Football evidence remains canonical. */
final class ClubCaptaincyRepository
{
    public const TABLE = 'club_captaincy_appointments';

    public function __construct(private readonly DatabaseInterface $database, bool $initialize = true)
    {
        if (!$initialize) {
            return;
        }
        SchemaInitializationGuard::run($this->database->connection(), self::class, function (): void {
            $this->database->connection()->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                . 'club_id TEXT NOT NULL, season_id TEXT NOT NULL, '
                . 'captain_player_id TEXT NULL, vice_captain_player_id TEXT NULL, '
                . 'appointed_date TEXT NOT NULL, '
                . 'PRIMARY KEY (club_id, season_id))'
            );
            $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_captaincy_captain ON ' . self::TABLE . ' (captain_player_id, season_id)');
            $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_captaincy_vice ON ' . self::TABLE . ' (vice_captain_player_id, season_id)');
        });
    }

    public function tableExists(): bool
    {
        $statement = $this->database->connection()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table");
        $statement->execute(['table' => self::TABLE]);

        return $statement->fetchColumn() !== false;
    }

    /** @return array<string, mixed>|null */
    public function get(string $clubId, string $seasonId): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE club_id = :club_id AND season_id = :season_id');
        $statement->execute(['club_id' => $clubId, 'season_id' => $seasonId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function byPlayer(string $playerId): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        $statement = $this->database->connection()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE captain_player_id = :player_id OR vice_captain_player_id = :player_id ORDER BY appointed_date ASC, club_id ASC, season_id ASC'
        );
        $statement->execute(['player_id' => $playerId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array{club_id:string,season_id:string,captain_player_id:?string,vice_captain_player_id:?string,appointed_date:string} $appointment */
    public function save(array $appointment): void
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO ' . self::TABLE . ' (club_id, season_id, captain_player_id, vice_captain_player_id, appointed_date) '
            . 'VALUES (:club_id, :season_id, :captain_player_id, :vice_captain_player_id, :appointed_date) '
            . 'ON CONFLICT(club_id, season_id) DO UPDATE SET captain_player_id = excluded.captain_player_id, vice_captain_player_id = excluded.vice_captain_player_id, appointed_date = excluded.appointed_date'
        );
        $statement->execute($appointment);
    }
}
