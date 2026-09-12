<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;

final class CompetitionListCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services)
    {
    }

    public function name(): string { return 'competition:list'; }

    public function description(): string { return 'List validated Competition definitions from selected content packages.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $competitions = $this->services->competitionModule()->service()->loadSelected();
        if ($competitions === []) {
            $output->write('No selected Competition content found.');

            return 0;
        }

        foreach ($competitions as $competition) {
            $output->write(sprintf(
                '%s %s nation=%s type=%s source=%s@%s',
                $competition->id()->value(),
                $competition->name(),
                $competition->nationId()->value(),
                $competition->type()->value,
                $competition->sourcePackageId(),
                $competition->sourcePackageVersion(),
            ));
        }

        return 0;
    }
}
