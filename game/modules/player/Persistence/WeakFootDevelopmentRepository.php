<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\WeakFootTier;
use Goal\Legacy\Modules\Player\Domain\WeakFootDevelopmentState;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

final class WeakFootDevelopmentRepository
{
    private const TABLE = 'player_weak_foot_development';

    public function __construct(private readonly DatabaseInterface $database, bool $initialize = true)
    {
        if (!$initialize) { return; }
        SchemaInitializationGuard::run($this->database->connection(), self::class, function (): void {
            $this->database->connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (player_id TEXT PRIMARY KEY, progress INTEGER NOT NULL, updated_date TEXT NULL, revision INTEGER NOT NULL)');
        });
    }

    public function state(PlayerId $playerId, int $fallbackProgress = 0): WeakFootDevelopmentState
    {
        if (!$this->tableExists()) {
            return WeakFootDevelopmentState::empty($playerId, WeakFootTier::fromProgress($fallbackProgress));
        }
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE player_id = :player_id');
        $statement->execute(['player_id' => $playerId->value()]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return WeakFootDevelopmentState::empty($playerId, WeakFootTier::fromProgress($fallbackProgress));
        }

        return new WeakFootDevelopmentState(
            $playerId,
            (int) $row['progress'],
            $row['updated_date'] === null ? null : SimulationDate::fromIsoString((string) $row['updated_date']),
            (int) $row['revision'],
        );
    }

    public function saveStateInTransaction(WeakFootDevelopmentState $state): void
    {
        $statement = $this->database->connection()->prepare('INSERT INTO ' . self::TABLE . ' (player_id, progress, updated_date, revision) VALUES (:player_id, :progress, :updated_date, :revision) ON CONFLICT(player_id) DO UPDATE SET progress = excluded.progress, updated_date = excluded.updated_date, revision = excluded.revision');
        $statement->execute([
            'player_id' => $state->playerId()->value(),
            'progress' => $state->progress(),
            'updated_date' => $state->updatedDate()?->toIsoString(),
            'revision' => $state->revision(),
        ]);
    }

    private function tableExists(): bool
    {
        $statement = $this->database->connection()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table");
        $statement->execute(['table' => self::TABLE]);

        return $statement->fetchColumn() !== false;
    }
}
