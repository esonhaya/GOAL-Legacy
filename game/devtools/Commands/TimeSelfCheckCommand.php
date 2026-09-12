<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Events\EventPriority;
use Goal\Legacy\Core\Time\SimulationDuration;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;

final class TimeSelfCheckCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services)
    {
    }

    public function name(): string { return 'time:self-check'; }

    public function description(): string { return 'Run the deterministic simulation clock and scheduler self-check.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $clock = $this->services->clock();
        $scheduler = $this->services->scheduler();
        $calls = [];

        $scheduler->scheduleAfter(
            new SimulationDuration(2),
            static function () use (&$calls): void { $calls[] = 'normal'; },
            EventPriority::Normal->value,
        );
        $scheduler->scheduleAfter(
            new SimulationDuration(2),
            static function () use (&$calls): void { $calls[] = 'high'; },
            EventPriority::High->value,
        );
        $cancelled = $scheduler->scheduleAfter(
            new SimulationDuration(2),
            static function () use (&$calls): void { $calls[] = 'cancelled'; },
        );
        $scheduler->cancel($cancelled);

        $clock->advanceBy(new SimulationDuration(2));
        $executed = $scheduler->runDue();

        if ($clock->now()->ticks() !== 2 || $executed !== 2 || $calls !== ['high', 'normal']) {
            $output->error('Deterministic timing self-check failed.');

            return 1;
        }

        $output->write('Deterministic timing self-check passed at tick 2.');

        return 0;
    }
}
