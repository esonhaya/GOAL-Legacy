<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\SaveStore;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Goal\Legacy\Modules\Match\Domain\SelectionStatus;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\SeasonStatus;
use RuntimeException;

/** Advance one controlled-career meaningful stop through canonical services. */
final class CareerContinueCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services, private readonly ?SaveStore $saveStore = null)
    {
    }

    public function name(): string { return 'career:continue'; }

    public function description(): string { return 'Advance a career to its next controlled Match or pending decision.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $saveId = trim((string) ($arguments[0] ?? ''));
        if ($saveId === '') { $output->error('Usage: career:continue <save-id>'); return 1; }
        $database = ($this->saveStore ?? $this->services->saveStore())->openDatabase($saveId);
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->load($database, $saveId);
        $career = (new CareerPlayerRepository($database))->get($saveId);
        $date = $world->currentDate($worldService->calendar());
        $query = new PlayerCareerProgressionQuery($this->services->clubModule()->service());
        $summary = $query->summary($database, $career->playerId(), $date, $world->currentSeasonId());

        if (($summary['pending_decisions'] ?? []) !== []) {
            $this->decision($output, $summary['pending_decisions']);
            return 0;
        }
        $next = $summary['next_scheduled_match'] ?? null;
        if (!is_array($next) || !isset($next['date'])) {
            $season = $worldService->seasonRepository($database)->get($world->currentSeasonId());
            if ($world->currentSeasonId() !== null && $season->status() === SeasonStatus::Active && !$date->isBefore($season->endDate())) {
                $worldService->advanceToDate($database, $saveId, $season->endDate()->addDays(1));
                $output->write(sprintf('SEASON END — %s completed; rollover preparation is persisted.', $season->label()));
                return 0;
            }
            if ($world->currentSeasonId() !== null && $season->status() === SeasonStatus::Completed) {
                $nextSeason = $worldService->seasonRollover()?->nextSeason($season);
                if ($nextSeason !== null) {
                    $worldService->advanceToDate($database, $saveId, $nextSeason->startDate());
                    $output->write(sprintf('SEASON ROLLOVER — %s is now active.', $nextSeason->label()));
                    return 0;
                }
            }
            $output->write(sprintf('CONTINUE — no future controlled fixture is scheduled from %s. Review CAREER actions or wait for the next Season.', $date->toIsoString()));
            return 0;
        }
        $target = SimulationDate::fromIsoString((string) $next['date']);
        if ($target->isBefore($date)) {
            throw new RuntimeException('Career Continue found a stale fixture in the past.');
        }
        if ($target->toIsoString() === $date->toIsoString()) {
            $completed = $this->services->matchModule()->service()->simulateDue($database, $target);
        } else {
            $worldService->advanceToDate($database, $saveId, $target);
            $completed = $this->services->matchModule()->service()->simulateDue($database, $target);
        }
        $clubId = $summary['current_club']['id'] ?? null;
        $controlled = array_values(array_filter($completed, static fn ($match): bool => $clubId !== null && ($match->homeClubId()->value() === $clubId || $match->awayClubId()->value() === $clubId)));
        if ($controlled === []) {
            throw new RuntimeException('Career Continue advanced to a date without completing the controlled Club fixture.');
        }
        $match = $controlled[array_key_last($controlled)];
        $this->matchResult($output, $database, $match, $career->playerId()->value());
        $newWorld = $worldService->load($database, $saveId);
        $fresh = $query->summary($database, $career->playerId(), $newWorld->currentDate($worldService->calendar()), $newWorld->currentSeasonId());
        $this->home($output, $fresh, $newWorld->currentDate($worldService->calendar()));
        return 0;
    }

    /** @param list<array<string, mixed>> $decisions */
    private function decision(ConsoleOutputInterface $output, array $decisions): void
    {
        $output->write('CAREER DECISION — Continue is paused until the Player decision is resolved.');
        foreach ($decisions as $decision) {
            $options = $decision['options'] ?? [];
            $output->write(sprintf('  %s: %s', $decision['type'] ?? 'decision', $this->optionText($options)));
        }
    }

    private function optionText(mixed $options): string
    {
        if (!is_array($options) || $options === []) { return 'review the available Career action'; }
        $labels = [];
        foreach ($options as $key => $option) { $labels[] = is_array($option) ? (string) ($option['label'] ?? $option['id'] ?? $key) : (string) $key; }
        return implode(', ', $labels);
    }

    private function matchResult(ConsoleOutputInterface $output, \Goal\Legacy\Core\Persistence\DatabaseInterface $database, object $match, string $playerId): void
    {
        $matches = $this->services->matchModule()->service();
        $clubs = $this->services->clubModule()->service()->repository($database);
        $home = $clubs->get($match->homeClubId())->canonicalName();
        $away = $clubs->get($match->awayClubId())->canonicalName();
        $result = $match->result();
        $output->write(sprintf('MATCH RESULT — %s | %s %d-%d %s', $match->scheduledDate()->toIsoString(), $home, $result?->homeGoals() ?? 0, $result?->awayGoals() ?? 0, $away));
        $performance = $matches->playerSummary($database, $match->id(), $playerId);
        if ($performance === null) {
            $selection = array_values(array_filter((new MatchSelectionRepository($database))->byMatch($match->id()), static fn ($row): bool => $row->playerId()->value() === $playerId))[0] ?? null;
            $status = $selection?->status()->value ?? SelectionStatus::NotSelected->value;
            $output->write(sprintf('YOUR PERFORMANCE — %s; no recorded appearance.', strtoupper(str_replace('_', ' ', $status))));
        } else {
            $output->write(sprintf('YOUR PERFORMANCE — %s | %d min | goals %d | assists %d | shots %d/%d | cards %dY/%dR | rating %s', $this->participation($performance), $performance['minutes'], $performance['goals'], $performance['assists'], $performance['shots'], $performance['shots_on_target'], $performance['yellow_cards'], $performance['red_cards'], $performance['rating'] === null ? 'n/a' : number_format((float) $performance['rating'], 2)));
        }
        $highlights = $matches->highlightRepository($database)->byMatch($match->id());
        $output->write('HIGHLIGHTS — ' . ($highlights === [] ? 'none recorded.' : implode('; ', array_map(static fn ($highlight): string => sprintf('%d\' %s', $highlight->minute(), $highlight->type()), $highlights))));
        $table = $matches->standings($database, $match->competitionId(), $match->seasonId());
        $position = null;
        foreach ($table as $index => $row) { if (($row['club_id'] ?? null) === $match->homeClubId()->value() || ($row['club_id'] ?? null) === $match->awayClubId()->value()) { $position = $index + 1; break; } }
        if ($position !== null) { $output->write(sprintf('TABLE — position %d after this result.', $position)); }
    }

    /** @param array<string, mixed> $performance */
    private function participation(array $performance): string
    {
        if (!$performance['appeared']) { return 'UNUSED_SUBSTITUTE'; }

        return $performance['started'] ? 'STARTER' : 'SUBSTITUTE_USED';
    }

    /** @param array<string, mixed> $summary */
    private function home(ConsoleOutputInterface $output, array $summary, SimulationDate $date): void
    {
        $player = $summary['player'];
        $next = $summary['next_scheduled_match'] ?? null;
        $nextText = is_array($next) ? sprintf('%s vs %s', $next['date'], $next['opponent_club_id']) : 'not scheduled';
        $output->write(sprintf('CAREER HOME — %s | date %s | age %d | %s | %s cm / %s kg | %s | OVR %d | potential %d | %s', $player['preferred_name'], $date->toIsoString(), $summary['age'], $player['primary_nation_id'], $player['height_cm'], $player['weight_kg'], $player['primary_position'], $summary['current_ovr'], $summary['potential'], $summary['development_profile']));
        $output->write(sprintf('Club: %s | role: %s | contract: %s', $summary['current_club']['name'] ?? 'none', $summary['current_role'] ?? 'none', $summary['current_contract']['status'] ?? 'none'));
        $output->write(sprintf('Recent form: %s | Season: %s | Next fixture: %s', $summary['recent_form']['classification'], $summary['season_performance']['classification'] ?? 'insufficient_evidence', $nextText));
    }
}
