<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;

final class DoctorCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services, private readonly string $projectRoot)
    {
    }

    public function name(): string { return 'doctor'; }

    public function description(): string { return 'Check the Core runtime environment.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $checks = [
            'PHP >= 8.2' => version_compare(PHP_VERSION, '8.2.0', '>='),
            'configuration loaded' => $this->services->configuration()->has('app.name'),
            'event dispatcher available' => $this->services->eventDispatcher() !== null,
            'module registry available' => $this->services->moduleRegistry() !== null,
            'game/logs directory' => is_dir($this->projectRoot . '/game/logs') && is_writable($this->projectRoot . '/game/logs'),
        ];
        $failed = 0;
        foreach ($checks as $label => $passed) {
            $output->write(sprintf('%s %s', $passed ? '[OK]' : '[FAIL]', $label));
            $failed += $passed ? 0 : 1;
        }

        return $failed === 0 ? 0 : 1;
    }
}
