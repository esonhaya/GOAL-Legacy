<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Transfer\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Transfer\Domain\Loan;
use Goal\Legacy\Modules\Transfer\Domain\LoanStatus;
use Goal\Legacy\Modules\Transfer\Domain\TransferException;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PDO;

/** Persistence for active loan state, not a second movement-history ledger. */
final class LoanRepository
{
    private const TABLE = 'player_loans';

    public function __construct(private readonly DatabaseInterface $database, bool $initialize = true)
    {
        if ($initialize) {
            $this->initializeSchema();
        }
    }

    public function initializeSchema(): void
    {
        $this->database->connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (id TEXT PRIMARY KEY, player_id TEXT NOT NULL, parent_club_id TEXT NOT NULL, loan_club_id TEXT NOT NULL, season_id TEXT NOT NULL, start_date TEXT NOT NULL, scheduled_end_date TEXT NOT NULL, parent_role TEXT NOT NULL, loan_role TEXT NOT NULL, status TEXT NOT NULL)');
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_player_loans_player_status ON ' . self::TABLE . ' (player_id, status, start_date, id)');
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_player_loans_end ON ' . self::TABLE . ' (status, scheduled_end_date, id)');
    }

    public function tableExists(): bool
    {
        $statement = $this->database->connection()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table");
        $statement->execute(['table' => self::TABLE]);

        return $statement->fetchColumn() !== false;
    }

    public function saveInTransaction(Loan $loan): void
    {
        $this->initializeSchema();
        $active = $this->activeForPlayer($loan->playerId());
        if ($loan->status() === LoanStatus::Active && $active !== null && $active->id() !== $loan->id()) {
            throw new TransferException('Player already has an active loan.');
        }
        $statement = $this->database->connection()->prepare('INSERT INTO ' . self::TABLE . ' (id, player_id, parent_club_id, loan_club_id, season_id, start_date, scheduled_end_date, parent_role, loan_role, status) VALUES (:id, :player_id, :parent_club_id, :loan_club_id, :season_id, :start_date, :scheduled_end_date, :parent_role, :loan_role, :status) ON CONFLICT(id) DO UPDATE SET player_id = excluded.player_id, parent_club_id = excluded.parent_club_id, loan_club_id = excluded.loan_club_id, season_id = excluded.season_id, start_date = excluded.start_date, scheduled_end_date = excluded.scheduled_end_date, parent_role = excluded.parent_role, loan_role = excluded.loan_role, status = excluded.status');
        $statement->execute($loan->toArray());
    }

    public function save(Loan $loan): void
    {
        $this->database->transaction(fn (): mixed => $this->saveInTransaction($loan));
    }

    public function get(string $id): ?Loan
    {
        if (!$this->tableExists()) { return null; }
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function activeForPlayer(PlayerId|string $playerId, ?SeasonId $seasonId = null, ?SimulationDate $asOf = null): ?Loan
    {
        if (!$this->tableExists()) { return null; }
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $sql = 'SELECT * FROM ' . self::TABLE . ' WHERE player_id = :player_id AND status = :status';
        $parameters = ['player_id' => $id->value(), 'status' => LoanStatus::Active->value];
        if ($seasonId !== null) { $sql .= ' AND season_id = :season_id'; $parameters['season_id'] = $seasonId->value(); }
        $sql .= ' ORDER BY start_date DESC, id ASC LIMIT 1';
        $statement = $this->database->connection()->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) { return null; }
        $loan = $this->hydrate($row);
        if ($asOf !== null && ($asOf->isBefore($loan->startDate()) || $asOf->isAfter($loan->scheduledEndDate()))) { return null; }

        return $loan;
    }

    /** @return list<Loan> */
    public function activeDue(SimulationDate $date): array
    {
        if (!$this->tableExists()) { return []; }
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE status = :status AND scheduled_end_date <= :date ORDER BY scheduled_end_date ASC, id ASC');
        $statement->execute(['status' => LoanStatus::Active->value, 'date' => $date->toIsoString()]);

        return array_map(fn (array $row): Loan => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<Loan> */
    public function byPlayer(PlayerId|string $playerId): array
    {
        if (!$this->tableExists()) { return []; }
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE player_id = :player_id ORDER BY start_date ASC, id ASC');
        $statement->execute(['player_id' => $id->value()]);

        return array_map(fn (array $row): Loan => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Loan
    {
        return new Loan((string) $row['id'], new PlayerId((string) $row['player_id']), new ClubId((string) $row['parent_club_id']), new ClubId((string) $row['loan_club_id']), new SeasonId((string) $row['season_id']), SimulationDate::fromIsoString((string) $row['start_date']), SimulationDate::fromIsoString((string) $row['scheduled_end_date']), SquadRole::from((string) $row['parent_role']), SquadRole::from((string) $row['loan_role']), LoanStatus::from((string) $row['status']));
    }
}
