<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Diagnostics;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Tools\Doctor\Contracts\CheckIdentityInterface;
use Tools\Doctor\Contracts\CheckInterface;
use Tools\Doctor\DTO\CheckResult;
use Tools\Doctor\DTO\CheckStatus;

final class CoreFoundationCheck implements CheckInterface, CheckIdentityInterface
{
    public function __construct(
        private readonly CoreServices $services,
        private readonly string $projectRoot,
    ) {
    }

    public function id(): string
    {
        return 'goal.core.foundation';
    }

    public function run(): CheckResult
    {
        $configurationLoaded = $this->services->configuration()->has('app.name');
        $eventDispatcherAvailable = $this->services->eventDispatcher() !== null;
        $moduleRegistryAvailable = $this->services->moduleRegistry() !== null;
        $logDirectory = $this->logDirectory();
        $logDirectoryWritable = is_dir($logDirectory) && is_writable($logDirectory);

        $checks = [
            'configuration loaded' => $configurationLoaded,
            'event dispatcher available' => $eventDispatcherAvailable,
            'module registry available' => $moduleRegistryAvailable,
            'game/logs directory writable' => $logDirectoryWritable,
        ];
        $failed = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));

        return new CheckResult(
            title: 'GOAL Core Foundation',
            status: $failed === [] ? CheckStatus::PASS : CheckStatus::FAIL,
            summary: $failed === []
                ? 'Core services and required runtime paths are available.'
                : 'One or more Core foundation checks failed.',
            details: array_map(
                static fn (string $label, bool $passed): string => sprintf('%s %s', $passed ? '[PASS]' : '[FAIL]', $label),
                array_keys($checks),
                array_values($checks),
            ),
            recommendations: $failed === [] ? [] : ['Restore the failing Core service or runtime path.'],
            score: $failed === [] ? 100 : 0,
            scope: 'DOCTOR',
            id: $this->id(),
            metadata: [
                'failed_checks' => $failed,
                'log_directory' => $logDirectory,
            ],
        );
    }

    public function category(): string
    {
        return 'goal.core';
    }

    public function priority(): int
    {
        return 10;
    }

    private function logDirectory(): string
    {
        $path = $this->services->configuration()->string('logging.path');
        if (!str_starts_with($path, '/')) {
            $path = $this->projectRoot . '/' . $path;
        }

        return dirname($path);
    }
}
