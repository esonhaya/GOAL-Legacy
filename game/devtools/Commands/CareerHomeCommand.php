<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\Competition\Persistence\CompetitionRepository;
use Goal\Legacy\Modules\Club\Persistence\ClubRepository;

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
        $output->write(sprintf('CAREER HOME — %s | date %s | age %d | %s | %s cm / %s kg | %s | OVR %d | potential %d | %s', $player['preferred_name'], $world->currentDate($this->services->worldModule()->service()->calendar())->toIsoString(), $summary['age'], $player['primary_nation_id'], $player['height_cm'], $player['weight_kg'], $player['primary_position'], $summary['current_ovr'], $summary['potential'], $summary['development_profile']));
        $output->write(sprintf('Club: %s | %s (tier %s) | role: %s | contract: %s', $summary['current_club']['name'] ?? 'none', $summary['current_competition']['name'] ?? 'none', $summary['current_competition']['tier'] ?? 'n/a', $summary['current_role'] ?? 'none', $summary['current_contract']['status'] ?? 'none'));
        $next = $summary['next_scheduled_match'] ?? null;
        $nextText = 'not scheduled';
        if (is_array($next)) {
            $opponent = (new ClubRepository($database))->get((string) $next['opponent_club_id'])->canonicalName();
            $competition = (new CompetitionRepository($database))->get((string) $next['competition_id']);
            $nextText = sprintf('%s vs %s (%s)', $next['date'], $opponent, $competition->name());
        }
        $output->write(sprintf('Recent form: %s (%s rated appearances) | Season: %s | Next fixture: %s', $summary['recent_form']['classification'], $summary['recent_form']['rated_appearances'], $summary['season_performance']['classification'] ?? 'insufficient_evidence', $nextText));
        $actions = ['CONTINUE: career:continue ' . $saveId, 'SAVE/EXIT: automatic persistence'];
        $actionTypes = array_column($summary['available_actions'] ?? [], 'type');
        if (in_array('request_transfer', $actionTypes, true)) { $actions[] = 'REQUEST TRANSFER: career:action request-transfer ' . $saveId; }
        if (in_array('withdraw_transfer_request', $actionTypes, true)) { $actions[] = 'WITHDRAW TRANSFER: career:action withdraw-transfer ' . $saveId; }
        if (($summary['pending_decisions'] ?? []) !== []) { $actions[] = 'DECISION: career:action decide ' . $saveId . ' <option-number>'; }
        $output->write('Actions: ' . implode(' | ', $actions) . '.');
        return 0;
    }
}
