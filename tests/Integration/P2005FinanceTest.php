<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\SqliteDatabase;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Contract\ContractService;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Finance\LifestyleCatalog;
use Goal\Legacy\Modules\Player\Finance\PlayerFinanceRepository;
use Goal\Legacy\Modules\Player\Finance\PlayerFinanceService;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class P2005FinanceTest extends TestCase
{
    public function testCatalogIsBoundedAndValid(): void
    {
        self::assertCount(20, LifestyleCatalog::all());
        self::assertSame([], LifestyleCatalog::validate());
    }

    public function testOpeningBalanceWeeklyPayrollPurchaseAndReplayProtection(): void
    {
        $database = new SqliteDatabase(':memory:');
        $database->connection()->exec('CREATE TABLE player_records (id TEXT PRIMARY KEY, career_state TEXT NOT NULL)');
        $database->connection()->exec('CREATE TABLE club_records (id TEXT PRIMARY KEY)');
        $database->connection()->exec("INSERT INTO player_records (id, career_state) VALUES ('controlled-player', 'active')");
        $database->connection()->exec("INSERT INTO club_records (id) VALUES ('career-club')");
        $contracts = new ContractService();
        $playerId = new PlayerId('controlled-player');
        $contracts->save($database, $contracts->create(new ContractCreationRequest(
            new ContractId('controlled-contract'),
            $playerId,
            new ClubId('career-club'),
            SimulationDate::fromIsoString('2024-07-31'),
            SimulationDate::fromIsoString('2025-06-30'),
            10,
            SimulationDate::fromIsoString('2024-07-31'),
        )));
        $finance = new PlayerFinanceService();
        $finance->initializeInTransaction($database, $playerId, SimulationDate::fromIsoString('2024-07-31'));
        self::assertSame(50, $finance->summary($database, $playerId)['balance']);
        self::assertSame(2, $finance->processPayroll($database, $playerId, SimulationDate::fromIsoString('2024-08-14')));
        self::assertSame(70, $finance->summary($database, $playerId)['balance']);
        self::assertSame(0, $finance->processPayroll($database, $playerId, SimulationDate::fromIsoString('2024-08-14')));
        self::assertSame(70, $finance->summary($database, $playerId)['balance']);

        $purchase = $finance->purchase($database, $playerId, 'transport.bicycle', SimulationDate::fromIsoString('2024-08-14'));
        self::assertSame(30, $purchase['price']);
        self::assertSame(40, $purchase['balance']);
        self::assertCount(1, $finance->summary($database, $playerId)['owned']);
        try {
            $finance->purchase($database, $playerId, 'transport.bicycle', SimulationDate::fromIsoString('2024-08-14'));
            self::fail('A unique lifestyle item must not be purchased twice.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(40, $finance->summary($database, $playerId)['balance']);
        self::assertCount(4, (new PlayerFinanceRepository($database))->transactions($playerId->value()));
    }

    public function testNpcPlayersDoNotReceivePersonalFinanceRows(): void
    {
        $database = new SqliteDatabase(':memory:');
        $database->connection()->exec('CREATE TABLE career_player_references (career_id TEXT PRIMARY KEY, player_id TEXT NOT NULL)');
        $database->connection()->exec("INSERT INTO career_player_references (career_id, player_id) VALUES ('career', 'controlled-player')");
        $finance = new PlayerFinanceService();
        $finance->processControlledPayroll($database, SimulationDate::fromIsoString('2024-08-01'));
        $tables = $database->connection()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'player_%finance%'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains(PlayerFinanceRepository::STATE_TABLE, $tables);
        self::assertNotContains('npc_finance_state', $tables);
    }

}
