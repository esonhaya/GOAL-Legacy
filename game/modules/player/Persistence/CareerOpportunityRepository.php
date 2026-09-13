<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunity;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunityStatus;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunityType;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PDO;

final class CareerOpportunityRepository
{
    private const TABLE = 'career_opportunities';

    public function __construct(private readonly DatabaseInterface $database)
    {
        $this->database->connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (id TEXT PRIMARY KEY, player_id TEXT NOT NULL, type TEXT NOT NULL, source_club_id TEXT NOT NULL, target_club_id TEXT NULL, created_date TEXT NOT NULL, expiry_date TEXT NULL, status TEXT NOT NULL, context_json TEXT NOT NULL, source_key TEXT NOT NULL UNIQUE)');
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_career_opportunity_player_status ON ' . self::TABLE . ' (player_id, status, created_date, id)');
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_career_opportunity_target ON ' . self::TABLE . ' (target_club_id, status, id)');
    }

    public function saveInTransaction(CareerOpportunity $opportunity): void
    {
        $values = $opportunity->toArray(); $values['context_json'] = json_encode($values['context'], JSON_THROW_ON_ERROR); unset($values['context']);
        $statement = $this->database->connection()->prepare('INSERT INTO ' . self::TABLE . ' (id, player_id, type, source_club_id, target_club_id, created_date, expiry_date, status, context_json, source_key) VALUES (:id, :player_id, :type, :source_club_id, :target_club_id, :created_date, :expiry_date, :status, :context_json, :source_key) ON CONFLICT(source_key) DO NOTHING'); $statement->execute($values);
    }

    public function get(string $id): ?CareerOpportunity
    {
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id'); $statement->execute(['id' => $id]); $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function bySourceKey(string $sourceKey): ?CareerOpportunity
    {
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE source_key = :source_key'); $statement->execute(['source_key' => $sourceKey]); $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return list<CareerOpportunity> */
    public function openForPlayer(PlayerId $playerId, ?SimulationDate $asOf = null): array
    {
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE player_id = :player_id AND status = :status ORDER BY created_date ASC, id ASC'); $statement->execute(['player_id' => $playerId->value(), 'status' => CareerOpportunityStatus::Open->value]);
        $rows = array_map(fn (array $row): CareerOpportunity => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC));
        if ($asOf === null) { return $rows; }

        return array_values(array_filter($rows, static fn (CareerOpportunity $value): bool => $value->expiryDate() === null || !$asOf->isAfter($value->expiryDate())));
    }

    public function updateStatusInTransaction(CareerOpportunity $opportunity, CareerOpportunityStatus $status): void
    {
        $this->database->connection()->prepare('UPDATE ' . self::TABLE . ' SET status = :status WHERE id = :id')->execute(['status' => $status->value, 'id' => $opportunity->id()]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): CareerOpportunity
    {
        $context = json_decode((string) $row['context_json'], true, 512, JSON_THROW_ON_ERROR);

        return new CareerOpportunity((string) $row['id'], new PlayerId((string) $row['player_id']), CareerOpportunityType::from((string) $row['type']), new ClubId((string) $row['source_club_id']), $row['target_club_id'] === null ? null : new ClubId((string) $row['target_club_id']), SimulationDate::fromIsoString((string) $row['created_date']), $row['expiry_date'] === null ? null : SimulationDate::fromIsoString((string) $row['expiry_date']), CareerOpportunityStatus::from((string) $row['status']), is_array($context) ? $context : [], (string) $row['source_key']);
    }
}
