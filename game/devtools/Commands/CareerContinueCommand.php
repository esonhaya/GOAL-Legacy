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
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
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
        $experience = $this->services->playerModule()->service()->careerExperienceService();

        if (($summary['pending_decisions'] ?? []) !== []) {
            $presentation = new CareerPresentationService($this->services);
            $decision = $presentation->decision($summary, $database);
            if ($decision === null) {
                $this->decision($output, $summary['pending_decisions']);
            } else {
                foreach ((new CareerFormatter())->decision($decision) as $line) { $output->write($line); }
            }
            return 0;
        }
        $pendingEvent = $experience->pendingEvent($database, $career->playerId());
        if ($pendingEvent !== null) {
            $this->renderEvent($output, $pendingEvent->toArray());
            return 0;
        }
        $next = $summary['next_scheduled_match'] ?? null;
        if (is_array($next) && isset($next['date'], $next['match_id']) && $world->currentSeasonId() !== null) {
            $event = $experience->ensureEvent($database, $career->playerId(), $world->currentSeasonId(), $date, $summary);
            if ($event !== null) {
                $this->renderEvent($output, $event->toArray());
                return 0;
            }
        }
        while (!is_array($next) || !isset($next['date'])) {
            $season = $worldService->seasonRepository($database)->get($world->currentSeasonId());
            $competitionIds = array_fill_keys($world->competitionIds(), true);
            $scheduled = array_values(array_filter(
                (new MatchRepository($database))->byStatus(MatchStatus::Scheduled),
                static fn ($match): bool => $world->currentSeasonId() !== null
                    && $match->seasonId()->value() === $world->currentSeasonId()->value()
                    && isset($competitionIds[$match->competitionId()->value()]),
            ));
            usort($scheduled, static fn ($left, $right): int => strcmp($left->scheduledDate()->toIsoString() . $left->id()->value(), $right->scheduledDate()->toIsoString() . $right->id()->value()));
            $nextWorldMatch = null;
            foreach ($scheduled as $candidate) {
                if (!$candidate->scheduledDate()->isBefore($date)) { $nextWorldMatch = $candidate; break; }
            }
            if ($nextWorldMatch !== null && $season->status() === SeasonStatus::Active) {
                $targetWorldDate = $nextWorldMatch->scheduledDate();
                $clubId = $summary['current_club']['id'] ?? null;
                $isControlledFixture = is_string($clubId) && ($nextWorldMatch->homeClubId()->value() === $clubId || $nextWorldMatch->awayClubId()->value() === $clubId);
                if ($isControlledFixture && $world->currentSeasonId() !== null) {
                    $event = $experience->ensureEvent($database, $career->playerId(), $world->currentSeasonId(), $date, $summary);
                    if ($event !== null) {
                        $this->renderEvent($output, $event->toArray());
                        return 0;
                    }
                    $experience->prepareTraining($database, $career->playerId(), $nextWorldMatch->id()->value(), $date, $targetWorldDate);
                }
                if ($targetWorldDate->toIsoString() !== $date->toIsoString()) {
                    $worldService->advanceToDate($database, $saveId, $targetWorldDate);
                }
                $completed = $this->services->matchModule()->service()->simulateDue($database, $targetWorldDate);
                $controlled = array_values(array_filter($completed, static fn ($match): bool => $clubId !== null && ($match->homeClubId()->value() === $clubId || $match->awayClubId()->value() === $clubId)));
                if ($controlled !== []) {
                    $this->renderMatch($database, $saveId, $output, $career->playerId()->value(), $controlled[array_key_last($controlled)], is_string($clubId) ? $clubId : null);
                    return 0;
                }
                $world = $worldService->load($database, $saveId);
                $date = $world->currentDate($worldService->calendar());
                $summary = $query->summary($database, $career->playerId(), $date, $world->currentSeasonId());
                if (($summary['pending_decisions'] ?? []) !== []) {
                    $presentation = new CareerPresentationService($this->services);
                    $decision = $presentation->decision($summary, $database);
                    if ($decision !== null) {
                        foreach ((new CareerFormatter())->decision($decision) as $line) { $output->write($line); }
                    }
                    return 0;
                }
                $next = $summary['next_scheduled_match'] ?? null;
                if (is_array($next) && isset($next['date'], $next['match_id']) && $world->currentSeasonId() !== null) {
                    $event = $experience->ensureEvent($database, $career->playerId(), $world->currentSeasonId(), $date, $summary);
                    if ($event !== null) {
                        $this->renderEvent($output, $event->toArray());
                        return 0;
                    }
                }
                continue;
            }
            if ($world->currentSeasonId() !== null && $season->status() === SeasonStatus::Active && $worldService->seasonRollover()?->competitionsComplete($database, $world, $season) === true) {
                $worldService->advanceToDate($database, $saveId, $season->endDate()->addDays(1));
                $snapshot = (new CareerPresentationService($this->services))->snapshot($database, $saveId);
                $output->write(sprintf('SEASON END — %s completed; rollover preparation is persisted.', $season->label()));
                foreach ((new CareerFormatter())->seasonSummary((new CareerPresentationService($this->services))->seasonSummary($database, $snapshot['summary'], $season->id()->value())) as $line) {
                    $output->write($line);
                }
                return 0;
            }
            if ($world->currentSeasonId() !== null && $season->status() === SeasonStatus::Completed) {
                $nextSeason = $worldService->seasonRollover()?->nextSeason($season);
                if ($nextSeason !== null) {
                    $worldService->advanceToDate($database, $saveId, $nextSeason->startDate());
                    $output->write(sprintf('SEASON ROLLOVER — %s is now active.', $nextSeason->label()));
                    $snapshot = (new CareerPresentationService($this->services))->snapshot($database, $saveId);
                    $presentation = new CareerPresentationService($this->services);
                    foreach ((new CareerFormatter())->rollover($presentation->rolloverSummary($snapshot['summary'], $season->id()->value())) as $line) {
                        $output->write($line);
                    }
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
            $experience->prepareTraining($database, $career->playerId(), (string) $next['match_id'], $date, $target);
            $worldService->advanceToDate($database, $saveId, $target);
        } else {
            $experience->prepareTraining($database, $career->playerId(), (string) $next['match_id'], $date, $target);
        }
        $completed = $this->services->matchModule()->service()->simulateDue($database, $target);
        $clubId = $summary['current_club']['id'] ?? null;
        $controlled = array_values(array_filter($completed, static fn ($match): bool => $clubId !== null && ($match->homeClubId()->value() === $clubId || $match->awayClubId()->value() === $clubId)));
        if ($controlled === []) {
            throw new RuntimeException('Career Continue advanced to a date without completing the controlled Club fixture.');
        }
        $match = $controlled[array_key_last($controlled)];
        $this->renderMatch($database, $saveId, $output, $career->playerId()->value(), $match, is_string($clubId) ? $clubId : null);

        return 0;
    }

    private function renderMatch(DatabaseInterface $database, string $saveId, ConsoleOutputInterface $output, string $playerId, GameMatch $match, ?string $clubId): void
    {
        $presentation = new CareerPresentationService($this->services);
        $formatter = new CareerFormatter();
        foreach ($formatter->matchday($presentation->matchday($database, $match, $playerId, $clubId)) as $line) {
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
    }

    /** @param array<string, mixed> $event */
    private function renderEvent(ConsoleOutputInterface $output, array $event): void
    {
        foreach ((new CareerFormatter())->careerEvent($event) as $line) { $output->write($line); }
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
