<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Finance;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Contract\Persistence\ContractRepository;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use RuntimeException;

/**
 * Controlled-player finance owner. Career references are the only entry point
 * for automatic payroll, so NPC contracts never create personal finance rows.
 */
final class PlayerFinanceService
{
    public const CURRENCY = 'GC';
    public const OPENING_BALANCE = 50;
    public const PAYROLL_PERIOD = 'weekly';

    public function initializeInTransaction(DatabaseInterface $database, PlayerId|string $playerId, SimulationDate $date): void
    {
        $id = $this->id($playerId);
        $repository = new PlayerFinanceRepository($database);
        if ($repository->state($id) !== null) {
            return;
        }
        // The opening ledger entry owns the balance change. Start the state at
        // zero so the opening amount is recorded exactly once.
        $repository->saveStateInTransaction($id, 0, $date->toIsoString(), $date->toIsoString());
        $this->recordInTransaction($database, $id, $date, 'opening_balance', self::OPENING_BALANCE, 'career-opening:' . $id, 'Career finance activated');
    }

    /** @return array<string, mixed> */
    public function summary(DatabaseInterface $database, PlayerId|string $playerId, ?SimulationDate $date = null): array
    {
        $id = $this->id($playerId);
        $repository = new PlayerFinanceRepository($database, false);
        $state = $repository->state($id);
        $totals = $repository->totals($id);
        $contracts = new ContractRepository($database);
        $contract = $contracts->activeForPlayer($id);
        $ownership = $repository->ownership($id);
        $ownedIds = array_fill_keys(array_map(static fn (array $row): string => $row['item_id'], $ownership), true);
        $owned = [];
        foreach ($ownership as $row) {
            $item = LifestyleCatalog::find($row['item_id']);
            $owned[] = $item === null ? $row : array_merge($item, $row);
        }

        return [
            'currency' => self::CURRENCY,
            'balance' => $state['balance'] ?? 0,
            'initialized' => $state !== null,
            'initialized_date' => $state['initialized_date'] ?? null,
            'last_payroll_date' => $state['last_payroll_date'] ?? null,
            'current_wage' => $contract?->wage(),
            'payroll_period' => self::PAYROLL_PERIOD,
            'income' => $totals['income'],
            'wage_income' => $totals['wage_income'],
            'spending' => $totals['spending'],
            'owned_ids' => $ownedIds,
            'owned' => $owned,
            'transactions' => $repository->transactions($id, 20),
        ];
    }

    /** Initialize legacy state at feature activation, then pay only newly due periods. */
    public function processControlledPayroll(DatabaseInterface $database, SimulationDate $date): int
    {
        $connection = $database->connection();
        $table = $connection->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'career_player_references'")->fetchColumn();
        if ($table === false) {
            return 0;
        }
        $players = $connection->query('SELECT player_id FROM career_player_references ORDER BY player_id ASC')->fetchAll(\PDO::FETCH_COLUMN);
        $paid = 0;
        foreach ($players as $playerId) {
            $paid += $this->processPayroll($database, (string) $playerId, $date);
        }

        return $paid;
    }

    public function processPayroll(DatabaseInterface $database, PlayerId|string $playerId, SimulationDate $date): int
    {
        $id = $this->id($playerId);
        $repository = new PlayerFinanceRepository($database, true);
        return $database->transaction(function () use ($database, $repository, $id, $date): int {
            $state = $repository->state($id);
            if ($state === null) {
                $this->initializeInTransaction($database, $id, $date);
                return 0;
            }
            $last = SimulationDate::fromIsoString($state['last_payroll_date']);
            if (!$last->isBefore($date)) {
                return 0;
            }
            $contracts = (new ContractRepository($database))->byPlayer($id);
            $cursor = $last->addDays(7);
            $paid = 0;
            while (!$cursor->isAfter($date)) {
                $contract = null;
                foreach ($contracts as $candidate) {
                    if (!$candidate->startDate()->isAfter($cursor) && !$candidate->endDate()->isBefore($cursor)) {
                        if ($contract === null || $candidate->startDate()->isAfter($contract->startDate())) {
                            $contract = $candidate;
                        }
                    }
                }
                if ($contract !== null && $contract->wage() > 0) {
                    $this->recordInTransaction($database, $id, $cursor, 'wage', $contract->wage(), 'wage:' . $contract->id()->value() . ':' . $cursor->toIsoString(), 'Contract wage');
                    ++$paid;
                }
                $cursor = $cursor->addDays(7);
            }
            $current = $repository->state($id);
            $repository->saveStateInTransaction($id, (int) ($current['balance'] ?? $state['balance']), $state['initialized_date'], $date->toIsoString());

            return $paid;
        });
    }

    /** @return array<string, int|string> */
    public function purchase(DatabaseInterface $database, PlayerId|string $playerId, string $itemId, SimulationDate $date): array
    {
        $id = $this->id($playerId);
        $item = LifestyleCatalog::find($itemId);
        if ($item === null) {
            throw new RuntimeException('That lifestyle item is unavailable.');
        }
        $repository = new PlayerFinanceRepository($database, true);

        return $database->transaction(function () use ($database, $repository, $id, $item, $date): array {
            if ($repository->state($id) === null) {
                $this->initializeInTransaction($database, $id, $date);
            }
            if ($repository->ownershipExistsInTransaction($id, (string) $item['id'])) {
                throw new RuntimeException('You already own this item.');
            }
            $state = $repository->state($id);
            $balance = (int) ($state['balance'] ?? 0);
            $price = (int) $item['price'];
            if ($balance < $price) {
                throw new RuntimeException('That item is not affordable yet.');
            }
            $transaction = $this->recordInTransaction($database, $id, $date, 'purchase', -$price, 'purchase:' . $item['id'], 'Purchased ' . $item['label']);
            $after = (int) $transaction['balance_after'];
            $repository->saveOwnershipInTransaction($id, (string) $item['id'], $date->toIsoString(), $price);

            return ['item_id' => (string) $item['id'], 'price' => $price, 'balance' => $after];
        });
    }

    /** @return array<string, int|string> */
    public function applyEventEffectInTransaction(DatabaseInterface $database, PlayerId|string $playerId, SimulationDate $date, string $sourceId, int $amount, string $type, string $context): array
    {
        $id = $this->id($playerId);
        $repository = new PlayerFinanceRepository($database);
        if ($repository->state($id) === null) {
            $this->initializeInTransaction($database, $id, $date);
        }
        $state = $repository->state($id);
        if ($amount < 0 && (int) ($state['balance'] ?? 0) + $amount < 0) {
            throw new RuntimeException('That choice would exceed the available balance.');
        }

        return $this->recordInTransaction($database, $id, $date, $type, $amount, $sourceId, $context);
    }

    /** @return array<string, int|string> */
    private function recordInTransaction(DatabaseInterface $database, string $playerId, SimulationDate $date, string $type, int $amount, string $sourceId, string $context): array
    {
        $repository = new PlayerFinanceRepository($database);
        $existing = $repository->transactionBySourceInTransaction($playerId, $sourceId);
        if ($existing !== null) {
            return array_map(static fn ($value): int|string => is_numeric($value) && !is_string($value) ? (int) $value : (string) $value, $existing);
        }
        $state = $repository->state($playerId);
        if ($state === null) {
            throw new RuntimeException('Finance state is not initialized.');
        }
        $before = (int) $state['balance'];
        $after = $before + $amount;
        if ($after < 0) {
            throw new RuntimeException('The available balance cannot become negative.');
        }
        $transactionId = hash('sha256', 'finance-transaction|' . $playerId . '|' . $sourceId);
        $repository->insertTransactionInTransaction([
            'id' => $transactionId,
            'player_id' => $playerId,
            'occurred_date' => $date->toIsoString(),
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
            'source_id' => $sourceId,
            'context' => $context,
        ]);
        $repository->saveStateInTransaction($playerId, $after, (string) $state['initialized_date'], (string) $state['last_payroll_date']);

        return ['id' => $transactionId, 'amount' => $amount, 'balance_after' => $after, 'source_id' => $sourceId, 'context' => $context];
    }

    private function id(PlayerId|string $playerId): string
    {
        return $playerId instanceof PlayerId ? $playerId->value() : $playerId;
    }
}
