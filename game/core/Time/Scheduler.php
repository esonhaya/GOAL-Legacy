<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Time;

use Goal\Legacy\Core\Events\EventPriority;
use InvalidArgumentException;
use SplPriorityQueue;

final class Scheduler
{
    private SplPriorityQueue $queue;

    /** @var array<string, ScheduledTask> */
    private array $tasks = [];

    private int $sequence = 0;

    public function __construct(private readonly SimulationClock $clock)
    {
        $this->queue = new class extends SplPriorityQueue {
            public function compare(mixed $left, mixed $right): int
            {
                for ($index = 0; $index < 3; $index++) {
                    if ($left[$index] === $right[$index]) {
                        continue;
                    }

                    return $left[$index] <=> $right[$index];
                }

                return 0;
            }
        };
        $this->queue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
    }

    public function scheduleAt(
        SimulationTime $time,
        ScheduledWork|callable $work,
        int $priority = EventPriority::Normal->value,
    ): ScheduledTaskToken {
        if ($time->isBefore($this->clock->now())) {
            throw new InvalidArgumentException('Scheduled work cannot be placed before the current simulation time.');
        }

        $sequence = $this->nextSequence();
        $token = new ScheduledTaskToken('scheduled-' . $sequence);
        $task = new ScheduledTask(
            token: $token,
            time: $time,
            priority: $priority,
            sequence: $sequence,
            work: $this->normalizeWork($work),
        );

        $this->tasks[$token->id()] = $task;
        $this->queue->insert($task, [
            -$time->ticks(),
            $priority,
            -$sequence,
        ]);

        return $token;
    }

    public function scheduleAfter(
        SimulationDuration $duration,
        ScheduledWork|callable $work,
        int $priority = EventPriority::Normal->value,
    ): ScheduledTaskToken {
        return $this->scheduleAt(
            $this->clock->now()->add($duration),
            $work,
            $priority,
        );
    }

    public function cancel(ScheduledTaskToken $token): bool
    {
        $task = $this->tasks[$token->id()] ?? null;
        if ($task === null || $task->token() !== $token) {
            return false;
        }

        $task->cancel();
        unset($this->tasks[$token->id()]);

        return true;
    }

    /**
     * Execute all work due at or before the current clock time.
     * Exceptions are fail-fast: the failed task is consumed and remaining
     * tasks stay queued for explicit recovery by the caller.
     */
    public function runDue(?SimulationTime $through = null): int
    {
        $through ??= $this->clock->now();
        if ($through->isAfter($this->clock->now())) {
            throw new InvalidArgumentException('Scheduler cannot run ahead of the simulation clock.');
        }

        $executed = 0;
        while (true) {
            $this->discardCancelledTasks();
            if ($this->queue->isEmpty()) {
                return $executed;
            }

            /** @var array{data: ScheduledTask, priority: array<int, int>} $entry */
            $entry = $this->queue->top();
            $task = $entry['data'];
            if ($task->time()->isAfter($through)) {
                return $executed;
            }

            $this->queue->extract();
            unset($this->tasks[$task->token()->id()]);
            $task->work()->execute();
            $executed++;
        }
    }

    public function hasPendingTasks(): bool
    {
        $this->discardCancelledTasks();

        return $this->tasks !== [];
    }

    public function pendingCount(): int
    {
        $this->discardCancelledTasks();

        return count($this->tasks);
    }

    private function nextSequence(): int
    {
        if ($this->sequence === PHP_INT_MAX) {
            throw new \OverflowException('Scheduler insertion sequence overflowed.');
        }

        return $this->sequence++;
    }

    private function normalizeWork(ScheduledWork|callable $work): ScheduledWork
    {
        return $work instanceof ScheduledWork
            ? $work
            : new CallbackScheduledWork(\Closure::fromCallable($work));
    }

    private function discardCancelledTasks(): void
    {
        while (!$this->queue->isEmpty()) {
            /** @var array{data: ScheduledTask, priority: array<int, int>} $entry */
            $entry = $this->queue->top();
            if (!$entry['data']->isCancelled()) {
                return;
            }

            $this->queue->extract();
        }
    }
}
