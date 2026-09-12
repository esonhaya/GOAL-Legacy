<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Core;

use Goal\Legacy\Core\Time\SimulationClock;
use Goal\Legacy\Core\Time\SimulationDuration;
use Goal\Legacy\Core\Time\SimulationTime;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class SimulationTimeTest extends TestCase
{
    public function testTimeAndDurationUseExplicitDeterministicTicks(): void
    {
        $time = new SimulationTime(100);
        $duration = new SimulationDuration(25);

        self::assertSame(100, $time->ticks());
        self::assertSame(25, $duration->ticks());
        self::assertTrue($time->isBefore(new SimulationTime(101)));
        self::assertTrue($time->isAfter(new SimulationTime(99)));
        self::assertSame(0, $time->compareTo(new SimulationTime(100)));
        self::assertSame(125, $time->add($duration)->ticks());
    }

    public function testNegativeTimeAndDurationAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SimulationTime(-1);
    }

    public function testNegativeDurationIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SimulationDuration(-1);
    }

    public function testArithmeticOverflowIsRejected(): void
    {
        $this->expectException(OverflowException::class);
        (new SimulationTime(PHP_INT_MAX))->add(new SimulationDuration(1));
    }

    public function testClockOnlyAdvancesThroughExplicitCalls(): void
    {
        $clock = new SimulationClock(new SimulationTime(10));

        self::assertSame(10, $clock->now()->ticks());
        self::assertSame(15, $clock->advanceBy(new SimulationDuration(5))->ticks());
        self::assertSame(15, $clock->now()->ticks());

        $this->expectException(InvalidArgumentException::class);
        $clock->advanceTo(new SimulationTime(14));
    }

    public function testRepeatedClocksWithTheSameInputAreDeterministic(): void
    {
        $advance = new SimulationDuration(7);
        $first = (new SimulationClock(new SimulationTime(3)))->advanceBy($advance);
        $second = (new SimulationClock(new SimulationTime(3)))->advanceBy($advance);

        self::assertSame($first->ticks(), $second->ticks());
    }
}
