<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\World\Domain;

use Goal\Legacy\Core\Time\SimulationTime;
use InvalidArgumentException;
use OverflowException;

final readonly class SimulationCalendar
{
    public function __construct(
        private SimulationDate $epoch = new SimulationDate(2000, 1, 1),
        private int $ticksPerDay = 1,
    ) {
        if ($ticksPerDay < 1) {
            throw new InvalidArgumentException('A simulation calendar needs at least one tick per day.');
        }
    }

    public function epoch(): SimulationDate
    {
        return $this->epoch;
    }

    public function ticksPerDay(): int
    {
        return $this->ticksPerDay;
    }

    public function dateAt(SimulationTime $time): SimulationDate
    {
        return $this->epoch->addDays(intdiv($time->ticks(), $this->ticksPerDay));
    }

    public function timeAt(SimulationDate $date): SimulationTime
    {
        $days = $this->epoch->daysUntil($date);
        if ($days < 0) {
            throw new InvalidArgumentException('Simulation dates cannot precede the calendar epoch.');
        }
        if ($days > intdiv(PHP_INT_MAX, $this->ticksPerDay)) {
            throw new OverflowException('Simulation calendar conversion overflowed.');
        }

        return new SimulationTime($days * $this->ticksPerDay);
    }
}
