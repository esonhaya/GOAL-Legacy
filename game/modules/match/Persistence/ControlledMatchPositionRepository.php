<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Domain\OnPitchRole;
use PDO;

/** Stores the controlled Player's position at Match time only. */
final class ControlledMatchPositionRepository
{
    private const TABLE = 'controlled_match_positions';

    public function __construct(private readonly DatabaseInterface $database, bool $initialize = true)
    {
        if (!$initialize) {
            return;
        }
        SchemaInitializationGuard::run($this->database->connection(), self::class, function (): void {
            $this->database->connection()->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                . 'match_id TEXT NOT NULL, player_id TEXT NOT NULL, position TEXT NOT NULL, role TEXT NULL, '
                . 'PRIMARY KEY (match_id, player_id))'
            );
            $columns = $this->database->connection()->query('PRAGMA table_info(' . self::TABLE . ')')->fetchAll(PDO::FETCH_ASSOC);
            if (!in_array('role', array_column($columns, 'name'), true)) {
                $this->database->connection()->exec('ALTER TABLE ' . self::TABLE . ' ADD COLUMN role TEXT NULL');
            }
            $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_controlled_match_positions_player ON ' . self::TABLE . ' (player_id, match_id)');
        });
    }

    public function saveInTransaction(MatchId $matchId, PlayerId $playerId, PlayerPosition $position, ?OnPitchRole $role = null): void
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO ' . self::TABLE . ' (match_id, player_id, position, role) VALUES (:match_id, :player_id, :position, :role) '
            . 'ON CONFLICT(match_id, player_id) DO UPDATE SET position = excluded.position, role = excluded.role'
        );
        $statement->execute(['match_id' => $matchId->value(), 'player_id' => $playerId->value(), 'position' => $position->value, 'role' => $role?->value]);
    }

    public function position(MatchId|string $matchId, PlayerId|string $playerId): ?PlayerPosition
    {
        if (!$this->tableExists()) {
            return null;
        }
        $match = $matchId instanceof MatchId ? $matchId : new MatchId($matchId);
        $player = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $statement = $this->database->connection()->prepare('SELECT position FROM ' . self::TABLE . ' WHERE match_id = :match_id AND player_id = :player_id');
        $statement->execute(['match_id' => $match->value(), 'player_id' => $player->value()]);
        $value = $statement->fetchColumn();

        return $value === false ? null : PlayerPosition::tryFrom((string) $value);
    }

    public function role(MatchId|string $matchId, PlayerId|string $playerId): ?OnPitchRole
    {
        if (!$this->tableExists() || !$this->roleColumnExists()) {
            return null;
        }
        $match = $matchId instanceof MatchId ? $matchId : new MatchId($matchId);
        $player = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $statement = $this->database->connection()->prepare('SELECT role FROM ' . self::TABLE . ' WHERE match_id = :match_id AND player_id = :player_id');
        $statement->execute(['match_id' => $match->value(), 'player_id' => $player->value()]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : OnPitchRole::tryFrom((string) $value);
    }

    /** @return array<string, PlayerPosition> keyed by match_id|player_id */
    public function all(): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        $rows = $this->database->connection()->query('SELECT match_id, player_id, position FROM ' . self::TABLE)->fetchAll(PDO::FETCH_ASSOC);
        $positions = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $position = PlayerPosition::tryFrom((string) ($row['position'] ?? ''));
            if ($position === null) { continue; }
            $positions[(string) $row['match_id'] . '|' . (string) $row['player_id']] = $position;
        }

        return $positions;
    }

    private function tableExists(): bool
    {
        $statement = $this->database->connection()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table");
        $statement->execute(['table' => self::TABLE]);

        return $statement->fetchColumn() !== false;
    }

    private function roleColumnExists(): bool
    {
        if (!$this->tableExists()) {
            return false;
        }
        $columns = $this->database->connection()->query('PRAGMA table_info(' . self::TABLE . ')')->fetchAll(PDO::FETCH_ASSOC);

        return in_array('role', array_column($columns, 'name'), true);
    }
}
