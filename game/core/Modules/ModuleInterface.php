<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Modules;

interface ModuleInterface
{
    public function descriptor(): ModuleDescriptor;

    public function boot(ModuleContext $context): void;

    public function start(): void;

    public function stop(): void;
}
