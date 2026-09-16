<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use Goal\Legacy\Modules\Player\Domain\CareerPriority;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

final class PlayerPriorityRepository
{
    private const TABLE = 'player_career_priorities';

    public function __construct(private readonly DatabaseInterface $database)
    {
        SchemaInitializationGuard::run($this->database->connection(), self::class, function (): void {
            $this->database->connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (player_id TEXT PRIMARY KEY, priority TEXT NOT NULL, changed_date TEXT NOT NULL)');
        });
    }

    public function current(PlayerId $playerId): CareerPriority
    {
        $statement = $this->database->connection()->prepare('SELECT priority FROM ' . self::TABLE . ' WHERE player_id = :player_id');
        $statement->execute(['player_id' => $playerId->value()]);
        $value = $statement->fetchColumn();

        return $value === false ? CareerPriority::Balanced : CareerPriority::from((string) $value);
    }

    public function saveInTransaction(PlayerId $playerId, CareerPriority $priority, SimulationDate $date): void
    {
        $statement = $this->database->connection()->prepare('INSERT INTO ' . self::TABLE . ' (player_id, priority, changed_date) VALUES (:player_id, :priority, :changed_date) ON CONFLICT(player_id) DO UPDATE SET priority = excluded.priority, changed_date = excluded.changed_date');
        $statement->execute(['player_id' => $playerId->value(), 'priority' => $priority->value, 'changed_date' => $date->toIsoString()]);
    }
}
