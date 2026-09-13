<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Transfer;

use Goal\Legacy\Core\Modules\ModuleContext;
use Goal\Legacy\Core\Modules\ModuleDescriptor;
use Goal\Legacy\Core\Modules\ModuleInterface;

final class TransferModule implements ModuleInterface
{
    public function __construct(private readonly TransferService $service) {}
    public function descriptor(): ModuleDescriptor { return new ModuleDescriptor('transfer', 'Transfer', '1.0.0', ['player', 'club', 'competition', 'contract'], critical: true); }
    public function boot(ModuleContext $context): void { $context->logger()->info('transfer.module', 'Transfer execution services initialized.'); }
    public function start(): void {}
    public function stop(): void {}
    public function service(): TransferService { return $this->service; }
}
