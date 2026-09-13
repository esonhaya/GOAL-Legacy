<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Club;

use Goal\Legacy\Core\Modules\ModuleContext;
use Goal\Legacy\Core\Modules\ModuleDescriptor;
use Goal\Legacy\Core\Modules\ModuleInterface;

final class ClubModule implements ModuleInterface
{
    public function __construct(private readonly ClubService $service)
    {
    }

    public function descriptor(): ModuleDescriptor
    {
        return new ModuleDescriptor('club', 'Club', '1.0.0', ['nation', 'competition'], critical: true);
    }

    public function boot(ModuleContext $context): void
    {
        $count = count($this->service->loadSelected());
        $context->logger()->info('club.module', 'Club content validated.', ['count' => $count]);
    }

    public function start(): void
    {
    }

    public function stop(): void
    {
    }

    public function service(): ClubService
    {
        return $this->service;
    }
}
