<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\OwnedArtifactCleanup;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Core\Persistence\StorageInventory;
use Goal\Legacy\Core\Persistence\StorageMaintenanceException;
use Goal\Legacy\Core\Time\SimulationTime;
use Goal\Legacy\Web\WebApplication;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class P4001StorageFoundationTest extends TestCase
{
    private string $root;
    private SqliteSaveStore $store;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/goal-legacy-p4-001-test-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/saves', 0775, true) || !mkdir($this->root . '/temp', 0775, true)) {
            throw new RuntimeException('Unable to create P4-001 isolated storage fixture.');
        }
        $this->store = new SqliteSaveStore($this->root . '/saves', new JsonSerializer());
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testInventoryAttributionAndOwnedCleanupProtectUnknownFiles(): void
    {
        $this->createSave('normal-save', 'account-a');
        $this->createSave('sandbox-save', 'account-a', 'normal-save');
        file_put_contents($this->root . '/saves/unknown.sqlite', 'not a GOAL save');
        file_put_contents($this->root . '/saves/normal-save.sqlite.backup', 'keep this nearby file');

        $ownedTemp = $this->root . '/temp/goal-legacy-p4-001-abandoned';
        mkdir($ownedTemp, 0775, true);
        file_put_contents($ownedTemp . '/owned.sqlite', str_repeat('x', 64));
        file_put_contents($this->root . '/temp/not-owned.sqlite', 'keep this external file');

        $inventory = new StorageInventory($this->store->storageDirectory(), $this->root . '/temp', $this->store);
        $report = $inventory->report();

        self::assertSame(1, $report['counts']['normal_save_count']);
        self::assertSame(1, $report['counts']['sandbox_save_count']);
        self::assertSame(1, $report['counts']['owned_temp_count']);
        self::assertSame(2, $report['counts']['unknown_file_count']);
        self::assertGreaterThan(0, $report['bytes']['total_owned_bytes']);
        self::assertGreaterThan(0, $report['bytes']['unknown_bytes']);

        $attribution = $inventory->attribution('normal-save');
        self::assertGreaterThan(0, $attribution['file_size_bytes']);
        self::assertGreaterThan(0, $attribution['page_size']);
        self::assertGreaterThan(0, $attribution['page_count']);
        self::assertContains($attribution['dbstat'], ['AVAILABLE', 'UNAVAILABLE']);
        $metadataTable = array_values(array_filter($attribution['tables'], static fn (array $table): bool => $table['table_name'] === 'core_save_metadata'));
        self::assertCount(1, $metadataTable);
        self::assertSame(1, $metadataTable[0]['row_count']);
        self::assertArrayHasKey('retention_class', $metadataTable[0]);

        $cleanup = (new OwnedArtifactCleanup())->cleanupTemporaryDirectories($this->root . '/temp', 0);
        self::assertCount(1, $cleanup['removed']);
        self::assertSame([], $cleanup['failures']);
        self::assertFileDoesNotExist($ownedTemp . '/owned.sqlite');
        self::assertFileExists($this->root . '/temp/not-owned.sqlite');
        self::assertFileExists($this->root . '/saves/unknown.sqlite');
        self::assertFileExists($this->root . '/saves/normal-save.sqlite.backup');

        $repeat = (new OwnedArtifactCleanup())->cleanupTemporaryDirectories($this->root . '/temp', 0);
        self::assertSame([], $repeat['removed']);
        self::assertFileExists($this->root . '/saves/normal-save.sqlite');
        self::assertFileExists($this->root . '/saves/sandbox-save.sqlite');
    }

    public function testProductionDeleteIsConfirmedPostOnlyOwnedAndIsolated(): void
    {
        $this->createSave('delete-own', 'account-a');
        $this->createSave('delete-other', 'account-b');
        $this->createSave('delete-source', 'account-a');
        $this->createSave('delete-sandbox', 'account-a', 'delete-source');
        file_put_contents($this->root . '/saves/delete-own.sqlite.p4-compaction-test', 'owned sidecar');
        file_put_contents($this->root . '/saves/delete-own.sqlite.backup', 'unknown nearby file');

        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $application = new WebApplication($services, dirname(__DIR__, 2), $this->store);
        $ownerSession = ['account_id' => 'account-a'];

        $sizeBeforeMenu = filesize($this->root . '/saves/delete-own.sqlite');
        $menu = $application->handle('GET', '/', ['page' => 'menu'], [], $ownerSession);
        self::assertSame(200, $menu['status']);
        self::assertStringContainsString('Last updated:', $menu['body']);
        self::assertStringContainsString('Size:', $menu['body']);
        self::assertStringContainsString('Optimize Storage', $menu['body']);
        self::assertStringContainsString('Delete Career', $menu['body']);
        self::assertSame($sizeBeforeMenu, filesize($this->root . '/saves/delete-own.sqlite'));
        $optimizeToken = $this->tokenFor($menu, 'optimize_storage');
        self::assertNotNull($optimizeToken);
        $optimized = $application->handle('POST', '/', [], [
            'action' => 'optimize_storage',
            'save' => 'delete-own',
            'token' => $optimizeToken,
        ], $ownerSession);
        self::assertSame(303, $optimized['status']);

        $withoutConfirmation = $application->handle('POST', '/', [], ['action' => 'delete_save', 'save' => 'delete-own', 'confirm' => '1'], $ownerSession);
        self::assertSame(303, $withoutConfirmation['status']);
        self::assertFileExists($this->root . '/saves/delete-own.sqlite');

        $confirmation = $application->handle('GET', '/', ['page' => 'delete', 'save' => 'delete-own'], [], $ownerSession);
        self::assertSame(200, $confirmation['status']);
        self::assertStringContainsString('irreversible', strtolower($confirmation['body']));
        $token = $this->tokenFor($confirmation, 'delete_save');
        self::assertNotNull($token);

        $deleted = $application->handle('POST', '/', [], [
            'action' => 'delete_save',
            'save' => 'delete-own',
            'confirm' => '1',
            'token' => $token,
        ], $ownerSession);
        self::assertSame(303, $deleted['status']);
        self::assertFileDoesNotExist($this->root . '/saves/delete-own.sqlite');
        self::assertFileDoesNotExist($this->root . '/saves/delete-own.sqlite.p4-compaction-test');
        self::assertFileExists($this->root . '/saves/delete-own.sqlite.backup');
        self::assertFileExists($this->root . '/saves/delete-other.sqlite');
        self::assertFileExists($this->root . '/saves/delete-sandbox.sqlite');

        // The same one-use submission cannot delete anything a second time.
        $doubleSubmit = $application->handle('POST', '/', [], [
            'action' => 'delete_save',
            'save' => 'delete-own',
            'confirm' => '1',
            'token' => $token,
        ], $ownerSession);
        self::assertSame(303, $doubleSubmit['status']);

        $foreignSession = ['account_id' => 'account-a'];
        $foreign = $application->handle('GET', '/', ['page' => 'delete', 'save' => 'delete-other'], [], $foreignSession);
        self::assertSame(303, $foreign['status']);
        self::assertFileExists($this->root . '/saves/delete-other.sqlite');
        $foreignCompact = $application->handle('POST', '/', [], [
            'action' => 'optimize_storage',
            'save' => 'delete-other',
            'token' => 'tampered',
        ], $foreignSession);
        self::assertSame(303, $foreignCompact['status']);
        self::assertFileExists($this->root . '/saves/delete-other.sqlite');

        $pathAttempt = $application->handle('POST', '/', [], [
            'action' => 'delete_save',
            'save' => '../delete-other',
            'confirm' => '1',
            'token' => 'tampered',
        ], $ownerSession);
        self::assertSame(303, $pathAttempt['status']);
        self::assertFileExists($this->root . '/saves/delete-other.sqlite');

        // GET action endpoints cannot invoke deletion, and a source Career is
        // independent from its Sandbox child.
        $getAttempt = $application->handle('GET', '/', ['page' => 'action', 'save' => 'delete-source'], [], $ownerSession);
        self::assertSame(303, $getAttempt['status']);
        self::assertFileExists($this->root . '/saves/delete-source.sqlite');

        $sandboxConfirmation = $application->handle('GET', '/', ['page' => 'delete', 'save' => 'delete-sandbox'], [], $ownerSession);
        $sandboxToken = $this->tokenFor($sandboxConfirmation, 'delete_save');
        self::assertNotNull($sandboxToken);
        $application->handle('POST', '/', [], [
            'action' => 'delete_save',
            'save' => 'delete-sandbox',
            'confirm' => '1',
            'token' => $sandboxToken,
        ], $ownerSession);
        self::assertFileDoesNotExist($this->root . '/saves/delete-sandbox.sqlite');
        self::assertFileExists($this->root . '/saves/delete-source.sqlite');
    }

    public function testExplicitVacuumReclaimsPagesAndPreservesCanonicalState(): void
    {
        $this->createSave('compact-save', 'account-a');
        $database = $this->store->openDatabase('compact-save');
        $connection = $database->connection();
        $connection->exec('CREATE TABLE p4_compaction_fixture (id INTEGER PRIMARY KEY, payload BLOB NOT NULL)');
        $insert = $connection->prepare('INSERT INTO p4_compaction_fixture (payload) VALUES (:payload)');
        for ($index = 0; $index < 240; ++$index) {
            $insert->execute(['payload' => str_repeat(chr(65 + ($index % 26)), 16384)]);
        }
        $connection->exec('DELETE FROM p4_compaction_fixture');
        unset($connection, $database);

        $beforeState = [
            'metadata' => $this->store->open('compact-save')->toArray(),
            'fixture_rows' => 0,
        ];
        $result = $this->store->compact('compact-save', function (DatabaseInterface $database): array {
            return [
                'metadata' => $this->store->open('compact-save')->toArray(),
                'fixture_rows' => (int) $database->connection()->query('SELECT COUNT(*) FROM p4_compaction_fixture')->fetchColumn(),
            ];
        });
        $before = $result['before'];
        $after = $result['after'];

        self::assertGreaterThan(0, $before['freelist_count']);
        self::assertLessThan($before['file_size_bytes'], $after['file_size_bytes']);
        self::assertSame('ok', $before['integrity_check']);
        self::assertSame('ok', $after['integrity_check']);
        self::assertSame(0, $before['foreign_key_violations']);
        self::assertSame(0, $after['foreign_key_violations']);
        self::assertGreaterThan(0, $result['bytes_reclaimed']);
        self::assertSame('PRESERVED', $result['semantic_checkpoint']);
        self::assertSame($beforeState['metadata'], $this->store->open('compact-save')->toArray());

        $reopened = $this->store->openDatabase('compact-save');
        self::assertSame('ok', $reopened->connection()->query('PRAGMA integrity_check')->fetchColumn());
        self::assertCount(0, $reopened->connection()->query('PRAGMA foreign_key_check')->fetchAll());
        self::assertSame(0, (int) $reopened->connection()->query('SELECT COUNT(*) FROM p4_compaction_fixture')->fetchColumn());
        unset($reopened);

        $repeat = $this->store->compact('compact-save');
        self::assertGreaterThanOrEqual(0, $repeat['bytes_reclaimed']);
        self::assertSame('ok', $repeat['after']['integrity_check']);

        file_put_contents($this->root . '/saves/unknown.sqlite', 'not a save');
        $this->expectException(StorageMaintenanceException::class);
        $this->store->compact('unknown');
    }

    private function createSave(string $id, string $owner, ?string $sourceId = null): void
    {
        $metadata = SaveMetadata::create($id, 'P4-001 ' . $id, new SimulationTime(0), new DateTimeImmutable('@0'), $owner);
        if ($sourceId !== null) {
            $metadata = $metadata->asSandbox($sourceId, new DateTimeImmutable('@0'));
        }
        $this->store->create($metadata);
    }

    /** @param array{body:string} $response */
    private function tokenFor(array $response, string $action): ?string
    {
        $pattern = '/<input type="hidden" name="action" value="' . preg_quote($action, '/') . '">.*?<input type="hidden" name="token" value="([^"]+)"/s';
        if (preg_match($pattern, $response['body'], $matches) !== 1) {
            return null;
        }

        return html_entity_decode((string) ($matches[1] ?? ''), ENT_QUOTES | ENT_HTML5);
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } elseif (is_file($path) || is_link($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
