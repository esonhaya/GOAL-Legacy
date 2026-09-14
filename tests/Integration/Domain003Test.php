<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\Club\Persistence\ClubMembershipRepository;
use Goal\Legacy\Modules\Club\Persistence\ClubRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class Domain003Test extends TestCase
{
    /** @var list<string> */
    private array $temporaryRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryRoots as $root) {
            foreach (glob($root . '/*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

    public function testBig5WorldInitializationPersistsAndReloadsClubMembershipGraph(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        $calendar = $services->worldModule()->service()->calendar();
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $clubs = $services->clubModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $world = new World(
            new WorldId('domain-003-world'),
            'DOMAIN-003 test world',
            2024003,
            new DateTimeImmutable('@0'),
            $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')),
            $season->id(),
            array_map(static fn ($nation): string => $nation->id()->value(), $nations),
            array_map(static fn ($competition): string => $competition->id()->value(), $competitions),
            $services->contentPackages()->selectedIds(),
        );

        $directory = sys_get_temp_dir() . '/goal-legacy-domain-003-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->temporaryRoots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create('domain-003-world', 'DOMAIN-003 test', $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase('domain-003-world');
        $worldService = $services->worldModule()->service();
        $worldService->initialize($database, $world, $season);

        $clubRepository = new ClubRepository($database);
        $membershipRepository = new ClubMembershipRepository($database);
        self::assertCount(198, $clubs);
        self::assertCount(198, $clubRepository->all());
        self::assertCount(198, $membershipRepository->all());
        self::assertSame(['2-bundesliga' => 18, 'bundesliga' => 18, 'championship' => 24, 'la-liga' => 20, 'ligue-1' => 18, 'ligue-2' => 18, 'premier-league' => 20, 'segunda-division' => 22, 'serie-a' => 20, 'serie-b' => 20], $this->membershipCounts($membershipRepository->all()));
        self::assertSame([], array_diff(array_map(static fn ($club): string => $club->id()->value(), $clubRepository->all()), array_map(static fn ($definition): string => $definition->club()->id()->value(), $clubs)));

        $before = array_map(static fn ($membership): array => $membership->toArray(), $membershipRepository->all());
        $reloaded = $worldService->load($database, 'domain-003-world');
        self::assertSame($world->toArray(), $reloaded->toArray());
        self::assertSame($before, array_map(static fn ($membership): array => $membership->toArray(), $membershipRepository->all()));
        self::assertArrayNotHasKey('canonical_name', $before[0]);
    }

    /** @param list<\Goal\Legacy\Modules\Club\Domain\ClubCompetitionMembership> $memberships @return array<string, int> */
    private function membershipCounts(array $memberships): array
    {
        $counts = [];
        foreach ($memberships as $membership) {
            $id = $membership->competitionId()->value();
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }
}
