<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Time;

use InvalidArgumentException;

final readonly class SimulationDuration
{
    public function __construct(private int $ticks)
    {
        if ($ticks < 0) {
            throw new InvalidArgumentException('Simulation duration cannot be negative.');
        }
    }

    public function ticks(): int
    {
        return $this->ticks;
    }
}
