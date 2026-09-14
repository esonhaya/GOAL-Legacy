<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Core;

use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use Goal\Legacy\Core\Persistence\SqlProfiler;
use Goal\Legacy\Core\Persistence\SqliteDatabase;
use Goal\Legacy\Core\Persistence\SqliteQueryPlanExplainer;
use PHPUnit\Framework\TestCase;

final class SqlProfilerTest extends TestCase
{
    public function testPreparedStatementsNormalizeAndAggregateWithTransactionMetrics(): void
    {
        $profiler = new SqlProfiler();
        $database = new SqliteDatabase(':memory:', $profiler);
        $database->connection()->exec('CREATE TABLE profiler_probe (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');

        $database->transaction(static function (\PDO $connection): void {
            $statement = $connection->prepare(" INSERT INTO profiler_probe (value)\nVALUES (:value); ");
            $statement->execute(['value' => 'one']);
            $statement->execute(['value' => 'two']);
        });

        $snapshot = $profiler->snapshot();
        $queries = array_values(array_filter($snapshot['queries'], static fn (array $query): bool => str_starts_with((string) $query['fingerprint'], 'INSERT INTO profiler_probe')));
        self::assertCount(1, $queries);
        self::assertSame(2, $queries[0]['calls']);
        self::assertGreaterThan(0.0, $queries[0]['total_ms']);
        self::assertSame(1, $snapshot['transaction_count']);
        self::assertSame(1, $snapshot['commit_count']);
        self::assertSame(['value' => 'one'], $queries[0]['example_params']);
    }

    public function testFailedStatementIsProfiledAndExplainIdentifiesIndex(): void
    {
        $profiler = new SqlProfiler();
        $database = new SqliteDatabase(':memory:', $profiler);
        $database->connection()->exec('CREATE TABLE profiler_plan (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $database->connection()->exec('CREATE INDEX profiler_plan_value ON profiler_plan (value)');
        $database->connection()->exec("INSERT INTO profiler_plan (value) VALUES ('x')");

        $statement = $database->connection()->prepare('SELECT id FROM profiler_plan WHERE value = :value');
        $statement->execute(['value' => 'x']);
        try {
            $database->connection()->prepare('INSERT INTO profiler_plan (id, value) VALUES (:id, :value)')->execute(['id' => 1, 'value' => 'duplicate']);
            self::fail('Expected the invalid query to fail.');
        } catch (\PDOException) {
            // The profiler must account for the failed execution without hiding it.
        }

        $plans = (new SqliteQueryPlanExplainer())->explain($database->connection(), $profiler);
        $plan = array_values(array_filter($plans, static fn (array $value): bool => str_starts_with($value['fingerprint'], 'SELECT id FROM profiler_plan')));
        self::assertCount(1, $plan);
        self::assertTrue($plan[0]['index_used']);
        self::assertNotEmpty($plan[0]['plan']);
        $failed = array_values(array_filter($profiler->snapshot()['queries'], static fn (array $query): bool => str_starts_with((string) $query['fingerprint'], 'INSERT INTO profiler_plan (id, value)')));
        self::assertSame(1, $failed[0]['failed_calls']);
    }

    public function testSchemaSetupRunsOncePerConnection(): void
    {
        $profiler = new SqlProfiler();
        $database = new SqliteDatabase(':memory:', $profiler);
        SchemaInitializationGuard::run($database->connection(), 'probe', function (): void {
            // The guard is intentionally independent of any football module.
        });
        SchemaInitializationGuard::run($database->connection(), 'probe', function (): void {
            throw new \RuntimeException('The guarded initializer ran twice.');
        });

        self::assertTrue(true);
    }
}
