<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Bootstrap;

use Goal\Legacy\Core\Configuration\ConfigurationLoader;
use Goal\Legacy\Core\Content\ContentPackageCatalog;
use Goal\Legacy\Core\Content\ContentPackageDiscovery;
use Goal\Legacy\Core\Events\EventDispatcher;
use Goal\Legacy\Core\Logging\FileLogger;
use Goal\Legacy\Core\Logging\LogLevel;
use Goal\Legacy\Core\Modules\ModuleRegistry;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Core\Time\Scheduler;
use Goal\Legacy\Core\Time\SimulationClock;
use Goal\Legacy\Core\Time\SimulationTime;
use Goal\Legacy\Modules\Club\ClubModule;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Club\ClubRecruitmentService;
use Goal\Legacy\Modules\Competition\CompetitionModule;
use Goal\Legacy\Modules\Competition\DomesticCupService;
use Goal\Legacy\Modules\Competition\CompetitionService;
use Goal\Legacy\Modules\Contract\ContractModule;
use Goal\Legacy\Modules\Contract\ContractService;
use Goal\Legacy\Modules\Nation\NationModule;
use Goal\Legacy\Modules\Nation\NationService;
use Goal\Legacy\Modules\Player\PlayerModule;
use Goal\Legacy\Modules\Player\PlayerService;
use Goal\Legacy\Modules\Player\Finance\PlayerFinanceService;
use Goal\Legacy\Modules\Player\PlayerDevelopmentService;
use Goal\Legacy\Modules\Player\PlayerAvailabilityService;
use Goal\Legacy\Modules\Player\PlayerPopulationService;
use Goal\Legacy\Modules\Player\PlayerLifecycleService;
use Goal\Legacy\Modules\Player\ClubExpectationService;
use Goal\Legacy\Modules\Match\MatchModule;
use Goal\Legacy\Modules\Match\MatchService;
use Goal\Legacy\Modules\World\Domain\SimulationCalendar;
use Goal\Legacy\Modules\World\WorldModule;
use Goal\Legacy\Modules\World\WorldService;
use Goal\Legacy\Modules\World\SeasonRolloverService;
use Goal\Legacy\Modules\Transfer\TransferModule;
use Goal\Legacy\Modules\Transfer\TransferService;
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
        $saveStore = new SqliteSaveStore($projectRoot . '/game/saves', new JsonSerializer());
        $contentPath = $configuration->string('content.path');
        if (!str_starts_with($contentPath, '/')) {
            $contentPath = $projectRoot . '/' . $contentPath;
        }
        $selectedPackages = $configuration->get('content.selected', []);
        if (!is_array($selectedPackages)) {
            throw new InvalidArgumentException('Configuration "content.selected" must be an array.');
        }
        $contentPackages = new ContentPackageCatalog(
            (new ContentPackageDiscovery($contentPath))->discover(),
            array_values($selectedPackages),
        );
        $nationModule = new NationModule(new NationService($contentPackages));
        $competitionModule = new CompetitionModule(new CompetitionService($contentPackages, $nationModule->service()));
        $clubModule = new ClubModule(new ClubService($contentPackages, $nationModule->service(), $competitionModule->service()));
        $domesticCups = new DomesticCupService($clubModule->service());
        $developmentService = new PlayerDevelopmentService($dispatcher);
        $availabilityService = new PlayerAvailabilityService($dispatcher);
        $contractModule = new ContractModule(new ContractService());
        $populationService = new PlayerPopulationService($nationModule->service(), $clubModule->service(), $contractModule->service());
        $playerLifecycleService = new PlayerLifecycleService($developmentService, $contractModule->service());
        $playerFinanceService = new PlayerFinanceService();
        $playerModule = new PlayerModule(new PlayerService($nationModule->service(), $clubModule->service(), $developmentService, $availabilityService, $populationService, $playerFinanceService));
        $transferModule = new TransferModule(new TransferService($contractModule->service(), $clubModule->service(), $competitionModule->service(), $dispatcher));
        $clubRecruitmentService = new ClubRecruitmentService($clubModule->service(), $contractModule->service(), $competitionModule->service(), $transferModule->service());
        $expectationService = new ClubExpectationService($clubModule->service(), $dispatcher);
        $matchModule = new MatchModule(new MatchService($clubModule->service(), $dispatcher, $developmentService, $expectationService, $availabilityService, $domesticCups));
        $seasonRollover = new SeasonRolloverService($competitionModule->service(), $clubModule->service(), $contractModule->service(), $populationService, $playerLifecycleService, $clubRecruitmentService, $matchModule->service(), $dispatcher, $transferModule->service(), $domesticCups);
        $worldModule = new WorldModule(new WorldService(
            $clock,
            new SimulationCalendar(),
            $dispatcher,
            $nationModule->service(),
            $competitionModule->service(),
            $clubModule->service(),
            contractService: $contractModule->service(),
            seasonRollover: $seasonRollover,
            playerFinance: $playerFinanceService,
            domesticCups: $domesticCups,
        ));
        $registry->register($nationModule);
        $registry->register($competitionModule);
        $registry->register($clubModule);
        $registry->register($playerModule);
        $registry->register($contractModule);
        $registry->register($transferModule);
        $registry->register($matchModule);
        $registry->register($worldModule);
        if (!$registry->isEnabled('player') || !$registry->isEnabled('club')) {
            $registry->setEnabled('contract', false);
        }
        if (!$registry->isEnabled('player') || !$registry->isEnabled('club') || !$registry->isEnabled('competition') || !$registry->isEnabled('contract')) {
            $registry->setEnabled('transfer', false);
        }
        if (!$registry->isEnabled('player') || !$registry->isEnabled('club') || !$registry->isEnabled('competition')) {
            $registry->setEnabled('match', false);
        }
        if (!$registry->isEnabled('nation') || !$registry->isEnabled('competition') || !$registry->isEnabled('club') || !$registry->isEnabled('contract')) {
            $registry->setEnabled('world', false);
        }

        $logger->info('core.bootstrap', 'Core services initialized.', [
            'environment' => $configuration->string('app.environment'),
        ]);

        return new CoreServices($configuration, $logger, $dispatcher, $registry, $clock, $scheduler, $saveStore, $contentPackages, $nationModule, $competitionModule, $clubModule, $clubRecruitmentService, $playerModule, $playerFinanceService, $contractModule, $transferModule, $matchModule, $worldModule);
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
