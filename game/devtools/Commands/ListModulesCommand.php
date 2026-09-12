<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;

final class ListModulesCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services)
    {
    }

    public function name(): string { return 'modules:list'; }

    public function description(): string { return 'List registered modules and lifecycle state.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $statuses = $this->services->moduleRegistry()->statuses();
        if ($statuses === []) {
            $output->write('No modules registered.');
            return 0;
        }
        foreach ($statuses as $status) {
            $descriptor = $status->descriptor();
            $output->write(sprintf(
                '%s %s (%s) enabled=%s state=%s',
                $descriptor->id(),
                $descriptor->name(),
                $descriptor->version(),
                $status->enabled() ? 'yes' : 'no',
                $status->state()->value,
            ));
        }

        return 0;
    }
}
