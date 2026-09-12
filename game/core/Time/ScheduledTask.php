<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Time;

final class ScheduledTask
{
    private bool $cancelled = false;

    public function __construct(
        private readonly ScheduledTaskToken $token,
        private readonly SimulationTime $time,
        private readonly int $priority,
        private readonly int $sequence,
        private readonly ScheduledWork $work,
    ) {
    }

    public function token(): ScheduledTaskToken
    {
        return $this->token;
    }

    public function time(): SimulationTime
    {
        return $this->time;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function sequence(): int
    {
        return $this->sequence;
    }

    public function work(): ScheduledWork
    {
        return $this->work;
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
