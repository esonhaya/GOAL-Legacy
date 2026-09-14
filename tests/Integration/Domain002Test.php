<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SeasonStatus;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldEventNames;
use Goal\Legacy\Modules\World\Domain\WorldId;
use Goal\Legacy\Core\Time\SimulationTime;
use PHPUnit\Framework\TestCase;

final class Domain002Test extends TestCase
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

    public function testWorldNationCompetitionSeasonSaveAdvanceReloadPath(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        $calendar = $services->worldModule()->service()->calendar();
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $world = new World(
            new WorldId('domain-002-world'),
            'DOMAIN-002 test world',
            2026002,
            new DateTimeImmutable('@0'),
            $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')),
            $season->id(),
            array_map(static fn ($nation): string => $nation->id()->value(), $nations),
            array_map(static fn ($competition): string => $competition->id()->value(), $competitions),
            $services->contentPackages()->selectedIds(),
        );

        $events = [];
        foreach ([WorldEventNames::TIME_ADVANCED, WorldEventNames::SEASON_STARTED, WorldEventNames::SEASON_COMPLETED, WorldEventNames::COMPETITION_ACTIVATED, WorldEventNames::COMPETITION_COMPLETED] as $eventName) {
            $services->eventDispatcher()->subscribe($eventName, static function ($event) use (&$events): void { $events[] = $event->name(); }, listenerId: 'domain-002-' . $eventName);
        }

        $directory = sys_get_temp_dir() . '/goal-legacy-domain-002-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->temporaryRoots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create('domain-002-world', 'DOMAIN-002 test', $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase('domain-002-world');
        $worldService = $services->worldModule()->service();
        $worldService->initialize($database, $world, $season);
        $active = $worldService->advanceByDays($database, 'domain-002-world', 1);
        $reloaded = $worldService->load($database, 'domain-002-world');

        self::assertSame($active->toArray(), $reloaded->toArray());
        self::assertSame(SeasonStatus::Active, $worldService->seasonRepository($database)->get($season->id())->status());
        self::assertCount(10, array_filter($services->competitionModule()->service()->repository($database)->all(), static fn ($competition): bool => $competition->status()->value === 'active'));

        $completed = $worldService->advanceToDate($database, 'domain-002-world', SimulationDate::fromIsoString('2025-06-01'));
        $restored = $worldService->load($database, 'domain-002-world');
        self::assertSame($completed->toArray(), $restored->toArray());
        self::assertSame(SeasonStatus::Completed, $worldService->seasonRepository($database)->get($season->id())->status());
        self::assertCount(10, array_filter($services->competitionModule()->service()->repository($database)->all(), static fn ($competition): bool => $competition->status()->value === 'completed'));
        self::assertSame(2, count(array_filter($events, static fn (string $event): bool => $event === WorldEventNames::TIME_ADVANCED)));
        self::assertContains(WorldEventNames::SEASON_STARTED, $events);
        self::assertContains(WorldEventNames::SEASON_COMPLETED, $events);
        self::assertCount(10, array_filter($events, static fn (string $event): bool => $event === WorldEventNames::COMPETITION_ACTIVATED));
        self::assertCount(10, array_filter($events, static fn (string $event): bool => $event === WorldEventNames::COMPETITION_COMPLETED));
    }
}
