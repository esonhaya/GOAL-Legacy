<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Fixtures;

use Goal\Legacy\Core\Modules\ModuleContext;
use Goal\Legacy\Core\Modules\ModuleDescriptor;
use Goal\Legacy\Core\Modules\ModuleInterface;

final class RecordingModule implements ModuleInterface
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly ModuleDescriptor $moduleDescriptor)
    {
    }

    public function descriptor(): ModuleDescriptor { return $this->moduleDescriptor; }

    public function boot(ModuleContext $context): void { $this->calls[] = 'boot'; }

    public function start(): void { $this->calls[] = 'start'; }

    public function stop(): void { $this->calls[] = 'stop'; }
}
