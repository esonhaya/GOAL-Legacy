<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\World;

use Goal\Legacy\Core\Time\SimulationTime;
use Goal\Legacy\Modules\World\Domain\SimulationCalendar;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CalendarTest extends TestCase
{
    public function testCalendarMapsTicksToDatesAndBackDeterministically(): void
    {
        $calendar = new SimulationCalendar(new SimulationDate(2024, 1, 1), 2);
        $date = SimulationDate::fromIsoString('2024-02-29');
        $time = $calendar->timeAt($date);

        self::assertSame(118, $time->ticks());
        self::assertSame('2024-02-29', $calendar->dateAt($time)->toIsoString());
        self::assertSame('2024-02-29', $calendar->dateAt(new SimulationTime(119))->toIsoString());
    }

    public function testCalendarHandlesLeapYearAndDateArithmetic(): void
    {
        $date = SimulationDate::fromIsoString('2024-02-28');

        self::assertSame('2024-02-29', $date->addDays(1)->toIsoString());
        self::assertSame('2024-03-01', $date->addDays(2)->toIsoString());
        self::assertSame(-2, $date->addDays(2)->daysUntil($date));
        self::assertSame(1, SimulationDate::fromIsoString('2024-03-01')->daysUntil(SimulationDate::fromIsoString('2024-03-02')));
    }

    public function testInvalidDatesAndDatesBeforeEpochAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SimulationDate::fromIsoString('2023-02-29');
    }

    public function testCalendarCannotMapBeforeItsExplicitEpoch(): void
    {
        $calendar = new SimulationCalendar(new SimulationDate(2024, 1, 1));

        $this->expectException(InvalidArgumentException::class);
        $calendar->timeAt(new SimulationDate(2023, 12, 31));
    }
}
