<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Contract;

use Goal\Legacy\Core\Modules\ModuleContext;
use Goal\Legacy\Core\Modules\ModuleDescriptor;
use Goal\Legacy\Core\Modules\ModuleInterface;

final class ContractModule implements ModuleInterface
{
    public function __construct(private readonly ContractService $service) {}
    public function descriptor(): ModuleDescriptor { return new ModuleDescriptor('contract', 'Contract', '1.0.0', ['player', 'club'], critical: true); }
    public function boot(ModuleContext $context): void { $context->logger()->info('contract.module', 'Contract persistence services initialized.'); }
    public function start(): void {}
    public function stop(): void {}
    public function service(): ContractService { return $this->service; }
}
