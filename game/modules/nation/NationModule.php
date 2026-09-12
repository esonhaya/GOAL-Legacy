<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Nation;

use Goal\Legacy\Core\Modules\ModuleContext;
use Goal\Legacy\Core\Modules\ModuleDescriptor;
use Goal\Legacy\Core\Modules\ModuleInterface;

final class NationModule implements ModuleInterface
{
    public function __construct(private readonly NationService $service)
    {
    }

    public function descriptor(): ModuleDescriptor
    {
        return new ModuleDescriptor('nation', 'Nation', '1.0.0', critical: true);
    }

    public function boot(ModuleContext $context): void
    {
        $count = count($this->service->loadSelected());
        $context->logger()->info('nation.module', 'Nation content validated.', ['count' => $count]);
    }

    public function start(): void
    {
    }

    public function stop(): void
    {
    }

    public function service(): NationService
    {
        return $this->service;
    }
}
