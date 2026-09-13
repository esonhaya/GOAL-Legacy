<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match;

use Goal\Legacy\Core\Modules\ModuleContext;
use Goal\Legacy\Core\Modules\ModuleDescriptor;
use Goal\Legacy\Core\Modules\ModuleInterface;

final class MatchModule implements ModuleInterface
{
    public function __construct(private readonly MatchService $service) {}
    public function descriptor(): ModuleDescriptor { return new ModuleDescriptor('match', 'Match', '1.0.0', ['competition', 'club', 'player'], critical: true); }
    public function boot(ModuleContext $context): void { $context->logger()->info('match.module', 'Fixture, Match simulation, result, and standings services initialized.'); }
    public function start(): void {}
    public function stop(): void {}
    public function service(): MatchService { return $this->service; }
}
