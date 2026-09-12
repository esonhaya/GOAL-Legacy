<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;

final class NationListCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services)
    {
    }

    public function name(): string { return 'nation:list'; }

    public function description(): string { return 'List validated Nations from selected content packages.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $nations = $this->services->nationModule()->service()->loadSelected();
        if ($nations === []) {
            $output->write('No selected Nation content found.');

            return 0;
        }

        foreach ($nations as $nation) {
            $output->write(sprintf(
                '%s %s code=%s source=%s@%s',
                $nation->id()->value(),
                $nation->displayName(),
                $nation->code() ?? '-',
                $nation->sourcePackageId(),
                $nation->sourcePackageVersion(),
            ));
        }

        return 0;
    }
}
