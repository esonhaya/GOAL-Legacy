<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\World;

use DateTimeImmutable;
use Goal\Legacy\Core\Persistence\SqliteDatabase;
use Goal\Legacy\Core\Time\SimulationTime;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationCalendar;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldException;
use Goal\Legacy\Modules\World\Domain\WorldId;
use Goal\Legacy\Modules\World\Persistence\WorldRepository;
use PHPUnit\Framework\TestCase;

final class WorldTest extends TestCase
{
    public function testWorldRoundTripsRootStateAndOnlyStableReferences(): void
    {
        $database = new SqliteDatabase(':memory:');
        $world = $this->world();
        $repository = new WorldRepository($database);

        $repository->save($world);
        $restored = $repository->get('world-test');

        self::assertSame($world->toArray(), $restored->toArray());
        self::assertSame('2026-07-31', $restored->currentDate(new SimulationCalendar())->toIsoString());
        self::assertSame(['england', 'spain'], $restored->nationIds());
        self::assertSame(['premier-league'], $restored->competitionIds());
        self::assertArrayNotHasKey('nations', $restored->toArray());
        self::assertArrayNotHasKey('competitions', $restored->toArray());
    }

    public function testWorldRepositoryEnforcesOneRootAndPersistsTimelineUpdates(): void
    {
        $database = new SqliteDatabase(':memory:');
        $repository = new WorldRepository($database);
        $world = $this->world();
        $repository->save($world);

        $repository->updateTimeline($world->withTimeline(new SimulationTime($world->currentTime()->ticks() + 1)));
        self::assertSame($world->currentTime()->ticks() + 1, $repository->get('world-test')->currentTime()->ticks());

        $this->expectException(WorldException::class);
        $repository->save(new World(
            new WorldId('other-world'),
            'Other',
            2,
            new DateTimeImmutable('@0'),
            new SimulationTime(0),
            null,
            [],
            [],
            [],
        ));
    }

    private function world(): World
    {
        $calendar = new SimulationCalendar();

        return new World(
            new WorldId('world-test'),
            'Test World',
            42,
            new DateTimeImmutable('@0'),
            $calendar->timeAt(SimulationDate::fromIsoString('2026-07-31')),
            new SeasonId('season-2026-27'),
            ['spain', 'england'],
            ['premier-league'],
            ['core-nations', 'core-competitions'],
        );
    }
}
