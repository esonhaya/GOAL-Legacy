<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Bootstrap;

use Goal\Legacy\Core\Configuration\ConfigurationLoader;
use Goal\Legacy\Core\Events\EventDispatcher;
use Goal\Legacy\Core\Logging\FileLogger;
use Goal\Legacy\Core\Logging\LogLevel;
use Goal\Legacy\Core\Modules\ModuleRegistry;
use Goal\Legacy\Core\Time\Scheduler;
use Goal\Legacy\Core\Time\SimulationClock;
use Goal\Legacy\Core\Time\SimulationTime;
use InvalidArgumentException;

final class Bootstrap
{
    public function __construct(private readonly ConfigurationLoader $configurationLoader = new ConfigurationLoader())
    {
    }

    /** @param array<string, string|int|float|bool|null> $environment */
    public function create(?string $projectRoot = null, ?array $environment = null): CoreServices
    {
        $projectRoot ??= dirname(__DIR__, 3);
        $defaultsPath = $projectRoot . '/game/config/core.php';
        $configuration = $this->configurationLoader->load($defaultsPath, $environment ?? self::environment());

        $logPath = $configuration->require('logging.path');
        if (!is_string($logPath) || trim($logPath) === '') {
            throw new InvalidArgumentException('Configuration "logging.path" must be a non-empty string.');
        }
        if (!str_starts_with($logPath, '/')) {
            $logPath = $projectRoot . '/' . $logPath;
        }
        $logLevel = LogLevel::fromName($configuration->string('logging.level'));
        $logger = new FileLogger($logPath, $logLevel);
        $dispatcher = new EventDispatcher($logger);
        $registry = new ModuleRegistry($configuration, $dispatcher, $logger);
        $clock = new SimulationClock(new SimulationTime(0));
        $scheduler = new Scheduler($clock);

        $logger->info('core.bootstrap', 'Core services initialized.', [
            'environment' => $configuration->string('app.environment'),
        ]);

        return new CoreServices($configuration, $logger, $dispatcher, $registry, $clock, $scheduler);
    }

    /** @return array<string, string> */
    private static function environment(): array
    {
        $environment = [];
        foreach ($_ENV as $key => $value) {
            if (is_string($value)) {
                $environment[$key] = $value;
            }
        }
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && !array_key_exists($key, $environment)) {
                $environment[$key] = $value;
            }
        }

        return $environment;
    }
}
