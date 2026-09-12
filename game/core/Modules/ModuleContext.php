<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Modules;

use Goal\Legacy\Core\Configuration\ConfigurationInterface;
use Goal\Legacy\Core\Events\EventDispatcherInterface;
use Goal\Legacy\Core\Logging\LoggerInterface;

final class ModuleContext
{
    public function __construct(
        private readonly ConfigurationInterface $configuration,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function configuration(): ConfigurationInterface { return $this->configuration; }

    public function eventDispatcher(): EventDispatcherInterface { return $this->eventDispatcher; }

    public function logger(): LoggerInterface { return $this->logger; }
}
