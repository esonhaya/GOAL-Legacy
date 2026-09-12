<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Nation;

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Content\ContentPackageCatalog;
use Goal\Legacy\Core\Content\ContentPackageDiscovery;
use Goal\Legacy\Core\Persistence\SqliteDatabase;
use Goal\Legacy\Modules\Nation\Content\NationContentLoader;
use Goal\Legacy\Modules\Nation\Domain\Nation;
use Goal\Legacy\Modules\Nation\Domain\NationException;
use Goal\Legacy\Modules\Nation\Domain\NationId;
use Goal\Legacy\Modules\Nation\Domain\NationNotFoundException;
use Goal\Legacy\Modules\Nation\Persistence\NationMaterializer;
use Goal\Legacy\Modules\Nation\Persistence\NationRepository;
use Goal\Legacy\Modules\Nation\NationModule;
use PHPUnit\Framework\TestCase;

final class NationTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryRoots = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is unavailable.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryRoots as $root) {
            $this->removeTree($root);
        }
    }

    public function testNationIdentityIsImmutableAndValidatesStableId(): void
    {
        $nation = $this->nation('england');

        self::assertSame('england', $nation->id()->value());
        self::assertSame('England', $nation->canonicalName());
        self::assertSame('ENG', $nation->code());
        self::assertSame($nation->toArray(), $nation->toArray());

        $this->expectException(\InvalidArgumentException::class);
        new NationId('../outside');
    }

    public function testSelectedNationContentLoadsDeterministicallyWithProvenance(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();

        self::assertSame(['england', 'france', 'germany', 'italy', 'scotland', 'spain', 'wales'], array_map(
            static fn (Nation $nation): string => $nation->id()->value(),
            $nations,
        ));
        self::assertSame('core-nations', $nations[0]->sourcePackageId());
        self::assertSame('1.0.0', $nations[0]->sourcePackageVersion());
        self::assertSame(1, $nations[0]->sourceSchemaVersion());
    }

    public function testNationContentRejectsMissingFieldsAndDuplicateIds(): void
    {
        $root = $this->temporaryRoot();
        $this->writeNationPackage($root, 'invalid', [
            ['id' => 'missing-name'],
        ]);
        $this->expectException(NationException::class);
        (new NationContentLoader())->loadSelected($this->catalog($root, ['invalid']));
    }

    public function testSelectedNationContentRejectsDuplicateIdsAcrossPackages(): void
    {
        $root = $this->temporaryRoot();
        $record = ['id' => 'same', 'canonical_name' => 'Same', 'display_name' => 'Same'];
        $this->writeNationPackage($root, 'one', [$record]);
        $this->writeNationPackage($root, 'two', [$record]);

        $this->expectException(NationException::class);
        (new NationContentLoader())->loadSelected($this->catalog($root, ['one', 'two']));
    }

    public function testRepositoryRoundTripsSortedNationsAndSupportsUpsert(): void
    {
        $database = new SqliteDatabase(':memory:');
        $repository = new NationRepository($database);
        $repository->save($this->nation('spain', 'Spain'));
        $repository->save($this->nation('england', 'England'));

        self::assertTrue($repository->exists('england'));
        self::assertSame(['england', 'spain'], array_map(
            static fn (Nation $nation): string => $nation->id()->value(),
            $repository->all(),
        ));
        self::assertSame('Spain', $repository->get('spain')->displayName());

        $repository->save($this->nation('spain', 'España'));
        self::assertSame('España', $repository->get('spain')->displayName());
        self::assertNull($repository->find('missing'));

        $this->expectException(NationNotFoundException::class);
        $repository->get('missing');
    }

    public function testMaterializationIsSortedTransactionalAndIdempotent(): void
    {
        $database = new SqliteDatabase(':memory:');
        $materializer = new NationMaterializer($database);
        $nations = [$this->nation('wales'), $this->nation('england')];

        self::assertSame(2, $materializer->materialize($nations));
        self::assertSame(2, $materializer->materialize($nations));
        self::assertSame(['england', 'wales'], array_map(
            static fn (Nation $nation): string => $nation->id()->value(),
            (new NationRepository($database))->all(),
        ));
    }

    public function testBootstrapRegistersNationModuleAndSupportsDisablement(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        self::assertInstanceOf(NationModule::class, $services->moduleRegistry()->get('nation'));
        self::assertTrue($services->moduleRegistry()->isEnabled('nation'));
        $services->moduleRegistry()->start();
        self::assertSame('started', $services->moduleRegistry()->statuses()[0]->state()->value);
        $services->moduleRegistry()->shutdown();

        $disabled = (new Bootstrap())->create($root, [
            'APP_ENV' => 'test',
            'APP_MODULE_NATION_ENABLED' => 'false',
            'APP_MODULE_COMPETITION_ENABLED' => 'false',
            'APP_MODULE_WORLD_ENABLED' => 'false',
        ]);
        self::assertFalse($disabled->moduleRegistry()->isEnabled('nation'));
        $disabled->moduleRegistry()->start();
        self::assertSame('registered', $disabled->moduleRegistry()->statuses()[0]->state()->value);
    }

    private function nation(string $id, ?string $displayName = null): Nation
    {
        return new Nation(
            new NationId($id),
            $displayName ?? ucfirst($id),
            $displayName ?? ucfirst($id),
            strtoupper(substr($id, 0, 3)),
            'europe',
            $id,
            'association-' . $id,
            'test-package',
            '1.0.0',
            1,
        );
    }

    private function temporaryRoot(): string
    {
        $root = sys_get_temp_dir() . '/goal-legacy-nation-test-' . bin2hex(random_bytes(8));
        mkdir($root, 0775, true);
        $this->temporaryRoots[] = $root;

        return $root;
    }

    /** @param list<array<string, string>> $records */
    private function writeNationPackage(string $root, string $id, array $records): void
    {
        $directory = $root . '/' . $id;
        mkdir($directory, 0775, true);
        file_put_contents($directory . '/manifest.json', json_encode([
            'id' => $id,
            'name' => $id,
            'version' => '1.0.0',
            'schema_version' => 1,
            'files' => ['nations.json'],
            'metadata' => ['content_domain' => 'nation'],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($directory . '/nations.json', json_encode([
            'schema_version' => 1,
            'nations' => $records,
        ], JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $selected */
    private function catalog(string $root, array $selected): ContentPackageCatalog
    {
        return new ContentPackageCatalog((new ContentPackageDiscovery($root))->discover(), $selected);
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (glob($directory . '/*') ?: [] as $path) {
            if (is_dir($path)) {
                $this->removeTree($path);
                continue;
            }
            unlink($path);
        }
        rmdir($directory);
    }
}
