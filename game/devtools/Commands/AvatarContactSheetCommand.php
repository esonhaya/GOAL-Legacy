<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\SaveStore;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Goal\Legacy\Modules\Player\Avatar\AvatarCatalog;
use Goal\Legacy\Modules\Player\Avatar\PlayerAppearanceGenerator;
use Goal\Legacy\Modules\Player\Avatar\PortraitContext;
use Goal\Legacy\Modules\Player\Avatar\PortraitRenderer;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

/** Development-only SVG sheets; generated output is deliberately outside saves. */
final class AvatarContactSheetCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services, private readonly ?SaveStore $saveStore = null)
    {
    }

    public function name(): string { return 'avatar:contact-sheet'; }

    public function description(): string { return 'Render Avatar V1 category, preset and deterministic NPC contact sheets.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $saveId = trim((string) ($arguments[0] ?? ''));
        $directory = trim((string) ($arguments[1] ?? dirname(__DIR__, 3) . '/storage/qa/avatar'));
        if ($saveId === '') { $output->error('Usage: avatar:contact-sheet <save-id> [output-directory]'); return 1; }
        $database = ($this->saveStore ?? $this->services->saveStore())->openDatabase($saveId);
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->load($database, $saveId);
        $date = $world->currentDate($worldService->calendar());
        $players = $this->services->playerModule()->service()->repository($database)->all();
        if ($players === []) { $output->error('No players are available for a contact sheet.'); return 1; }
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) { $output->error('Unable to create output directory.'); return 1; }
        $catalog = new AvatarCatalog();
        $generator = new PlayerAppearanceGenerator($catalog);
        $renderer = new PortraitRenderer($catalog, $directory . '/cache');
        $sample = $players[0];
        $base = $generator->generate($sample, $date);
        $fields = ['face' => 'face', 'eyes' => 'eyes', 'nose' => 'nose', 'mouth' => 'mouth', 'hair' => 'hair', 'beard' => 'facial_hair'];
        foreach ($fields as $label => $field) {
            $cards = [];
            $category = $field === 'facial_hair' ? 'facial_hair' : $field;
            foreach ($catalog->assets($category) as $asset) { $cards[] = [$generator->generate($sample, $date)->withChanges([$field => $asset['id']]), (string) $asset['label']]; }
            $this->writeSheet($directory . '/' . $label . '.svg', $cards, $renderer, $sample, $date, $label);
        }
        $presetCards = [];
        foreach ($catalog->presets() as $preset) { $appearance = $generator->preset((string) $preset['id']); if ($appearance !== null) { $presetCards[] = [$appearance, (string) $preset['label']]; } }
        $this->writeSheet($directory . '/presets.svg', $presetCards, $renderer, $sample, $date, 'presets');
        $npcCards = [];
        foreach (array_slice($players, 0, 100) as $player) { $npcCards[] = [$generator->generate($player, $date), $player->preferredName()]; }
        $this->writeSheet($directory . '/npc-100.svg', $npcCards, $renderer, $sample, $date, 'npc-100');
        $output->write('AVATAR CONTACT SHEETS: ' . $directory);
        return 0;
    }

    /** @param list<array{0:\Goal\Legacy\Modules\Player\Domain\PlayerAppearance,1:string}> $cards */
    private function writeSheet(string $path, array $cards, PortraitRenderer $renderer, \Goal\Legacy\Modules\Player\Domain\Player $sample, SimulationDate $date, string $label): void
    {
        $columns = 8; $tile = 150; $rows = max(1, (int) ceil(count($cards) / $columns));
        $parts = [sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d"><rect width="100%%" height="100%%" fill="#F2F5FA"/><text x="18" y="28" font-family="sans-serif" font-size="20" fill="#182132">%s</text>', $columns * $tile, ($rows + 1) * $tile, $columns * $tile, ($rows + 1) * $tile, htmlspecialchars($label, ENT_QUOTES | ENT_XML1))];
        foreach ($cards as $index => [$appearance, $caption]) {
            $x = ($index % $columns) * $tile; $y = (intdiv($index, $columns) + 1) * $tile;
            $portrait = $renderer->renderSvg($appearance, PortraitContext::forPlayer($sample, $date), 128);
            preg_match('/<svg[^>]*>(.*)<\/svg>/s', $portrait, $match);
            $parts[] = sprintf('<g transform="translate(%d %d)">%s<text x="75" y="145" text-anchor="middle" font-family="sans-serif" font-size="11" fill="#182132">%s</text></g>', $x + 11, $y + 4, $match[1] ?? '', htmlspecialchars($caption, ENT_QUOTES | ENT_XML1));
        }
        $parts[] = '</svg>\n';
        file_put_contents($path, implode("\n", $parts), LOCK_EX);
    }
}
