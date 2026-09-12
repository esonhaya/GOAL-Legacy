<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Modules;

final class ModuleStatus
{
    public function __construct(
        private readonly ModuleDescriptor $descriptor,
        private readonly ModuleState $state,
        private readonly bool $enabled,
    ) {
    }

    public function descriptor(): ModuleDescriptor { return $this->descriptor; }

    public function state(): ModuleState { return $this->state; }

    public function enabled(): bool { return $this->enabled; }
}
