<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;

final class CareerHomeCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services)
    {
    }

    public function name(): string { return 'career:home'; }

    public function description(): string { return 'Open the persisted Career Home for a save ID.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $saveId = trim((string) ($arguments[0] ?? ''));
        if ($saveId === '') { $output->error('Usage: career:home <save-id>'); return 1; }
        $database = $this->services->saveStore()->openDatabase($saveId);
        $world = $this->services->worldModule()->service()->load($database, $saveId);
        $career = $this->services->playerModule()->service()->careerRepository($database)->get($saveId);
        $summary = (new PlayerCareerProgressionQuery($this->services->clubModule()->service()))->summary($database, $career->playerId(), $world->currentDate($this->services->worldModule()->service()->calendar()), $world->currentSeasonId());
        $player = $summary['player'];
        $output->write(sprintf('CAREER HOME — %s | age %d | %s | %s cm / %s kg | %s | OVR %d | potential %d | %s', $player['preferred_name'], $summary['age'], $player['primary_nation_id'], $player['height_cm'], $player['weight_kg'], $player['primary_position'], $summary['current_ovr'], $summary['potential'], $summary['development_profile']));
        $output->write(sprintf('Club: %s | %s (tier %s) | role: %s | contract: %s', $summary['current_club']['name'] ?? 'none', $summary['current_competition']['name'] ?? 'none', $summary['current_competition']['tier'] ?? 'n/a', $summary['current_role'] ?? 'none', $summary['current_contract']['status'] ?? 'none'));
        $output->write(sprintf('Recent form: %s (%s rated appearances) | Season: %s | Next fixture: %s', $summary['recent_form']['classification'], $summary['recent_form']['rated_appearances'], $summary['season_performance']['classification'] ?? 'insufficient_evidence', $summary['next_scheduled_match']['date'] ?? 'not scheduled'));
        $output->write('Actions: CONTINUE (career game loop is DOMAIN-037) | CAREER (this Home) | WORLD/COMPETITION (existing data commands) | SAVE/EXIT (automatic persistence).');
        return 0;
    }
}
