<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\SaveStore;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Goal\Legacy\Devtools\Presentation\CareerFormatter;
use Goal\Legacy\Devtools\Presentation\CareerPresentationService;
use Goal\Legacy\Modules\Player\Avatar\PlayerAppearanceService;
use Goal\Legacy\Modules\Player\Avatar\PortraitContext;
use Goal\Legacy\Modules\Player\Avatar\PortraitRenderer;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;

final class CareerViewCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services, private readonly ?SaveStore $saveStore = null)
    {
    }

    public function name(): string { return 'career:view'; }

    public function description(): string { return 'Open the player-facing Career view for a save ID.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $saveId = trim((string) ($arguments[0] ?? ''));
        if ($saveId === '') { $output->error('Usage: career:view <save-id>'); return 1; }
        $database = ($this->saveStore ?? $this->services->saveStore())->openDatabase($saveId);
        $snapshot = (new CareerPresentationService($this->services))->snapshot($database, $saveId);
        foreach ((new CareerFormatter())->career($snapshot['summary']) as $line) {
            $output->write($line);
        }
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->load($database, $saveId);
        $career = (new CareerPlayerRepository($database))->get($saveId);
        $player = $this->services->playerModule()->service()->repository($database)->get($career->playerId());
        $date = $world->currentDate($worldService->calendar());
        $club = isset($snapshot['summary']['current_club']['id']) ? $this->services->clubModule()->service()->repository($database)->get((string) $snapshot['summary']['current_club']['id']) : null;
        $appearance = (new PlayerAppearanceService())->getOrGenerate($database, $player, $date);
        $portrait = (new PortraitRenderer())->render($appearance, PortraitContext::forPlayer($player, $date, $club), 128);
        $output->write('Portrait file: ' . $portrait);

        return 0;
    }
}
