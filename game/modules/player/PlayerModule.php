<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Modules\ModuleContext;
use Goal\Legacy\Core\Modules\ModuleDescriptor;
use Goal\Legacy\Core\Modules\ModuleInterface;

final class PlayerModule implements ModuleInterface
{
    public function __construct(private readonly PlayerService $service)
    {
    }

    public function descriptor(): ModuleDescriptor
    {
        return new ModuleDescriptor('player', 'Player', '1.0.0', ['nation', 'club'], critical: true);
    }

    public function boot(ModuleContext $context): void
    {
        $context->logger()->info('player.module', 'Player creation and persistence services initialized.');
    }

    public function start(): void
    {
    }

    public function stop(): void
    {
    }

    public function service(): PlayerService
    {
        return $this->service;
    }
}
