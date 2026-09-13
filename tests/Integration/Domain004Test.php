<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class Domain004Test extends TestCase
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

    public function testCareerPlayerCreationSquadPersistenceAndReloadPath(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        $calendar = $services->worldModule()->service()->calendar();
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $world = new World(new WorldId('domain-004-world'), 'DOMAIN-004 test world', 2024004, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/goal-legacy-domain-004-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->temporaryRoots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create('domain-004-world', 'DOMAIN-004 test', $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase('domain-004-world');
        $services->worldModule()->service()->initialize($database, $world, $season);

        $playerService = $services->playerModule()->service();
        $player = $playerService->create(new PlayerCreationRequest('domain-004-player', 'Jamie', 'Legacy', null, '2005-02-03', 'england', [], 'england', null, 178, 72, 'CM', 86, 'prodigy', 99));
        $playerService->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('domain-004-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id()));

        $restored = (new PlayerRepository($database))->get('domain-004-player');
        $career = (new CareerPlayerRepository($database))->get('domain-004-career');
        $squad = $services->clubModule()->service()->squadRepository($database)->byPlayer('domain-004-player', $season->id());
        self::assertSame($player->toArray(), $restored->toArray());
        self::assertSame('domain-004-player', $career->playerId()->value());
        self::assertCount(1, $squad);
        self::assertSame('arsenal', $squad[0]->clubId()->value());
        self::assertSame([], array_diff(array_keys($career->toArray()), ['career_id', 'player_id', 'start_date']));
    }
}
