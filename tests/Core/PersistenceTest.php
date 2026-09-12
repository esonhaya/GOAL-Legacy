<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Core;

use DateTimeImmutable;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\PersistenceException;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteDatabase;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Core\Time\SimulationTime;
use PHPUnit\Framework\TestCase;

final class PersistenceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is unavailable.');
        }

        $this->directory = sys_get_temp_dir() . '/goal-legacy-persistence-test-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!isset($this->directory) || !is_dir($this->directory)) {
            return;
        }

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->directory);
    }

    public function testSqliteConnectionUsesExceptionsAndForeignKeys(): void
    {
        $database = new SqliteDatabase(':memory:');

        self::assertSame(\PDO::ERRMODE_EXCEPTION, $database->connection()->getAttribute(\PDO::ATTR_ERRMODE));
        self::assertSame(1, (int) $database->connection()->query('PRAGMA foreign_keys')->fetchColumn());
    }

    public function testSaveCreateExistsOpenListAndSimulationTimeRoundTrip(): void
    {
        $store = new SqliteSaveStore($this->directory, new JsonSerializer());
        $metadata = SaveMetadata::create(
            'career_one',
            'Test Career',
            new SimulationTime(42),
            new DateTimeImmutable('@100'),
        );

        $store->create($metadata);

        self::assertTrue($store->exists('career_one'));
        self::assertInstanceOf(SqliteDatabase::class, $store->openDatabase('career_one'));
        self::assertSame($metadata->toArray(), $store->open('career_one')->toArray());
        self::assertCount(1, $store->list());

        $this->expectException(PersistenceException::class);
        $store->create($metadata);
    }

    public function testSaveIdsAreControlledAndCannotTraversePaths(): void
    {
        $store = new SqliteSaveStore($this->directory, new JsonSerializer());

        $this->expectException(PersistenceException::class);
        $store->exists('../outside');
    }

    public function testJsonSerializationIsDeterministicAndRejectsMalformedData(): void
    {
        $serializer = new JsonSerializer();
        $first = $serializer->encode(['z' => 2, 'a' => ['b' => 1, 'a' => 0]]);
        $second = $serializer->encode(['a' => ['a' => 0, 'b' => 1], 'z' => 2]);

        self::assertSame($first, $second);
        self::assertSame(['a' => ['a' => 0, 'b' => 1], 'z' => 2], $serializer->decode($first));

        $this->expectException(PersistenceException::class);
        $serializer->decode('{malformed');
    }

    public function testTransactionsCommitRollbackAndRejectNesting(): void
    {
        $database = new SqliteDatabase(':memory:');
        $database->connection()->exec('CREATE TABLE values_probe (value INTEGER NOT NULL)');

        $database->transaction(static function (\PDO $connection): void {
            $connection->exec('INSERT INTO values_probe (value) VALUES (1)');
        });
        self::assertSame(1, (int) $database->connection()->query('SELECT COUNT(*) FROM values_probe')->fetchColumn());

        try {
            $database->transaction(static function (\PDO $connection): void {
                $connection->exec('INSERT INTO values_probe (value) VALUES (2)');
                throw new \RuntimeException('rollback');
            });
            self::fail('Expected transaction callback to throw.');
        } catch (\RuntimeException $exception) {
            self::assertSame('rollback', $exception->getMessage());
        }
        self::assertSame(1, (int) $database->connection()->query('SELECT COUNT(*) FROM values_probe')->fetchColumn());

        try {
            $database->transaction(function () use ($database): void {
                $database->transaction(static function (): void {});
            });
            self::fail('Expected nested transaction to be rejected.');
        } catch (PersistenceException $exception) {
            self::assertStringContainsString('Nested', $exception->getMessage());
        }
    }

    public function testUnsupportedAndMalformedSaveDataFailClearly(): void
    {
        $serializer = new JsonSerializer();
        $store = new SqliteSaveStore($this->directory, $serializer);
        $metadata = SaveMetadata::create('broken', 'Broken Save', new SimulationTime(1), new DateTimeImmutable('@0'));
        $store->create($metadata);
        $database = new SqliteDatabase($this->directory . '/broken.sqlite');

        $statement = $database->connection()->prepare('UPDATE core_save_metadata SET payload = :payload WHERE id = :id');
        $statement->execute(['id' => 'broken', 'payload' => '{broken']);
        try {
            $store->open('broken');
            self::fail('Expected malformed save data to fail.');
        } catch (PersistenceException $exception) {
            self::assertStringContainsString('malformed JSON', $exception->getMessage());
        }

        $statement->execute([
            'id' => 'broken',
            'payload' => $serializer->encode(['format_version' => 2, 'metadata' => $metadata->toArray()]),
        ]);
        $this->expectException(PersistenceException::class);
        $store->open('broken');
    }
}
