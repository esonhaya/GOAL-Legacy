<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Bootstrap;

use Goal\Legacy\Core\Configuration\ConfigurationInterface;
use Goal\Legacy\Core\Content\ContentPackageCatalog;
use Goal\Legacy\Core\Events\EventDispatcherInterface;
use Goal\Legacy\Core\Logging\LoggerInterface;
use Goal\Legacy\Core\Modules\ModuleRegistry;
use Goal\Legacy\Core\Persistence\SaveStore;
use Goal\Legacy\Core\Time\Scheduler;
use Goal\Legacy\Core\Time\SimulationClock;

final class CoreServices
{
    public function __construct(
        private readonly ConfigurationInterface $configuration,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ModuleRegistry $moduleRegistry,
        private readonly SimulationClock $clock,
        private readonly Scheduler $scheduler,
        private readonly SaveStore $saveStore,
        private readonly ContentPackageCatalog $contentPackages,
    ) {
    }

    public function configuration(): ConfigurationInterface { return $this->configuration; }

    public function logger(): LoggerInterface { return $this->logger; }

    public function eventDispatcher(): EventDispatcherInterface { return $this->eventDispatcher; }

    public function moduleRegistry(): ModuleRegistry { return $this->moduleRegistry; }

    public function clock(): SimulationClock { return $this->clock; }

    public function scheduler(): Scheduler { return $this->scheduler; }

    public function saveStore(): SaveStore { return $this->saveStore; }

    public function contentPackages(): ContentPackageCatalog { return $this->contentPackages; }
}
