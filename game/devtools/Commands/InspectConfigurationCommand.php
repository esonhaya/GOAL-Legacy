<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;

final class InspectConfigurationCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services)
    {
    }

    public function name(): string { return 'config:inspect'; }

    public function description(): string { return 'Inspect resolved Core configuration.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $output->write((string) json_encode($this->services->configuration()->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return 0;
    }
}
