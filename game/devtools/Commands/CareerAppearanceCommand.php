<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\SaveStore;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Goal\Legacy\Modules\Player\Avatar\PlayerAppearanceService;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use RuntimeException;

/** Controlled-player cosmetic editor. Changes remain cosmetic and deterministic. */
final class CareerAppearanceCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services, private readonly ?SaveStore $saveStore = null)
    {
    }

    public function name(): string { return 'career:appearance'; }

    public function description(): string { return 'Set a controlled Player preset or randomize a cosmetic category.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $saveId = trim((string) ($arguments[0] ?? ''));
        $action = trim((string) ($arguments[1] ?? ''));
        if ($saveId === '' || $action === '') { $output->error('Usage: career:appearance <save-id> preset:<preset-id>|randomize|category:<category>'); return 1; }
        $database = ($this->saveStore ?? $this->services->saveStore())->openDatabase($saveId);
        $worldService = $this->services->worldModule()->service();
        $date = $worldService->load($database, $saveId)->currentDate($worldService->calendar());
        $career = (new CareerPlayerRepository($database))->get($saveId);
        $player = $this->services->playerModule()->service()->repository($database)->get($career->playerId());
        $service = new PlayerAppearanceService();
        $current = $service->getOrGenerate($database, $player, $date);
        if ($action === 'randomize') {
            $updated = $service->generator()->randomize($player, $date);
        } elseif (str_starts_with($action, 'preset:')) {
            $updated = $service->preset(substr($action, 7));
            if ($updated === null) { throw new RuntimeException('Unknown Avatar preset.'); }
        } elseif (str_starts_with($action, 'category:')) {
            $updated = $service->generator()->randomizeCategory($player, $current, substr($action, 9));
        } else {
            throw new RuntimeException('Appearance action must be preset:<id>, randomize, or category:<name>.');
        }
        $service->save($database, $player, $updated);
        $output->write('Appearance updated. Render it with: php game/devtools/console.php career:portrait ' . $saveId);
        return 0;
    }
}
