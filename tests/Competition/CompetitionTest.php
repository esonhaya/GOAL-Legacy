<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Competition;

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\SqliteDatabase;
use Goal\Legacy\Modules\Competition\Domain\Competition;
use Goal\Legacy\Modules\Competition\Domain\CompetitionDefinition;
use Goal\Legacy\Modules\Competition\Domain\CompetitionException;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\CompetitionStatus;
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
use Goal\Legacy\Modules\Competition\Persistence\CompetitionRepository;
use Goal\Legacy\Modules\Nation\Domain\NationId;
use Goal\Legacy\Modules\Nation\Persistence\NationRepository;
use Goal\Legacy\Core\Persistence\SqliteDatabase as Database;
use Goal\Legacy\Core\Content\ContentPackageCatalog;
use Goal\Legacy\Core\Content\ContentPackageDiscovery;
use Goal\Legacy\Modules\Competition\Content\CompetitionContentLoader;
use Goal\Legacy\Modules\Nation\Domain\Nation;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use PHPUnit\Framework\TestCase;

final class CompetitionTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryRoots as $root) {
            $this->removeTree($root);
        }
    }

    public function testSeededCompetitionContentLoadsFiveNationBoundDefinitions(): void
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $definitions = $services->competitionModule()->service()->loadSelected();

        self::assertSame(['bundesliga', 'la-liga', 'ligue-1', 'premier-league', 'serie-a'], array_map(
            static fn (CompetitionDefinition $definition): string => $definition->id()->value(),
            $definitions,
        ));
        self::assertSame('england', $definitions[3]->nationId()->value());
    }

    public function testCompetitionRepositoryMaterializesStateAndQueriesByNationAndSeason(): void
    {
        $database = new SqliteDatabase(':memory:');
        $nationRepository = new NationRepository($database);
        $nationRepository->save($this->nation());
        $definition = $this->definition();
        $service = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test'])->competitionModule()->service();
        self::assertSame(1, $service->materializeInTransaction($database, [$definition], new SeasonId('season-2026-27')));

        $repository = new CompetitionRepository($database);
        $competition = $repository->get('test-competition');
        self::assertSame(CompetitionStatus::Upcoming, $competition->status());
        self::assertSame(['test-competition'], array_map(static fn (Competition $value): string => $value->id()->value(), $repository->byNation('england')));
        self::assertSame(['test-competition'], array_map(static fn (Competition $value): string => $value->id()->value(), $repository->bySeason('season-2026-27')));
        self::assertSame('test-package', $competition->definition()->sourcePackageId());
    }

    public function testCompetitionContentRejectsUnknownNationAndUnsupportedSchema(): void
    {
        $root = sys_get_temp_dir() . '/goal-legacy-competition-test-' . bin2hex(random_bytes(8));
        mkdir($root, 0775, true);
        $this->temporaryRoots[] = $root;
        mkdir($root . '/invalid', 0775, true);
        file_put_contents($root . '/invalid/manifest.json', json_encode([
            'id' => 'invalid', 'name' => 'Invalid', 'version' => '1.0.0', 'schema_version' => 1,
            'files' => ['competitions.json'], 'metadata' => ['content_domain' => 'competition'],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/invalid/competitions.json', json_encode([
            'schema_version' => 1,
            'competitions' => [[
                'id' => 'unknown-nation', 'name' => 'Unknown', 'short_name' => 'Unknown',
                'type' => 'domestic_league', 'nation_id' => 'france',
            ]],
        ], JSON_THROW_ON_ERROR));

        $catalog = new ContentPackageCatalog((new ContentPackageDiscovery($root))->discover(), ['invalid']);
        $this->expectException(CompetitionException::class);
        (new CompetitionContentLoader())->loadSelected($catalog, [$this->nation()]);
    }

    private function definition(): CompetitionDefinition
    {
        return new CompetitionDefinition(
            new CompetitionId('test-competition'),
            'Test Competition',
            'Test',
            CompetitionType::DomesticLeague,
            new NationId('england'),
            'test-package',
            '1.0.0',
            1,
        );
    }

    private function nation(): Nation
    {
        return new Nation(
            new NationId('england'), 'England', 'England', 'ENG', 'europe', 'england', 'association-england', 'test-package', '1.0.0', 1,
        );
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
