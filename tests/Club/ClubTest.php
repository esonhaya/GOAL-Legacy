<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Club;

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Content\ContentPackageCatalog;
use Goal\Legacy\Core\Content\ContentPackageDiscovery;
use Goal\Legacy\Core\Persistence\SqliteDatabase;
use Goal\Legacy\Modules\Club\Content\ClubContentLoader;
use Goal\Legacy\Modules\Club\Domain\Club;
use Goal\Legacy\Modules\Club\Domain\ClubCompetitionMembership;
use Goal\Legacy\Modules\Club\Domain\ClubException;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\ClubMembershipException;
use Goal\Legacy\Modules\Club\Persistence\ClubMaterializer;
use Goal\Legacy\Modules\Club\Persistence\ClubMembershipRepository;
use Goal\Legacy\Modules\Club\Persistence\ClubRepository;
use Goal\Legacy\Modules\Competition\Domain\Competition;
use Goal\Legacy\Modules\Competition\Domain\CompetitionDefinition;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
use Goal\Legacy\Modules\Competition\Persistence\CompetitionRepository;
use Goal\Legacy\Modules\Nation\Domain\Nation;
use Goal\Legacy\Modules\Nation\Domain\NationId;
use Goal\Legacy\Modules\Nation\Persistence\NationRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Persistence\SeasonRepository;
use PHPUnit\Framework\TestCase;

final class ClubTest extends TestCase
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

    public function testClubModelHasThreeIdentityLayersAndStableIdentity(): void
    {
        $club = $this->club('test-club');

        self::assertSame('test-club', $club->id()->value());
        self::assertSame('Core values', $club->corePhilosophy());
        self::assertSame('Technical football', $club->footballIdentity());
        self::assertSame('Current expression', $club->currentStyle());
        self::assertSame($club->toArray(), $club->toArray());

        $this->expectException(\InvalidArgumentException::class);
        new ClubId('../invalid');
    }

    public function testBig5ClubContentLoadsCompleteDeterministicMembershipSet(): void
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $definitions = $services->clubModule()->service()->loadSelected();
        $counts = [];
        foreach ($definitions as $definition) {
            $competitionId = $definition->memberships()[0]->competitionId()->value();
            $counts[$competitionId] = ($counts[$competitionId] ?? 0) + 1;
        }
        ksort($counts);

        self::assertCount(96, $definitions);
        self::assertSame([
            'bundesliga' => 18,
            'la-liga' => 20,
            'ligue-1' => 18,
            'premier-league' => 20,
            'serie-a' => 20,
        ], $counts);
        self::assertSame('core-clubs', $definitions[0]->club()->sourcePackageId());
        self::assertSame('season-2024-25', $definitions[0]->memberships()[0]->seasonId()->value());
    }

    public function testClubContentRejectsUnknownNation(): void
    {
        $root = $this->temporaryRoot();
        $this->writeClubPackage($root, 'invalid', [
            ['id' => 'duplicate', 'name' => 'Duplicate', 'short_name' => 'Duplicate', 'nation_id' => 'missing', 'city' => 'X', 'founded_year' => 1900, 'stadium_name' => 'X', 'club_colors' => 'x', 'core_philosophy' => 'x', 'football_identity' => 'x', 'current_style' => 'x', 'memberships' => []],
        ]);
        $catalog = new ContentPackageCatalog((new ContentPackageDiscovery($root))->discover(), ['invalid']);
        $loader = new ClubContentLoader();
        $this->expectException(ClubException::class);
        $loader->loadSelected($catalog, [$this->nation()], [$this->competitionDefinition()]);
    }

    public function testClubContentRejectsUnknownCompetitionAndDuplicateMembership(): void
    {
        $unknownRoot = $this->temporaryRoot();
        $this->writeClubPackage($unknownRoot, 'unknown-competition', [$this->clubRecord('unknown-competition-club', [
            'memberships' => [['competition_id' => 'missing-competition', 'season_id' => 'season-2024-25']],
        ])]);
        $loader = new ClubContentLoader();
        $this->expectException(ClubException::class);
        $loader->loadSelected(new ContentPackageCatalog((new ContentPackageDiscovery($unknownRoot))->discover(), ['unknown-competition']), [$this->nation()], [$this->competitionDefinition()]);
    }

    public function testClubContentRejectsDuplicateMembership(): void
    {
        $root = $this->temporaryRoot();
        $this->writeClubPackage($root, 'duplicate-membership', [$this->clubRecord('duplicate-membership-club', [
            'memberships' => [
                ['competition_id' => 'test-competition', 'season_id' => 'season-2024-25'],
                ['competition_id' => 'test-competition', 'season_id' => 'season-2024-25'],
            ],
        ])]);
        $this->expectException(ClubException::class);
        (new ClubContentLoader())->loadSelected(new ContentPackageCatalog((new ContentPackageDiscovery($root))->discover(), ['duplicate-membership']), [$this->nation()], [$this->competitionDefinition()]);
    }

    public function testClubRepositoryMaterializesMembershipAndProtectsDuplicates(): void
    {
        $database = new SqliteDatabase(':memory:');
        $nationRepository = new NationRepository($database);
        $nationRepository->save($this->nation());
        $competitionRepository = new CompetitionRepository($database);
        $competitionRepository->save(new Competition($this->competitionDefinition()));
        $seasonRepository = new SeasonRepository($database);
        $seasonRepository->save($this->season());
        $membership = new ClubCompetitionMembership(new ClubId('test-club'), new CompetitionId('test-competition'), new SeasonId('season-2024-25'));
        $definition = new \Goal\Legacy\Modules\Club\Domain\ClubContentDefinition($this->club('test-club'), [$membership]);

        $materializer = new ClubMaterializer($database);
        self::assertSame(1, $materializer->materialize([$definition]));
        self::assertSame(1, $materializer->materialize([$definition]));
        $clubs = (new ClubRepository($database))->all();
        self::assertSame(['test-club'], array_map(static fn (Club $club): string => $club->id()->value(), $clubs));
        self::assertSame(['test-club'], array_map(static fn ($row): string => $row->clubId()->value(), (new ClubMembershipRepository($database))->byCompetition('test-competition')));

        $this->expectException(ClubMembershipException::class);
        (new ClubMembershipRepository($database))->save($membership);
    }

    private function club(string $id): Club
    {
        return new Club(new ClubId($id), 'Test Club', 'Test', null, new NationId('england'), 'London', 1900, 'Test Ground', 'red and white', 'Core values', 'Technical football', 'Current expression', 50, 50, 'test-package', '1.0.0', 1);
    }

    private function nation(): Nation
    {
        return new Nation(new NationId('england'), 'England', 'England', 'ENG', 'europe', 'england', 'association-england', 'test-package', '1.0.0', 1);
    }

    private function competitionDefinition(): CompetitionDefinition
    {
        return new CompetitionDefinition(new CompetitionId('test-competition'), 'Test Competition', 'Test', CompetitionType::DomesticLeague, new NationId('england'), 'test-package', '1.0.0', 1);
    }

    private function season(): Season
    {
        return new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function clubRecord(string $id, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'name' => 'Fixture Club',
            'short_name' => 'Fixture',
            'nation_id' => 'england',
            'city' => 'London',
            'founded_year' => 1900,
            'stadium_name' => 'Fixture Ground',
            'club_colors' => 'red and white',
            'core_philosophy' => 'Core values',
            'football_identity' => 'Technical football',
            'current_style' => 'Current expression',
            'memberships' => [['competition_id' => 'test-competition', 'season_id' => 'season-2024-25']],
        ], $overrides);
    }

    /** @param list<array<string, mixed>> $records */
    private function writeClubPackage(string $root, string $id, array $records): void
    {
        $directory = $root . '/' . $id;
        mkdir($directory, 0775, true);
        file_put_contents($directory . '/manifest.json', json_encode([
            'id' => $id, 'name' => $id, 'version' => '1.0.0', 'schema_version' => 1,
            'files' => ['clubs.json'], 'metadata' => ['content_domain' => 'club'],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($directory . '/clubs.json', json_encode(['schema_version' => 1, 'clubs' => $records], JSON_THROW_ON_ERROR));
    }

    private function temporaryRoot(): string
    {
        $root = sys_get_temp_dir() . '/goal-legacy-club-test-' . bin2hex(random_bytes(8));
        mkdir($root, 0775, true);
        $this->temporaryRoots[] = $root;

        return $root;
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (glob($directory . '/*') ?: [] as $path) {
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
