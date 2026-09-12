<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Core;

use Goal\Legacy\Core\Content\ContentFileLoader;
use Goal\Legacy\Core\Content\ContentPackage;
use Goal\Legacy\Core\Content\ContentPackageCatalog;
use Goal\Legacy\Core\Content\ContentPackageDiscovery;
use Goal\Legacy\Core\Content\ContentPackageException;
use Goal\Legacy\Core\Content\ContentPackageManifest;
use Goal\Legacy\Core\Content\ContentPackageManifestLoader;
use Goal\Legacy\Core\Content\ContentPackageValidator;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use PHPUnit\Framework\TestCase;

final class ContentPackageTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryRoots as $root) {
            foreach (glob($root . '/*') ?: [] as $child) {
                if (is_dir($child)) {
                    foreach (glob($child . '/*') ?: [] as $file) {
                        if (is_file($file)) {
                            unlink($file);
                        }
                    }
                    rmdir($child);
                } elseif (is_file($child)) {
                    unlink($child);
                }
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

    public function testManifestAndDiscoveryAreVersionedAndDeterministic(): void
    {
        $root = dirname(__DIR__) . '/Fixtures/ContentPackages';
        $packages = (new ContentPackageDiscovery($root))->discover();
        $catalog = new ContentPackageCatalog($packages);

        self::assertSame(['alpha', 'beta', 'gamma'], array_map(
            static fn (ContentPackage $package): string => $package->manifest()->id(),
            $catalog->packages(),
        ));
        self::assertSame(['alpha', 'beta', 'gamma'], array_map(
            static fn (ContentPackage $package): string => $package->manifest()->id(),
            $catalog->resolvedPackages(),
        ));
        self::assertSame(1, $catalog->packages()[0]->manifest()->schemaVersion());
        self::assertSame(['alpha'], $catalog->packages()[1]->manifest()->dependencies());
        self::assertFalse($catalog->isSelected('alpha'));
    }

    public function testContentFileLoaderReadsOnlyPackageRelativeJson(): void
    {
        $package = (new ContentPackageDiscovery(dirname(__DIR__) . '/Fixtures/ContentPackages'))->discover()[0];
        $loader = new ContentFileLoader();

        self::assertSame(['kind' => 'fixture', 'value' => 1], $loader->readJson($package, 'data.json'));

        try {
            $loader->readJson($package, '../outside.json');
            self::fail('Expected traversal to be rejected.');
        } catch (ContentPackageException $exception) {
            self::assertStringContainsString('traverse', $exception->getMessage());
        }

        $this->expectException(ContentPackageException::class);
        $loader->readJson($package, '/etc/passwd');
    }

    public function testSelectionRequiresDiscoveredDependencies(): void
    {
        $packages = (new ContentPackageDiscovery(dirname(__DIR__) . '/Fixtures/ContentPackages'))->discover();
        $catalog = new ContentPackageCatalog($packages);
        $selection = $catalog->select(['beta', 'alpha']);

        self::assertSame(['alpha', 'beta'], $selection->selectedIds());
        self::assertTrue($selection->isSelected('beta'));
        self::assertFalse($selection->isSelected('gamma'));

        $this->expectException(ContentPackageException::class);
        $catalog->select(['beta']);
    }

    public function testMalformedMissingAndUnsupportedManifestsFailClearly(): void
    {
        $root = $this->temporaryRoot();
        $this->writePackage($root, 'malformed', '{not-json');
        try {
            (new ContentPackageDiscovery($root))->discover();
            self::fail('Expected malformed manifest to fail.');
        } catch (ContentPackageException $exception) {
            self::assertStringContainsString('malformed', strtolower($exception->getMessage()));
        }

        $root = $this->temporaryRoot();
        $this->writePackage($root, 'missing', '{"id":"missing","version":"1.0.0","schema_version":1}');
        $this->expectException(ContentPackageException::class);
        (new ContentPackageDiscovery($root))->discover();
    }

    public function testManifestRejectsInvalidIdAndNewerSchema(): void
    {
        $loader = new ContentPackageManifestLoader();
        $root = $this->temporaryRoot();
        $this->writePackage($root, 'invalid', '{"id":"../bad","name":"Bad","version":"1.0.0","schema_version":1}');
        try {
            $loader->load($root . '/invalid/manifest.json');
            self::fail('Expected invalid package ID to fail.');
        } catch (ContentPackageException $exception) {
            self::assertStringContainsString('package IDs', $exception->getMessage());
        }

        $this->writePackage($root, 'newer', '{"id":"newer","name":"Newer","version":"1.0.0","schema_version":2}');
        $this->expectException(ContentPackageException::class);
        $loader->load($root . '/newer/manifest.json');
    }

    public function testDuplicateMissingAndCircularDependenciesFail(): void
    {
        $root = $this->temporaryRoot();
        $manifest = static fn (string $id, array $dependencies = []): string => json_encode([
            'id' => $id,
            'name' => $id,
            'version' => '1.0.0',
            'schema_version' => 1,
            'dependencies' => $dependencies,
        ], JSON_THROW_ON_ERROR);
        $this->writePackage($root, 'one', $manifest('same'));
        $this->writePackage($root, 'two', $manifest('same'));
        try {
            (new ContentPackageDiscovery($root))->discover();
            self::fail('Expected duplicate package ID to fail.');
        } catch (ContentPackageException $exception) {
            self::assertStringContainsString('Duplicate', $exception->getMessage());
        }

        $root = $this->temporaryRoot();
        $this->writePackage($root, 'missing', $manifest('missing', ['absent']));
        try {
            $packages = (new ContentPackageDiscovery($root))->discover();
            (new ContentPackageCatalog($packages));
            self::fail('Expected missing dependency to fail.');
        } catch (ContentPackageException $exception) {
            self::assertStringContainsString('missing', strtolower($exception->getMessage()));
        }

        $root = $this->temporaryRoot();
        $this->writePackage($root, 'a', $manifest('a', ['b']));
        $this->writePackage($root, 'b', $manifest('b', ['a']));
        $packages = (new ContentPackageDiscovery($root))->discover();
        $this->expectException(ContentPackageException::class);
        (new ContentPackageCatalog($packages));
    }

    public function testDeclaredFilesAndMalformedContentFail(): void
    {
        $root = $this->temporaryRoot();
        $this->writePackage($root, 'declared', '{"id":"declared","name":"Declared","version":"1.0.0","schema_version":1,"files":["missing.json"]}');
        $this->expectException(ContentPackageException::class);
        (new ContentPackageDiscovery($root))->discover();
    }

    public function testMalformedJsonContentFailsThroughContentBoundary(): void
    {
        $root = $this->temporaryRoot();
        $this->writePackage($root, 'bad-json', '{"id":"bad-json","name":"Bad JSON","version":"1.0.0","schema_version":1,"files":["data.json"]}', [
            'data.json' => '{bad-json',
        ]);
        $package = (new ContentPackageDiscovery($root))->discover()[0];

        $this->expectException(ContentPackageException::class);
        (new ContentFileLoader())->readJson($package, 'data.json');
    }

    public function testValidatorAndSerializerRemainComposable(): void
    {
        $manifest = ContentPackageManifest::fromArray([
            'id' => 'composable',
            'name' => 'Composable',
            'version' => '1.0.0',
            'schema_version' => 1,
            'metadata' => ['source' => 'test'],
        ]);
        $root = $this->temporaryRoot();
        mkdir($root . '/composable');
        file_put_contents($root . '/composable/manifest.json', (new JsonSerializer())->encode([
            'id' => $manifest->id(),
            'name' => $manifest->name(),
            'version' => $manifest->version(),
            'schema_version' => $manifest->schemaVersion(),
        ]));
        $package = new ContentPackage($manifest, $root . '/composable');

        (new ContentPackageValidator())->validate($package);
        self::assertSame('composable', $package->manifest()->id());
    }

    private function temporaryRoot(): string
    {
        $root = sys_get_temp_dir() . '/goal-legacy-content-' . bin2hex(random_bytes(8));
        mkdir($root, 0775, true);
        $this->temporaryRoots[] = $root;

        return $root;
    }

    /** @param array<string, string> $files */
    private function writePackage(string $root, string $directory, string $manifest, array $files = []): void
    {
        mkdir($root . '/' . $directory, 0775, true);
        file_put_contents($root . '/' . $directory . '/manifest.json', $manifest);
        foreach ($files as $path => $contents) {
            file_put_contents($root . '/' . $directory . '/' . $path, $contents);
        }
    }
}
