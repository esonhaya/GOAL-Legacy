<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Competition;

use Goal\Legacy\Core\Modules\ModuleContext;
use Goal\Legacy\Core\Modules\ModuleDescriptor;
use Goal\Legacy\Core\Modules\ModuleInterface;

final class CompetitionModule implements ModuleInterface
{
    public function __construct(private readonly CompetitionService $service)
    {
    }

    public function descriptor(): ModuleDescriptor
    {
        return new ModuleDescriptor('competition', 'Competition', '1.0.0', ['nation'], critical: true);
    }

    public function boot(ModuleContext $context): void
    {
        $count = count($this->service->loadSelected());
        $context->logger()->info('competition.module', 'Competition content validated.', ['count' => $count]);
    }

    public function start(): void
    {
    }

    public function stop(): void
    {
    }

    public function service(): CompetitionService
    {
        return $this->service;
    }
}
