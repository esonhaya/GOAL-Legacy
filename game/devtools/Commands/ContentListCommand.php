<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;

final class ContentListCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services)
    {
    }

    public function name(): string { return 'content:list'; }

    public function description(): string { return 'List discovered versioned content packages and selection state.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $packages = $this->services->contentPackages()->packages();
        if ($packages === []) {
            $output->write('No content packages discovered.');

            return 0;
        }

        foreach ($packages as $package) {
            $manifest = $package->manifest();
            $dependencies = $manifest->dependencies() === [] ? '-' : implode(',', $manifest->dependencies());
            $output->write(sprintf(
                '%s %s version=%s schema=%d selected=%s dependencies=%s',
                $manifest->id(),
                $manifest->name(),
                $manifest->version(),
                $manifest->schemaVersion(),
                $this->services->contentPackages()->isSelected($manifest->id()) ? 'yes' : 'no',
                $dependencies,
            ));
        }

        return 0;
    }
}
