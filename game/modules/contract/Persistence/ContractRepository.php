<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Contract\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Contract\Domain\Contract;
use Goal\Legacy\Modules\Contract\Domain\ContractException;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Contract\Domain\ContractNotFoundException;
use Goal\Legacy\Modules\Contract\Domain\ContractStatus;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PDO;

final class ContractRepository
{
    private const TABLE = 'contract_records';

    public function __construct(private readonly DatabaseInterface $database)
    {
        $this->database->connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (id TEXT PRIMARY KEY, player_id TEXT NOT NULL, club_id TEXT NOT NULL, start_date TEXT NOT NULL, end_date TEXT NOT NULL, wage INTEGER NOT NULL, status TEXT NOT NULL)');
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_contract_player_status ON ' . self::TABLE . ' (player_id, status, start_date, id)');
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_contract_club_status ON ' . self::TABLE . ' (club_id, status, start_date, id)');
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_contract_expiry ON ' . self::TABLE . ' (status, end_date, id)');
    }

    public function save(Contract $contract): void
    {
        $this->database->transaction(function () use ($contract): void {
            $this->saveInTransaction($contract);
        });
    }

    public function saveInTransaction(Contract $contract): void
    {
        $this->assertReferences($contract);
        $existing = $this->database->connection()->prepare('SELECT player_id FROM ' . self::TABLE . ' WHERE id = :id');
        $existing->execute(['id' => $contract->id()->value()]);
        $existingPlayer = $existing->fetchColumn();
        if ($existingPlayer !== false && (string) $existingPlayer !== $contract->playerId()->value()) {
            throw new ContractException(sprintf('Contract ID "%s" already belongs to another Player.', $contract->id()->value()));
        }
        if ($contract->status() === ContractStatus::Active) {
            $statement = $this->database->connection()->prepare('SELECT id FROM ' . self::TABLE . ' WHERE player_id = :player_id AND status = :status AND id <> :id LIMIT 1');
            $statement->execute(['player_id' => $contract->playerId()->value(), 'status' => ContractStatus::Active->value, 'id' => $contract->id()->value()]);
            $conflictingId = $statement->fetchColumn();
            if ($conflictingId !== false) {
                throw new ContractException(sprintf('Player "%s" already has another active Contract.', $contract->playerId()->value()));
            }
        }
        $statement = $this->database->connection()->prepare('INSERT INTO ' . self::TABLE . ' (id, player_id, club_id, start_date, end_date, wage, status) VALUES (:id, :player_id, :club_id, :start_date, :end_date, :wage, :status) ON CONFLICT(id) DO UPDATE SET player_id = excluded.player_id, club_id = excluded.club_id, start_date = excluded.start_date, end_date = excluded.end_date, wage = excluded.wage, status = excluded.status');
        $statement->execute($contract->toArray());
    }

    public function exists(string|ContractId $id): bool
    {
        $contractId = $id instanceof ContractId ? $id : new ContractId($id);
        $statement = $this->database->connection()->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE id = :id');
        $statement->execute(['id' => $contractId->value()]);
        return $statement->fetchColumn() !== false;
    }

    public function get(string|ContractId $id): Contract
    {
        $contractId = $id instanceof ContractId ? $id : new ContractId($id);
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id');
        $statement->execute(['id' => $contractId->value()]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ContractNotFoundException($contractId->value());
        }
        return $this->hydrate($row);
    }

    /** @return list<Contract> */
    public function all(): array
    {
        return $this->hydrateRows($this->database->connection()->query('SELECT * FROM ' . self::TABLE . ' ORDER BY player_id ASC, start_date ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<Contract> */
    public function byPlayer(string|PlayerId $id): array
    {
        $playerId = $id instanceof PlayerId ? $id : new PlayerId($id);
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE player_id = :player_id ORDER BY start_date ASC, id ASC');
        $statement->execute(['player_id' => $playerId->value()]);
        return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<Contract> */
    public function byClub(string|ClubId $id): array
    {
        $clubId = $id instanceof ClubId ? $id : new ClubId($id);
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE club_id = :club_id ORDER BY player_id ASC, start_date ASC, id ASC');
        $statement->execute(['club_id' => $clubId->value()]);
        return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function activeForPlayer(string|PlayerId $id): ?Contract
    {
        $playerId = $id instanceof PlayerId ? $id : new PlayerId($id);
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE player_id = :player_id AND status = :status ORDER BY start_date DESC, id ASC LIMIT 1');
        $statement->execute(['player_id' => $playerId->value(), 'status' => ContractStatus::Active->value]);
        $row = $statement->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return list<array{before: Contract, after: Contract}> */
    public function evaluateLifecycleInTransaction(SimulationDate $date): array
    {
        $rows = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE status IN (:pending, :active) AND (start_date <= :date OR end_date < :date) ORDER BY id ASC');
        $rows->execute(['pending' => ContractStatus::Pending->value, 'active' => ContractStatus::Active->value, 'date' => $date->toIsoString()]);
        $changes = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $before = $this->hydrate($row);
            $afterStatus = $date->isBefore($before->startDate()) ? ContractStatus::Pending : ($date->isAfter($before->endDate()) ? ContractStatus::Expired : ContractStatus::Active);
            if ($afterStatus !== $before->status()) {
                $after = $before->withStatus($afterStatus);
                $this->saveInTransaction($after);
                $changes[] = ['before' => $before, 'after' => $after];
            }
        }
        return $changes;
    }

    /** @param list<array<string, mixed>> $rows @return list<Contract> */
    private function hydrateRows(array $rows): array { return array_map(fn (array $row): Contract => $this->hydrate($row), $rows); }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Contract
    {
        return new Contract(new ContractId((string) $row['id']), new PlayerId((string) $row['player_id']), new ClubId((string) $row['club_id']), SimulationDate::fromIsoString((string) $row['start_date']), SimulationDate::fromIsoString((string) $row['end_date']), (int) $row['wage'], ContractStatus::from((string) $row['status']));
    }

    private function assertReferences(Contract $contract): void
    {
        if ($contract->status() === ContractStatus::Active) {
            $careerState = $this->database->connection()->prepare("SELECT career_state FROM player_records WHERE id = :player_id");
            $careerState->execute(['player_id' => $contract->playerId()->value()]);
            if ((string) $careerState->fetchColumn() === 'retired') {
                throw new ContractException('Retired Players cannot receive active Contracts.');
            }
        }
        foreach ([['player_records', 'id', $contract->playerId()->value(), 'Player'], ['club_records', 'id', $contract->clubId()->value(), 'Club']] as [$table, $column, $value, $label]) {
            $statement = $this->database->connection()->prepare('SELECT 1 FROM ' . $table . ' WHERE ' . $column . ' = :value');
            $statement->execute(['value' => $value]);
            if ($statement->fetchColumn() === false) {
                throw new ContractException(sprintf('Contract references missing %s "%s".', $label, $value));
            }
        }
    }
}
