<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SaveStore;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Goal\Legacy\Devtools\Presentation\CareerFormatter;
use Goal\Legacy\Devtools\Presentation\CareerLabels;
use Goal\Legacy\Devtools\Presentation\CareerPresentationService;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\World\Domain\SeasonStatus;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use RuntimeException;

/** Advance one controlled-career meaningful stop through canonical services. */
final class CareerContinueCommand implements CommandInterface
{
    public function __construct(public readonly CoreServices $services, private readonly ?SaveStore $saveStore = null)
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
            $output->write('CONTINUE — no future controlled fixture is scheduled. Review Career actions or wait for the next Season.');
            return 0;
        }
        $target = SimulationDate::fromIsoString((string) $next['date']);
        if ($target->isBefore($date)) {
            throw new RuntimeException('Career Continue found a stale fixture in the past.');
        }
        if ($target->toIsoString() !== $date->toIsoString()) {
            $worldService->advanceToDate($database, $saveId, $target);
        }
        $completed = $this->services->matchModule()->service()->simulateDue($database, $target);
        $clubId = $summary['current_club']['id'] ?? null;
        $controlled = array_values(array_filter($completed, static fn ($match): bool => $clubId !== null && ($match->homeClubId()->value() === $clubId || $match->awayClubId()->value() === $clubId)));
        if ($controlled === []) {
            throw new RuntimeException('Career Continue advanced to a date without completing the controlled Club fixture.');
        }
        $match = $controlled[array_key_last($controlled)];
        $presentation = new CareerPresentationService($this->services);
        $formatter = new CareerFormatter();
        foreach ($formatter->matchday($presentation->matchday($database, $match, $career->playerId()->value(), is_string($clubId) ? $clubId : null)) as $line) {
            $output->write($line);
        }
        $newSnapshot = $presentation->snapshot($database, $saveId);
        foreach ($formatter->home(
            $newSnapshot['summary'],
            $newSnapshot['date']->toIsoString(),
            $presentation->nextMatch($database, $newSnapshot['summary']),
            $presentation->clubContext($database, $newSnapshot['summary']),
        ) as $line) {
            $output->write($line);
        }

        return 0;
    }

    /** @param list<array<string, mixed>> $decisions */
    private function decision(ConsoleOutputInterface $output, array $decisions): void
    {
        $output->write('CAREER DECISION — Continue is paused until your decision is resolved.');
        foreach ($decisions as $decision) {
            $options = $decision['options'] ?? [];
            $output->write(sprintf('  %s: %s', CareerLabels::value($decision['type'] ?? null), $this->optionText($options)));
        }
    }

    private function optionText(mixed $options): string
    {
        if (!is_array($options) || $options === []) { return 'review the available Career action'; }
        $labels = [];
        foreach (array_values($options) as $index => $option) {
            $label = is_array($option) ? ($option['label'] ?? null) : null;
            $labels[] = ($index + 1) . '. ' . (is_string($label) && trim($label) !== '' ? $label : 'available choice');
        }
        return implode(', ', $labels);
    }
}
