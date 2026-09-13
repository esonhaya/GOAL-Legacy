<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Player\Domain\Injury;
use Goal\Legacy\Modules\Player\Domain\InjuryCategory;
use Goal\Legacy\Modules\Player\Domain\InjurySeverity;
use Goal\Legacy\Modules\Player\Domain\InjuryStatus;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PDO;

final class PlayerAvailabilityRepository
{
    private const STATE_TABLE = 'player_availability_state';
    private const SOURCE_TABLE = 'player_availability_sources';
    private const INJURY_TABLE = 'player_injuries';
    private const RECOVERY_RATE_PER_DAY = 7;

    public function __construct(private readonly DatabaseInterface $database)
    {
        $this->database->connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::STATE_TABLE . ' (player_id TEXT PRIMARY KEY, fatigue INTEGER NOT NULL, last_processed_date TEXT NOT NULL, revision INTEGER NOT NULL)');
        $this->database->connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::SOURCE_TABLE . ' (player_id TEXT NOT NULL, source_type TEXT NOT NULL, source_id TEXT NOT NULL, fatigue_delta INTEGER NOT NULL, occurred_date TEXT NOT NULL, PRIMARY KEY (player_id, source_type, source_id))');
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_player_availability_sources_player ON ' . self::SOURCE_TABLE . ' (player_id, occurred_date, source_type, source_id)');
        $this->database->connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::INJURY_TABLE . ' (id TEXT PRIMARY KEY, player_id TEXT NOT NULL, source_type TEXT NOT NULL, source_id TEXT NOT NULL, category TEXT NOT NULL, severity TEXT NOT NULL, start_date TEXT NOT NULL, recovery_date TEXT NOT NULL, status TEXT NOT NULL, actual_recovery_date TEXT NULL, UNIQUE (player_id, source_type, source_id))');
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_player_injuries_active ON ' . self::INJURY_TABLE . ' (player_id, status, recovery_date, id)');
    }

    /** @return array{fatigue:int,last_date:?SimulationDate,revision:int} */
    public function state(PlayerId $playerId): array
    {
        $statement = $this->database->connection()->prepare('SELECT fatigue, last_processed_date, revision FROM ' . self::STATE_TABLE . ' WHERE player_id = :player_id');
        $statement->execute(['player_id' => $playerId->value()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['fatigue' => 0, 'last_date' => null, 'revision' => 0];
        }

        return ['fatigue' => (int) $row['fatigue'], 'last_date' => SimulationDate::fromIsoString((string) $row['last_processed_date']), 'revision' => (int) $row['revision']];
    }

    public function fatigueAt(PlayerId $playerId, SimulationDate $date): int
    {
        $state = $this->state($playerId);
        if ($state['last_date'] === null || !$state['last_date']->isBefore($date)) {
            return $state['fatigue'];
        }

        return max(0, $state['fatigue'] - ($state['last_date']->daysUntil($date) * self::RECOVERY_RATE_PER_DAY));
    }

    public function saveStateInTransaction(PlayerId $playerId, int $fatigue, SimulationDate $date, int $revision): void
    {
        $statement = $this->database->connection()->prepare('INSERT INTO ' . self::STATE_TABLE . ' (player_id, fatigue, last_processed_date, revision) VALUES (:player_id, :fatigue, :last_processed_date, :revision) ON CONFLICT(player_id) DO UPDATE SET fatigue = excluded.fatigue, last_processed_date = excluded.last_processed_date, revision = excluded.revision');
        $statement->execute(['player_id' => $playerId->value(), 'fatigue' => max(0, min(100, $fatigue)), 'last_processed_date' => $date->toIsoString(), 'revision' => $revision]);
    }

    public function hasSource(PlayerId $playerId, string $sourceType, string $sourceId): bool
    {
        $statement = $this->database->connection()->prepare('SELECT 1 FROM ' . self::SOURCE_TABLE . ' WHERE player_id = :player_id AND source_type = :source_type AND source_id = :source_id');
        $statement->execute(['player_id' => $playerId->value(), 'source_type' => $sourceType, 'source_id' => $sourceId]);

        return $statement->fetchColumn() !== false;
    }

    public function recordSourceInTransaction(PlayerId $playerId, string $sourceType, string $sourceId, int $fatigueDelta, SimulationDate $date): void
    {
        $statement = $this->database->connection()->prepare('INSERT INTO ' . self::SOURCE_TABLE . ' (player_id, source_type, source_id, fatigue_delta, occurred_date) VALUES (:player_id, :source_type, :source_id, :fatigue_delta, :occurred_date)');
        $statement->execute(['player_id' => $playerId->value(), 'source_type' => $sourceType, 'source_id' => $sourceId, 'fatigue_delta' => $fatigueDelta, 'occurred_date' => $date->toIsoString()]);
    }

    public function saveInjuryInTransaction(Injury $injury): void
    {
        $values = $injury->toArray();
        $statement = $this->database->connection()->prepare('INSERT INTO ' . self::INJURY_TABLE . ' (id, player_id, source_type, source_id, category, severity, start_date, recovery_date, status, actual_recovery_date) VALUES (:id, :player_id, :source_type, :source_id, :category, :severity, :start_date, :recovery_date, :status, :actual_recovery_date)');
        $statement->execute($values);
    }

    public function activeInjuryAt(PlayerId $playerId, SimulationDate $date): ?Injury
    {
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::INJURY_TABLE . ' WHERE player_id = :player_id AND status = :status AND start_date <= :date AND recovery_date > :date ORDER BY start_date DESC, id DESC LIMIT 1');
        $statement->execute(['player_id' => $playerId->value(), 'status' => InjuryStatus::Active->value, 'date' => $date->toIsoString()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrateInjury($row) : null;
    }

    /** @return list<Injury> */
    public function byPlayer(PlayerId $playerId): array
    {
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::INJURY_TABLE . ' WHERE player_id = :player_id ORDER BY start_date ASC, id ASC');
        $statement->execute(['player_id' => $playerId->value()]);

        return array_map(fn (array $row): Injury => $this->hydrateInjury($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<Injury> */
    public function dueActiveInjuries(SimulationDate $date): array
    {
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::INJURY_TABLE . ' WHERE status = :status AND recovery_date <= :date ORDER BY recovery_date ASC, id ASC');
        $statement->execute(['status' => InjuryStatus::Active->value, 'date' => $date->toIsoString()]);

        return array_map(fn (array $row): Injury => $this->hydrateInjury($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function markRecoveredInTransaction(Injury $injury, SimulationDate $date): void
    {
        $statement = $this->database->connection()->prepare('UPDATE ' . self::INJURY_TABLE . ' SET status = :status, actual_recovery_date = :actual_recovery_date WHERE id = :id AND status = :active_status');
        $statement->execute(['status' => InjuryStatus::Recovered->value, 'actual_recovery_date' => $date->toIsoString(), 'id' => $injury->id(), 'active_status' => InjuryStatus::Active->value]);
    }

    /** @param array<string, mixed> $row */
    private function hydrateInjury(array $row): Injury
    {
        return new Injury((string) $row['id'], new PlayerId((string) $row['player_id']), (string) $row['source_type'], (string) $row['source_id'], InjuryCategory::from((string) $row['category']), InjurySeverity::from((string) $row['severity']), SimulationDate::fromIsoString((string) $row['start_date']), SimulationDate::fromIsoString((string) $row['recovery_date']), InjuryStatus::from((string) $row['status']), $row['actual_recovery_date'] === null ? null : SimulationDate::fromIsoString((string) $row['actual_recovery_date']));
    }
}
