<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Domain\PositionDevelopmentState;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PDO;

/** Compact controlled-Career positional state; NPCs never receive rows. */
final class PositionDevelopmentRepository
{
    private const STATE_TABLE = 'player_position_development';
    private const HISTORY_TABLE = 'career_position_changes';

    public function __construct(private readonly DatabaseInterface $database, bool $initialize = true)
    {
        if (!$initialize) {
            return;
        }
        SchemaInitializationGuard::run($this->database->connection(), self::class, function (): void {
            $connection = $this->database->connection();
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::STATE_TABLE . ' (player_id TEXT PRIMARY KEY, secondary_positions_json TEXT NOT NULL, developing_position TEXT NULL, progress_json TEXT NOT NULL, updated_date TEXT NULL, revision INTEGER NOT NULL)');
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::HISTORY_TABLE . ' (id TEXT PRIMARY KEY, player_id TEXT NOT NULL, occurred_date TEXT NOT NULL, from_position TEXT NOT NULL, to_position TEXT NOT NULL, source_key TEXT NOT NULL UNIQUE)');
            $connection->exec('CREATE INDEX IF NOT EXISTS idx_position_changes_player_date ON ' . self::HISTORY_TABLE . ' (player_id, occurred_date, id)');
        });
    }

    public function state(PlayerId $playerId): PositionDevelopmentState
    {
        if (!$this->tableExists(self::STATE_TABLE)) {
            return PositionDevelopmentState::empty($playerId);
        }
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::STATE_TABLE . ' WHERE player_id = :player_id');
        $statement->execute(['player_id' => $playerId->value()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return PositionDevelopmentState::empty($playerId);
        }
        $secondary = json_decode((string) $row['secondary_positions_json'], true, 512, JSON_THROW_ON_ERROR);
        $progress = json_decode((string) $row['progress_json'], true, 512, JSON_THROW_ON_ERROR);

        return new PositionDevelopmentState(
            $playerId,
            array_values(array_filter(array_map(static fn (mixed $value): ?PlayerPosition => is_string($value) ? PlayerPosition::tryFrom($value) : null, is_array($secondary) ? $secondary : []))),
            $row['developing_position'] === null ? null : PlayerPosition::from((string) $row['developing_position']),
            is_array($progress) ? array_map('intval', $progress) : [],
            $row['updated_date'] === null ? null : SimulationDate::fromIsoString((string) $row['updated_date']),
            (int) $row['revision'],
        );
    }

    public function saveStateInTransaction(PositionDevelopmentState $state): void
    {
        $statement = $this->database->connection()->prepare('INSERT INTO ' . self::STATE_TABLE . ' (player_id, secondary_positions_json, developing_position, progress_json, updated_date, revision) VALUES (:player_id, :secondary_positions_json, :developing_position, :progress_json, :updated_date, :revision) ON CONFLICT(player_id) DO UPDATE SET secondary_positions_json = excluded.secondary_positions_json, developing_position = excluded.developing_position, progress_json = excluded.progress_json, updated_date = excluded.updated_date, revision = excluded.revision');
        $statement->execute([
            'player_id' => $state->playerId()->value(),
            'secondary_positions_json' => json_encode(array_map(static fn (PlayerPosition $position): string => $position->value, $state->secondaryPositions()), JSON_THROW_ON_ERROR),
            'developing_position' => $state->developingPosition()?->value,
            'progress_json' => json_encode($state->progress(), JSON_THROW_ON_ERROR),
            'updated_date' => $state->updatedDate()?->toIsoString(),
            'revision' => $state->revision(),
        ]);
    }

    /** @return list<array<string,string>> */
    public function changes(PlayerId $playerId): array
    {
        if (!$this->tableExists(self::HISTORY_TABLE)) {
            return [];
        }
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::HISTORY_TABLE . ' WHERE player_id = :player_id ORDER BY occurred_date ASC, id ASC');
        $statement->execute(['player_id' => $playerId->value()]);

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'player_id' => (string) $row['player_id'],
            'occurred_date' => (string) $row['occurred_date'],
            'from_position' => (string) $row['from_position'],
            'to_position' => (string) $row['to_position'],
            'source_key' => (string) $row['source_key'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function saveChangeInTransaction(PlayerId $playerId, PlayerPosition $from, PlayerPosition $to, SimulationDate $date): void
    {
        $sourceKey = implode('|', ['position-change', $playerId->value(), $date->toIsoString(), $from->value, $to->value]);
        $statement = $this->database->connection()->prepare('INSERT INTO ' . self::HISTORY_TABLE . ' (id, player_id, occurred_date, from_position, to_position, source_key) VALUES (:id, :player_id, :occurred_date, :from_position, :to_position, :source_key) ON CONFLICT(source_key) DO NOTHING');
        $statement->execute([
            'id' => hash('sha256', $sourceKey),
            'player_id' => $playerId->value(),
            'occurred_date' => $date->toIsoString(),
            'from_position' => $from->value,
            'to_position' => $to->value,
            'source_key' => $sourceKey,
        ]);
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->database->connection()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table");
        $statement->execute(['table' => $table]);

        return $statement->fetchColumn() !== false;
    }
}
