<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Finance;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use PDO;

/** Durable controlled-player finance state, ledger, and lifestyle ownership. */
final class PlayerFinanceRepository
{
    public const STATE_TABLE = 'player_finance_state';
    public const TRANSACTION_TABLE = 'player_finance_transactions';
    public const OWNERSHIP_TABLE = 'player_lifestyle_ownership';

    public function __construct(
        private readonly DatabaseInterface $database,
        bool $initialize = true,
    ) {
        if (!$initialize) {
            return;
        }
        SchemaInitializationGuard::run($this->database->connection(), self::class, function (): void {
            $connection = $this->database->connection();
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::STATE_TABLE . ' (player_id TEXT PRIMARY KEY, balance INTEGER NOT NULL, initialized_date TEXT NOT NULL, last_payroll_date TEXT NOT NULL)');
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::TRANSACTION_TABLE . ' (id TEXT PRIMARY KEY, player_id TEXT NOT NULL, occurred_date TEXT NOT NULL, type TEXT NOT NULL, amount INTEGER NOT NULL, balance_before INTEGER NOT NULL, balance_after INTEGER NOT NULL, source_id TEXT NOT NULL, context TEXT NOT NULL, UNIQUE (player_id, source_id))');
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::OWNERSHIP_TABLE . ' (player_id TEXT NOT NULL, item_id TEXT NOT NULL, purchased_date TEXT NOT NULL, price INTEGER NOT NULL, PRIMARY KEY (player_id, item_id))');
            $connection->exec('CREATE INDEX IF NOT EXISTS idx_player_finance_transactions_player_date ON ' . self::TRANSACTION_TABLE . ' (player_id, occurred_date DESC, id DESC)');
        });
    }

    public function available(): bool
    {
        $statement = $this->database->connection()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table");
        $statement->execute(['table' => self::STATE_TABLE]);

        return $statement->fetchColumn() !== false;
    }

    /** @return array{player_id:string,balance:int,initialized_date:string,last_payroll_date:string}|null */
    public function state(string $playerId): ?array
    {
        if (!$this->available()) {
            return null;
        }
        $statement = $this->database->connection()->prepare('SELECT player_id, balance, initialized_date, last_payroll_date FROM ' . self::STATE_TABLE . ' WHERE player_id = :player_id');
        $statement->execute(['player_id' => $playerId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? [
            'player_id' => (string) $row['player_id'],
            'balance' => (int) $row['balance'],
            'initialized_date' => (string) $row['initialized_date'],
            'last_payroll_date' => (string) $row['last_payroll_date'],
        ] : null;
    }

    /** @return list<array<string, int|string>> */
    public function transactions(string $playerId, int $limit = 20): array
    {
        if (!$this->available()) {
            return [];
        }
        $statement = $this->database->connection()->prepare('SELECT id, player_id, occurred_date, type, amount, balance_before, balance_after, source_id, context FROM ' . self::TRANSACTION_TABLE . ' WHERE player_id = :player_id ORDER BY occurred_date DESC, id DESC LIMIT :limit');
        $statement->bindValue(':player_id', $playerId);
        $statement->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $statement->execute();

        return array_map(static function (array $row): array {
            foreach (['id', 'player_id', 'occurred_date', 'type', 'source_id', 'context'] as $key) {
                $row[$key] = (string) $row[$key];
            }
            foreach (['amount', 'balance_before', 'balance_after'] as $key) {
                $row[$key] = (int) $row[$key];
            }

            return $row;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array{player_id:string,item_id:string,purchased_date:string,price:int}> */
    public function ownership(string $playerId): array
    {
        if (!$this->available()) {
            return [];
        }
        $statement = $this->database->connection()->prepare('SELECT player_id, item_id, purchased_date, price FROM ' . self::OWNERSHIP_TABLE . ' WHERE player_id = :player_id ORDER BY purchased_date ASC, item_id ASC');
        $statement->execute(['player_id' => $playerId]);

        return array_map(static fn (array $row): array => [
            'player_id' => (string) $row['player_id'],
            'item_id' => (string) $row['item_id'],
            'purchased_date' => (string) $row['purchased_date'],
            'price' => (int) $row['price'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function ownershipExistsInTransaction(string $playerId, string $itemId): bool
    {
        $statement = $this->database->connection()->prepare('SELECT 1 FROM ' . self::OWNERSHIP_TABLE . ' WHERE player_id = :player_id AND item_id = :item_id');
        $statement->execute(['player_id' => $playerId, 'item_id' => $itemId]);

        return $statement->fetchColumn() !== false;
    }

    public function saveStateInTransaction(string $playerId, int $balance, string $initializedDate, string $lastPayrollDate): void
    {
        $statement = $this->database->connection()->prepare('INSERT INTO ' . self::STATE_TABLE . ' (player_id, balance, initialized_date, last_payroll_date) VALUES (:player_id, :balance, :initialized_date, :last_payroll_date) ON CONFLICT(player_id) DO UPDATE SET balance = excluded.balance, initialized_date = excluded.initialized_date, last_payroll_date = excluded.last_payroll_date');
        $statement->execute([
            'player_id' => $playerId,
            'balance' => $balance,
            'initialized_date' => $initializedDate,
            'last_payroll_date' => $lastPayrollDate,
        ]);
    }

    /** @return array<string, int|string>|null */
    public function transactionBySourceInTransaction(string $playerId, string $sourceId): ?array
    {
        $statement = $this->database->connection()->prepare('SELECT id, player_id, occurred_date, type, amount, balance_before, balance_after, source_id, context FROM ' . self::TRANSACTION_TABLE . ' WHERE player_id = :player_id AND source_id = :source_id');
        $statement->execute(['player_id' => $playerId, 'source_id' => $sourceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function insertTransactionInTransaction(array $values): void
    {
        $statement = $this->database->connection()->prepare('INSERT INTO ' . self::TRANSACTION_TABLE . ' (id, player_id, occurred_date, type, amount, balance_before, balance_after, source_id, context) VALUES (:id, :player_id, :occurred_date, :type, :amount, :balance_before, :balance_after, :source_id, :context)');
        $statement->execute($values);
    }

    public function saveOwnershipInTransaction(string $playerId, string $itemId, string $date, int $price): void
    {
        $statement = $this->database->connection()->prepare('INSERT INTO ' . self::OWNERSHIP_TABLE . ' (player_id, item_id, purchased_date, price) VALUES (:player_id, :item_id, :purchased_date, :price)');
        $statement->execute(['player_id' => $playerId, 'item_id' => $itemId, 'purchased_date' => $date, 'price' => $price]);
    }

    /** @return array{income:int,spending:int,wage_income:int} */
    public function totals(string $playerId): array
    {
        if (!$this->available()) {
            return ['income' => 0, 'spending' => 0, 'wage_income' => 0];
        }
        $statement = $this->database->connection()->prepare('SELECT COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS income, COALESCE(SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END), 0) AS spending, COALESCE(SUM(CASE WHEN type = :wage AND amount > 0 THEN amount ELSE 0 END), 0) AS wage_income FROM ' . self::TRANSACTION_TABLE . ' WHERE player_id = :player_id');
        $statement->execute(['player_id' => $playerId, 'wage' => 'wage']);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        return ['income' => (int) ($row['income'] ?? 0), 'spending' => (int) ($row['spending'] ?? 0), 'wage_income' => (int) ($row['wage_income'] ?? 0)];
    }
}
