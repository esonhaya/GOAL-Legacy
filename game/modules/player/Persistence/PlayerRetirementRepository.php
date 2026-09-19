<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use Goal\Legacy\Modules\Player\Domain\PlayerId;

/** Compact controlled-career retirement facts. NPCs never receive rows here. */
final class PlayerRetirementRepository
{
    private const TABLE = 'career_retirement_records';

    public function __construct(private readonly DatabaseInterface $database, bool $initialize = true)
    {
        if (!$initialize) {
            return;
        }
        SchemaInitializationGuard::run($this->database->connection(), self::class, function (): void {
            $this->database->connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (player_id TEXT PRIMARY KEY, retirement_date TEXT NOT NULL, retirement_season_id TEXT NOT NULL, final_club_id TEXT NULL, reason TEXT NOT NULL, forced INTEGER NOT NULL DEFAULT 0)');
            $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_career_retirement_season ON ' . self::TABLE . ' (retirement_season_id, player_id)');
        });
    }

    public function available(): bool
    {
        $statement = $this->database->connection()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table");
        $statement->execute(['table' => self::TABLE]);

        return $statement->fetchColumn() !== false;
    }

    /** @return array<string, mixed>|null */
    public function get(string|PlayerId $playerId): ?array
    {
        if (!$this->available()) {
            return null;
        }
        $id = $playerId instanceof PlayerId ? $playerId->value() : $playerId;
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE player_id = :player_id');
        $statement->execute(['player_id' => $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? [
            'player_id' => (string) $row['player_id'],
            'retirement_date' => (string) $row['retirement_date'],
            'retirement_season_id' => (string) $row['retirement_season_id'],
            'final_club_id' => $row['final_club_id'] === null ? null : (string) $row['final_club_id'],
            'reason' => (string) $row['reason'],
            'forced' => (bool) $row['forced'],
        ] : null;
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        if (!$this->available()) {
            return [];
        }
        $rows = $this->database->connection()->query('SELECT * FROM ' . self::TABLE . ' ORDER BY retirement_date ASC, player_id ASC')->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(fn (array $row): array => $this->normalize($row), $rows);
    }

    /** @param array<string, mixed> $record */
    public function saveInTransaction(array $record): void
    {
        $this->database->connection()->prepare('INSERT INTO ' . self::TABLE . ' (player_id, retirement_date, retirement_season_id, final_club_id, reason, forced) VALUES (:player_id, :retirement_date, :retirement_season_id, :final_club_id, :reason, :forced) ON CONFLICT(player_id) DO UPDATE SET retirement_date = excluded.retirement_date, retirement_season_id = excluded.retirement_season_id, final_club_id = excluded.final_club_id, reason = excluded.reason, forced = excluded.forced')->execute([
            'player_id' => (string) $record['player_id'],
            'retirement_date' => (string) $record['retirement_date'],
            'retirement_season_id' => (string) $record['retirement_season_id'],
            'final_club_id' => $record['final_club_id'] ?? null,
            'reason' => (string) ($record['reason'] ?? 'career_complete'),
            'forced' => (int) (($record['forced'] ?? false) ? 1 : 0),
        ]);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalize(array $row): array
    {
        return [
            'player_id' => (string) $row['player_id'],
            'retirement_date' => (string) $row['retirement_date'],
            'retirement_season_id' => (string) $row['retirement_season_id'],
            'final_club_id' => $row['final_club_id'] === null ? null : (string) $row['final_club_id'],
            'reason' => (string) $row['reason'],
            'forced' => (bool) $row['forced'],
        ];
    }
}
