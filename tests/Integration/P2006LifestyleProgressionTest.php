<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use Goal\Legacy\Core\Persistence\SqliteDatabase;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Contract\ContractService;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\CareerEventCatalog;
use Goal\Legacy\Modules\Player\Finance\LifestyleCatalog;
use Goal\Legacy\Modules\Player\Finance\PlayerFinanceRepository;
use Goal\Legacy\Modules\Player\Finance\PlayerFinanceService;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PHPUnit\Framework\TestCase;

final class P2006LifestyleProgressionTest extends TestCase
{
    public function testFinancialContextMovesWithIncomeBalanceAndAssets(): void
    {
        $finance = new PlayerFinanceService();
        self::assertSame('starting_out', $finance->contextFromSummary(['balance' => 50, 'current_wage' => 10, 'wage_income' => 0, 'owned' => []])['code']);
        self::assertSame('comfortable', $finance->contextFromSummary(['balance' => 900, 'current_wage' => 500, 'wage_income' => 2500, 'owned' => []])['code']);
        self::assertSame('elite', $finance->contextFromSummary(['balance' => 3000, 'current_wage' => 1600, 'wage_income' => 10000, 'owned' => [['tier' => 'Elite']]])['code']);
    }

    public function testTieredCatalogAndActiveUpgradeDoNotCreateExtraCharge(): void
    {
        $database = $this->databaseWithContract(500);
        $finance = new PlayerFinanceService();
        $player = new PlayerId('controlled-player');
        $date = SimulationDate::fromIsoString('2024-07-31');
        $finance->initializeInTransaction($database, $player, $date);
        $finance->processPayroll($database, $player, $date->addDays(7));
        $finance->purchase($database, $player, 'transport.bicycle', $date->addDays(7));
        $before = $finance->summary($database, $player);
        $finance->purchase($database, $player, 'transport.scooter', $date->addDays(7));
        $after = $finance->summary($database, $player);
        self::assertSame(2, count($after['owned']));
        self::assertFalse((bool) $after['owned'][0]['active']);
        self::assertTrue((bool) $after['owned'][1]['active']);
        self::assertSame(4, count($after['transactions']));
        $balanceAfterPurchase = $after['balance'];
        $finance->activate($database, $player, 'transport.bicycle');
        $activated = $finance->summary($database, $player);
        self::assertSame($balanceAfterPurchase, $activated['balance']);
        self::assertSame(4, count($activated['transactions']));
        self::assertTrue((bool) $activated['owned'][0]['active']);
        self::assertFalse((bool) $activated['owned'][1]['active']);
        self::assertNotSame($before['balance'], $after['balance']);
    }

    public function testLegacyOwnershipWithoutActiveColumnRemainsReadableAndMigratesOnWrite(): void
    {
        $database = new SqliteDatabase(':memory:');
        $database->connection()->exec('CREATE TABLE player_records (id TEXT PRIMARY KEY, career_state TEXT NOT NULL)');
        $database->connection()->exec("INSERT INTO player_records (id, career_state) VALUES ('controlled-player', 'active')");
        $database->connection()->exec('CREATE TABLE player_finance_state (player_id TEXT PRIMARY KEY, balance INTEGER NOT NULL, initialized_date TEXT NOT NULL, last_payroll_date TEXT NOT NULL)');
        $database->connection()->exec('CREATE TABLE player_lifestyle_ownership (player_id TEXT NOT NULL, item_id TEXT NOT NULL, purchased_date TEXT NOT NULL, price INTEGER NOT NULL, PRIMARY KEY (player_id, item_id))');
        $database->connection()->exec("INSERT INTO player_lifestyle_ownership (player_id, item_id, purchased_date, price) VALUES ('controlled-player', 'transport.bicycle', '2024-07-31', 30)");
        $repository = new PlayerFinanceRepository($database, false);
        self::assertTrue($repository->ownership('controlled-player')[0]['active']);
        new PlayerFinanceRepository($database, true);
        $columns = array_column($database->connection()->query('PRAGMA table_info(player_lifestyle_ownership)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        self::assertContains('active', $columns);
    }

    public function testCatalogProgressionRemainsBoundedAndUsesSafeEffects(): void
    {
        self::assertCount(20, LifestyleCatalog::all());
        self::assertSame([], LifestyleCatalog::validate());
        self::assertSame(30, LifestyleCatalog::find('transport.bicycle')['price']);
        self::assertSame(25000, LifestyleCatalog::find('transport.premium-car')['price']);
        self::assertNull(LifestyleCatalog::activeGroup(LifestyleCatalog::find('experience.family-trip')));
        self::assertSame('Home', LifestyleCatalog::activeGroup(LifestyleCatalog::find('home.city-apartment')));
    }

    public function testFinancialEventContentUsesStableBoundedMoneyChoices(): void
    {
        $events = CareerEventCatalog::all();
        $ids = array_column($events, 'id');
        self::assertContains('lifestyle-wealth-pressure', $ids);
        self::assertContains('lifestyle-relocation-home', $ids);
        self::assertContains('lifestyle-free-agent-caution', $ids);
        foreach ($events as $event) {
            foreach ((array) ($event['choices'] ?? []) as $choice) {
                if (!isset($choice['finance'])) { continue; }
                self::assertIsInt($choice['finance']['amount'] ?? null);
                self::assertLessThanOrEqual(25, abs((int) $choice['finance']['amount']));
                self::assertNotSame('', (string) ($choice['finance']['type'] ?? ''));
            }
        }
    }

    private function databaseWithContract(int $wage): SqliteDatabase
    {
        $database = new SqliteDatabase(':memory:');
        $database->connection()->exec('CREATE TABLE player_records (id TEXT PRIMARY KEY, career_state TEXT NOT NULL)');
        $database->connection()->exec('CREATE TABLE club_records (id TEXT PRIMARY KEY)');
        $database->connection()->exec("INSERT INTO player_records (id, career_state) VALUES ('controlled-player', 'active')");
        $database->connection()->exec("INSERT INTO club_records (id) VALUES ('career-club')");
        $contracts = new ContractService();
        $contracts->save($database, $contracts->create(new ContractCreationRequest(
            new ContractId('controlled-contract'),
            new PlayerId('controlled-player'),
            new ClubId('career-club'),
            SimulationDate::fromIsoString('2024-07-31'),
            SimulationDate::fromIsoString('2025-06-30'),
            $wage,
            SimulationDate::fromIsoString('2024-07-31'),
        )));

        return $database;
    }
}
