<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;

final class ClubListCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services)
    {
    }

    public function name(): string { return 'club:list'; }

    public function description(): string { return 'List validated Club definitions and season-bound Competition memberships.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $clubs = $this->services->clubModule()->service()->loadSelected();
        if ($clubs === []) {
            $output->write('No selected Club content found.');

            return 0;
        }

        foreach ($clubs as $definition) {
            $memberships = array_map(
                static fn ($membership): string => sprintf('%s@%s', $membership->competitionId()->value(), $membership->seasonId()->value()),
                $definition->memberships(),
            );
            $output->write(sprintf(
                '%s %s nation=%s competitions=%s source=%s@%s',
                $definition->club()->id()->value(),
                $definition->club()->canonicalName(),
                $definition->club()->nationId()->value(),
                implode(',', $memberships),
                $definition->club()->sourcePackageId(),
                $definition->club()->sourcePackageVersion(),
            ));
        }

        return 0;
    }
}
