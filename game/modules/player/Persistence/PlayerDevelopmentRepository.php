<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Player\Domain\DevelopmentHistoryEntry;
use Goal\Legacy\Modules\Player\Domain\DevelopmentState;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\TrainingFocus;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PDO;

final class PlayerDevelopmentRepository
{
    private const STATE_TABLE = 'player_development_state';
    private const HISTORY_TABLE = 'player_development_history';

    public function __construct(private readonly DatabaseInterface $database)
    {
        $this->database->connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::STATE_TABLE . ' (player_id TEXT PRIMARY KEY, progress_json TEXT NOT NULL, last_processed_date TEXT NULL, current_focus TEXT NULL, revision INTEGER NOT NULL)');
        $this->database->connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::HISTORY_TABLE . ' (id TEXT PRIMARY KEY, player_id TEXT NOT NULL, occurred_date TEXT NOT NULL, source TEXT NOT NULL, source_id TEXT NOT NULL, attribute_deltas_json TEXT NOT NULL, before_ovr INTEGER NOT NULL, after_ovr INTEGER NOT NULL, UNIQUE (player_id, source, source_id))');
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_player_development_history_player_date ON ' . self::HISTORY_TABLE . ' (player_id, occurred_date, id)');
    }

    public function state(PlayerId $playerId): DevelopmentState
    {
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::STATE_TABLE . ' WHERE player_id = :player_id');
        $statement->execute(['player_id' => $playerId->value()]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return DevelopmentState::empty($playerId);
        }
        $progress = json_decode((string) $row['progress_json'], true, 512, JSON_THROW_ON_ERROR);

        return new DevelopmentState(
            $playerId,
            array_map('intval', is_array($progress) ? $progress : []),
            $row['last_processed_date'] === null ? null : SimulationDate::fromIsoString((string) $row['last_processed_date']),
            $row['current_focus'] === null ? null : TrainingFocus::from((string) $row['current_focus']),
            (int) $row['revision'],
        );
    }

    public function saveStateInTransaction(DevelopmentState $state): void
    {
        $statement = $this->database->connection()->prepare('INSERT INTO ' . self::STATE_TABLE . ' (player_id, progress_json, last_processed_date, current_focus, revision) VALUES (:player_id, :progress_json, :last_processed_date, :current_focus, :revision) ON CONFLICT(player_id) DO UPDATE SET progress_json = excluded.progress_json, last_processed_date = excluded.last_processed_date, current_focus = excluded.current_focus, revision = excluded.revision');
        $statement->execute([
            'player_id' => $state->playerId()->value(),
            'progress_json' => json_encode($state->progress(), JSON_THROW_ON_ERROR),
            'last_processed_date' => $state->lastProcessedDate()?->toIsoString(),
            'current_focus' => $state->currentFocus()?->value,
            'revision' => $state->revision(),
        ]);
    }

    public function hasSource(PlayerId $playerId, string $source, string $sourceId): bool
    {
        $statement = $this->database->connection()->prepare('SELECT 1 FROM ' . self::HISTORY_TABLE . ' WHERE player_id = :player_id AND source = :source AND source_id = :source_id');
        $statement->execute(['player_id' => $playerId->value(), 'source' => $source, 'source_id' => $sourceId]);

        return $statement->fetchColumn() !== false;
    }

    public function saveHistoryInTransaction(DevelopmentHistoryEntry $entry): void
    {
        $statement = $this->database->connection()->prepare('INSERT INTO ' . self::HISTORY_TABLE . ' (id, player_id, occurred_date, source, source_id, attribute_deltas_json, before_ovr, after_ovr) VALUES (:id, :player_id, :occurred_date, :source, :source_id, :attribute_deltas_json, :before_ovr, :after_ovr)');
        $statement->execute([
            'id' => $entry->id(),
            'player_id' => $entry->playerId()->value(),
            'occurred_date' => $entry->date()->toIsoString(),
            'source' => $entry->source(),
            'source_id' => $entry->sourceId(),
            'attribute_deltas_json' => json_encode($entry->attributeDeltas(), JSON_THROW_ON_ERROR),
            'before_ovr' => $entry->beforeOverall(),
            'after_ovr' => $entry->afterOverall(),
        ]);
    }

    /** @return list<DevelopmentHistoryEntry> */
    public function byPlayer(PlayerId $playerId): array
    {
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::HISTORY_TABLE . ' WHERE player_id = :player_id ORDER BY occurred_date ASC, id ASC');
        $statement->execute(['player_id' => $playerId->value()]);

        return array_map(fn (array $row): DevelopmentHistoryEntry => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function bySource(PlayerId $playerId, string $source, string $sourceId): ?DevelopmentHistoryEntry
    {
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::HISTORY_TABLE . ' WHERE player_id = :player_id AND source = :source AND source_id = :source_id');
        $statement->execute(['player_id' => $playerId->value(), 'source' => $source, 'source_id' => $sourceId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): DevelopmentHistoryEntry
    {
        $deltas = json_decode((string) $row['attribute_deltas_json'], true, 512, JSON_THROW_ON_ERROR);

        return new DevelopmentHistoryEntry(
            (string) $row['id'],
            new PlayerId((string) $row['player_id']),
            SimulationDate::fromIsoString((string) $row['occurred_date']),
            (string) $row['source'],
            (string) $row['source_id'],
            array_map('intval', is_array($deltas) ? $deltas : []),
            (int) $row['before_ovr'],
            (int) $row['after_ovr'],
        );
    }
}
