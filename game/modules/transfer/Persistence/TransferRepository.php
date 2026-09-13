<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Transfer\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Transfer\Domain\Transfer;
use Goal\Legacy\Modules\Transfer\Domain\TransferException;
use Goal\Legacy\Modules\Transfer\Domain\TransferId;
use Goal\Legacy\Modules\Transfer\Domain\TransferNotFoundException;
use Goal\Legacy\Modules\Transfer\Domain\TransferStatus;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PDO;

final class TransferRepository
{
    private const TABLE = 'transfer_records';
    public function __construct(private readonly DatabaseInterface $database)
    {
        $this->database->connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (id TEXT PRIMARY KEY, player_id TEXT NOT NULL, source_club_id TEXT NOT NULL, destination_club_id TEXT NOT NULL, season_id TEXT NOT NULL, fee INTEGER NOT NULL, effective_date TEXT NOT NULL, status TEXT NOT NULL, source_contract_id TEXT NULL, destination_contract_id TEXT NULL)');
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_transfer_player ON ' . self::TABLE . ' (player_id, effective_date, id)');
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_transfer_status_date ON ' . self::TABLE . ' (status, effective_date, id)');
    }
    public function save(Transfer $transfer): void { $this->database->transaction(function () use ($transfer): void { $this->saveInTransaction($transfer); }); }
    public function saveInTransaction(Transfer $transfer): void
    {
        $this->assertReferences($transfer);
        $statement = $this->database->connection()->prepare('INSERT INTO ' . self::TABLE . ' (id, player_id, source_club_id, destination_club_id, season_id, fee, effective_date, status, source_contract_id, destination_contract_id) VALUES (:id, :player_id, :source_club_id, :destination_club_id, :season_id, :fee, :effective_date, :status, :source_contract_id, :destination_contract_id) ON CONFLICT(id) DO UPDATE SET player_id = excluded.player_id, source_club_id = excluded.source_club_id, destination_club_id = excluded.destination_club_id, season_id = excluded.season_id, fee = excluded.fee, effective_date = excluded.effective_date, status = excluded.status, source_contract_id = excluded.source_contract_id, destination_contract_id = excluded.destination_contract_id');
        $statement->execute($transfer->toArray());
    }
    public function exists(string|TransferId $id): bool { $transferId = $id instanceof TransferId ? $id : new TransferId($id); $statement = $this->database->connection()->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE id = :id'); $statement->execute(['id' => $transferId->value()]); return $statement->fetchColumn() !== false; }
    public function get(string|TransferId $id): Transfer { $transferId = $id instanceof TransferId ? $id : new TransferId($id); $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id'); $statement->execute(['id' => $transferId->value()]); $row = $statement->fetch(); if (!is_array($row)) { throw new TransferNotFoundException($transferId->value()); } return $this->hydrate($row); }
    /** @return list<Transfer> */
    public function all(): array { return $this->hydrateRows($this->database->connection()->query('SELECT * FROM ' . self::TABLE . ' ORDER BY effective_date ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC)); }
    /** @return list<Transfer> */
    public function byPlayer(string|PlayerId $id): array { $playerId = $id instanceof PlayerId ? $id : new PlayerId($id); $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE player_id = :player_id ORDER BY effective_date ASC, id ASC'); $statement->execute(['player_id' => $playerId->value()]); return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC)); }
    /** @param list<array<string, mixed>> $rows @return list<Transfer> */
    private function hydrateRows(array $rows): array { return array_map(fn (array $row): Transfer => $this->hydrate($row), $rows); }
    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Transfer { return new Transfer(new TransferId((string) $row['id']), new PlayerId((string) $row['player_id']), new ClubId((string) $row['source_club_id']), new ClubId((string) $row['destination_club_id']), new SeasonId((string) $row['season_id']), (int) $row['fee'], SimulationDate::fromIsoString((string) $row['effective_date']), TransferStatus::from((string) $row['status']), $row['source_contract_id'] === null ? null : new \Goal\Legacy\Modules\Contract\Domain\ContractId((string) $row['source_contract_id']), $row['destination_contract_id'] === null ? null : new \Goal\Legacy\Modules\Contract\Domain\ContractId((string) $row['destination_contract_id'])); }
    private function assertReferences(Transfer $transfer): void
    {
        if ($transfer->fee() < 0) { throw new TransferException('Transfer fee cannot be negative.'); }
        foreach ([['player_records', 'id', $transfer->playerId()->value(), 'Player'], ['club_records', 'id', $transfer->sourceClubId()->value(), 'source Club'], ['club_records', 'id', $transfer->destinationClubId()->value(), 'destination Club'], ['season_records', 'id', $transfer->seasonId()->value(), 'Season']] as [$table, $column, $value, $label]) { $statement = $this->database->connection()->prepare('SELECT 1 FROM ' . $table . ' WHERE ' . $column . ' = :value'); $statement->execute(['value' => $value]); if ($statement->fetchColumn() === false) { throw new TransferException(sprintf('Transfer references missing %s "%s".', $label, $value)); } }
    }
}
