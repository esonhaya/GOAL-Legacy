<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\SaveStore;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Goal\Legacy\Modules\Player\Avatar\PlayerAppearanceService;
use Goal\Legacy\Modules\Player\Avatar\PortraitContext;
use Goal\Legacy\Modules\Player\Avatar\PortraitRenderer;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;

final class CareerPortraitCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services, private readonly ?SaveStore $saveStore = null)
    {
    }

    public function name(): string { return 'career:portrait'; }

    public function description(): string { return 'Render the controlled Player portrait to the disposable cache.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $saveId = trim((string) ($arguments[0] ?? ''));
        $size = (int) ($arguments[1] ?? 256);
        if ($saveId === '') { $output->error('Usage: career:portrait <save-id> [32|64|128|256|512]'); return 1; }
        $database = ($this->saveStore ?? $this->services->saveStore())->openDatabase($saveId);
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->load($database, $saveId);
        $date = $world->currentDate($worldService->calendar());
        $career = (new CareerPlayerRepository($database))->get($saveId);
        $player = $this->services->playerModule()->service()->repository($database)->get($career->playerId());
        $summary = (new \Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery($this->services->clubModule()->service()))->summary($database, $player->id(), $date, $world->currentSeasonId());
        $club = isset($summary['current_club']['id']) ? $this->services->clubModule()->service()->repository($database)->get((string) $summary['current_club']['id']) : null;
        $appearance = (new PlayerAppearanceService())->getOrGenerate($database, $player, $date);
        $path = (new PortraitRenderer())->render($appearance, PortraitContext::forPlayer($player, $date, $club), $size);
        $output->write('PORTRAIT: ' . $path);
        return 0;
    }
}
