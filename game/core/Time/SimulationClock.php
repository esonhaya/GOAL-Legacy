<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Time;

use InvalidArgumentException;

final class SimulationClock
{
    private SimulationTime $current;

    public function __construct(SimulationTime $initialTime)
    {
        $this->current = $initialTime;
    }

    public function now(): SimulationTime
    {
        return $this->current;
    }

    public function advanceBy(SimulationDuration $duration): SimulationTime
    {
        return $this->advanceTo($this->current->add($duration));
    }

    public function advanceTo(SimulationTime $target): SimulationTime
    {
        if ($target->isBefore($this->current)) {
            throw new InvalidArgumentException('Simulation clock cannot move backwards.');
        }

        $this->current = $target;

        return $this->current;
    }
}
