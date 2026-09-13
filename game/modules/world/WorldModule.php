<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\World;

use Goal\Legacy\Core\Modules\ModuleContext;
use Goal\Legacy\Core\Modules\ModuleDescriptor;
use Goal\Legacy\Core\Modules\ModuleInterface;

final class WorldModule implements ModuleInterface
{
    public function __construct(private readonly WorldService $service)
    {
    }

    public function descriptor(): ModuleDescriptor
    {
        return new ModuleDescriptor('world', 'World', '1.0.0', ['nation', 'competition', 'club', 'contract'], critical: true);
    }

    public function boot(ModuleContext $context): void
    {
        $context->logger()->info('world.module', 'World timeline services initialized.');
    }

    public function start(): void
    {
    }

    public function stop(): void
    {
    }

    public function service(): WorldService
    {
        return $this->service;
    }
}
