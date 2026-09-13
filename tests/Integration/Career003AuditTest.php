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
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class Career003AuditTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            foreach (glob($root . '/*') ?: [] as $file) {
                if (is_file($file)) { unlink($file); }
            }
            if (is_dir($root)) { rmdir($root); }
        }
    }

    public function testCurrentProductionWorldCompletesSeasonAndPreparesSeasonTwo(): void
    {
        [$services, $database, $store, $world] = $this->scenario('career-003-rollover');
        $worldService = $services->worldModule()->service();
        $clubService = $services->clubModule()->service();
        $membershipsBefore = count($clubService->membershipRepository($database)->bySeason(new SeasonId('season-2024-25')));
        $completed = $worldService->advanceToDate($database, $world->id(), SimulationDate::fromIsoString('2025-06-01'));

        self::assertSame(SeasonStatus::Completed, $worldService->seasonRepository($database)->get(new SeasonId('season-2024-25'))->status());
        self::assertSame('season-2024-25', $completed->currentSeasonId()?->value());
        self::assertSame(SeasonStatus::Upcoming, $worldService->seasonRepository($database)->get(new SeasonId('season-2025-26'))->status());

        $activated = $worldService->advanceToDate($database, $world->id(), SimulationDate::fromIsoString('2025-08-01'));
        self::assertSame('season-2025-26', $activated->currentSeasonId()?->value());
        self::assertSame(SeasonStatus::Active, $worldService->seasonRepository($database)->get(new SeasonId('season-2025-26'))->status());
        self::assertSame($membershipsBefore, count($clubService->membershipRepository($database)->bySeason(new SeasonId('season-2025-26'))));
        self::assertCount(2, $worldService->seasonRepository($database)->all());

        $again = $worldService->advanceToDate($database, $world->id(), SimulationDate::fromIsoString('2025-08-01'));
        self::assertSame($activated->toArray(), $again->toArray());
        self::assertSame($membershipsBefore, count($clubService->membershipRepository($database)->bySeason(new SeasonId('season-2025-26'))));

        unset($database);
        $database = $store->openDatabase('career-003-rollover');
        self::assertSame('season-2025-26', $worldService->load($database, $world->id())->currentSeasonId()?->value());
    }

    /** @return array{0: object, 1: object, 2: SqliteSaveStore, 3: World} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 13003, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $store, $world];
    }
}
