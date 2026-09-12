<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Core;

use Goal\Legacy\Core\Events\EventPriority;
use Goal\Legacy\Core\Time\Scheduler;
use Goal\Legacy\Core\Time\SimulationClock;
use Goal\Legacy\Core\Time\SimulationDuration;
use Goal\Legacy\Core\Time\SimulationTime;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SchedulerTest extends TestCase
{
    public function testFutureWorkDoesNotExecuteEarlyAndScheduleAfterUsesTheClock(): void
    {
        $clock = new SimulationClock(new SimulationTime(100));
        $scheduler = new Scheduler($clock);
        $calls = [];

        $scheduler->scheduleAfter(new SimulationDuration(5), static function () use (&$calls): void {
            $calls[] = 'due';
        });

        self::assertSame(0, $scheduler->runDue());
        self::assertSame([], $calls);
        $clock->advanceBy(new SimulationDuration(4));
        self::assertSame(0, $scheduler->runDue());
        $clock->advanceBy(new SimulationDuration(1));
        self::assertSame(1, $scheduler->runDue());
        self::assertSame(['due'], $calls);
    }

    public function testSameTimeWorkUsesPriorityThenInsertionSequence(): void
    {
        $clock = new SimulationClock(new SimulationTime(100));
        $scheduler = new Scheduler($clock);
        $calls = [];
        $at = new SimulationTime(110);

        $scheduler->scheduleAt($at, static function () use (&$calls): void { $calls[] = 'normal-first'; });
        $scheduler->scheduleAt($at, static function () use (&$calls): void { $calls[] = 'high'; }, EventPriority::High->value);
        $scheduler->scheduleAt($at, static function () use (&$calls): void { $calls[] = 'normal-second'; });

        $clock->advanceTo(new SimulationTime(110));
        self::assertSame(3, $scheduler->runDue());
        self::assertSame(['high', 'normal-first', 'normal-second'], $calls);
    }

    public function testCancellationRemovesWorkWithoutAffectingOtherTasks(): void
    {
        $clock = new SimulationClock(new SimulationTime(0));
        $scheduler = new Scheduler($clock);
        $calls = [];
        $cancelled = $scheduler->scheduleAfter(new SimulationDuration(1), static function () use (&$calls): void {
            $calls[] = 'cancelled';
        });
        $scheduler->scheduleAfter(new SimulationDuration(1), static function () use (&$calls): void {
            $calls[] = 'kept';
        });
        $foreignToken = (new Scheduler(new SimulationClock(new SimulationTime(0))))
            ->scheduleAfter(new SimulationDuration(1), static function (): void {});

        self::assertTrue($scheduler->cancel($cancelled));
        self::assertFalse($scheduler->cancel($cancelled));
        self::assertFalse($scheduler->cancel($foreignToken));
        self::assertSame(1, $scheduler->pendingCount());
        $clock->advanceBy(new SimulationDuration(1));
        self::assertSame(1, $scheduler->runDue());
        self::assertSame(['kept'], $calls);
    }

    public function testLargeAdvancementProcessesAllDueWorkAndNewDueWork(): void
    {
        $clock = new SimulationClock(new SimulationTime(100));
        $scheduler = new Scheduler($clock);
        $calls = [];

        $scheduler->scheduleAt(new SimulationTime(110), static function () use (&$calls): void { $calls[] = '110'; });
        $scheduler->scheduleAt(new SimulationTime(120), static function () use (&$calls, $scheduler): void {
            $calls[] = '120-first';
            $scheduler->scheduleAt(new SimulationTime(160), static function () use (&$calls): void { $calls[] = '160-added'; });
        });
        $scheduler->scheduleAt(new SimulationTime(120), static function () use (&$calls): void { $calls[] = '120-second'; });
        $scheduler->scheduleAt(new SimulationTime(150), static function () use (&$calls): void { $calls[] = '150'; });

        $clock->advanceTo(new SimulationTime(160));
        self::assertSame(5, $scheduler->runDue());
        self::assertSame(['110', '120-first', '120-second', '150', '160-added'], $calls);
        self::assertFalse($scheduler->hasPendingTasks());
    }

    public function testSchedulerDoesNotRunAheadOfTheClock(): void
    {
        $scheduler = new Scheduler(new SimulationClock(new SimulationTime(10)));

        $this->expectException(InvalidArgumentException::class);
        $scheduler->runDue(new SimulationTime(11));
    }

    public function testPastWorkIsRejected(): void
    {
        $scheduler = new Scheduler(new SimulationClock(new SimulationTime(10)));

        $this->expectException(InvalidArgumentException::class);
        $scheduler->scheduleAt(new SimulationTime(9), static function (): void {});
    }

    public function testWorkExceptionsFailFastAndLeaveLaterWorkQueued(): void
    {
        $clock = new SimulationClock(new SimulationTime(0));
        $scheduler = new Scheduler($clock);
        $calls = [];
        $scheduler->scheduleAfter(new SimulationDuration(1), static function (): void {
            throw new RuntimeException('scheduled failure');
        });
        $scheduler->scheduleAfter(new SimulationDuration(2), static function () use (&$calls): void {
            $calls[] = 'later';
        });
        $clock->advanceTo(new SimulationTime(2));

        try {
            $scheduler->runDue();
            self::fail('Expected scheduled work to throw.');
        } catch (RuntimeException $exception) {
            self::assertSame('scheduled failure', $exception->getMessage());
        }
        self::assertSame([], $calls);
        self::assertSame(1, $scheduler->pendingCount());

        self::assertSame(1, $scheduler->runDue());
        self::assertSame(['later'], $calls);
    }
}
