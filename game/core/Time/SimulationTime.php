<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Time;

use InvalidArgumentException;
use OverflowException;

final readonly class SimulationTime
{
    public function __construct(private int $ticks)
    {
        if ($ticks < 0) {
            throw new InvalidArgumentException('Simulation time cannot be negative.');
        }
    }

    public function ticks(): int
    {
        return $this->ticks;
    }

    public function compareTo(self $other): int
    {
        return $this->ticks <=> $other->ticks;
    }

    public function isBefore(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isAfter(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function add(SimulationDuration $duration): self
    {
        if ($duration->ticks() > PHP_INT_MAX - $this->ticks) {
            throw new OverflowException('Simulation time arithmetic overflowed.');
        }

        return new self($this->ticks + $duration->ticks());
    }
}
