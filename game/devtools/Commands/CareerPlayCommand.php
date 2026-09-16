<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\SaveStore;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Goal\Legacy\Devtools\Presentation\CareerFormatter;
use Goal\Legacy\Devtools\Presentation\CareerPresentationService;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;

/** Small stdin menu for CLI Phase 1 career navigation. */
final class CareerPlayCommand implements CommandInterface
{
    /** @param resource|null $input */
    public function __construct(private readonly CoreServices $services, private readonly ?SaveStore $saveStore = null, private $input = null)
    {
    }

    public function name(): string { return 'career:play'; }

    public function description(): string { return 'Play a career through the readable CLI screens.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $saveId = trim((string) ($arguments[0] ?? ''));
        if ($saveId === '') { $output->error('Usage: career:play <save-id>'); return 1; }
        $store = $this->saveStore ?? $this->services->saveStore();
        $store->openDatabase($saveId);
        $this->home($saveId, $output);

        while (true) {
            $choice = $this->readChoice();
            if ($choice === null) {
                $output->write('SAVE & EXIT — progress is saved automatically.');
                return 0;
            }
            if ($choice === '1') {
                if ($this->hasPendingDecision($saveId)) {
                    $this->resolvePendingDecision($saveId, $output);
                } elseif ($this->hasPendingEvent($saveId)) {
                    $this->resolvePendingEvent($saveId, $output);
                } else {
                    (new CareerContinueCommand($this->services, $this->saveStore))->execute([$saveId], $output);
                    if ($this->hasPendingEvent($saveId)) {
                        $this->resolvePendingEvent($saveId, $output, false);
                    }
                }
                continue;
            }
            if ($choice === '2') {
                (new CareerViewCommand($this->services, $this->saveStore))->execute([$saveId], $output);
                $this->returnToHome($output);
                $this->home($saveId, $output);
                continue;
            }
            if ($choice === '3') {
                (new CareerWorldCommand($this->services, $this->saveStore))->execute([$saveId], $output);
                $this->returnToHome($output);
                $this->home($saveId, $output);
                continue;
            }
            if ($choice === '4') {
                (new CareerNewsCommand($this->services, $this->saveStore))->execute([$saveId], $output);
                $this->returnToHome($output);
                $this->home($saveId, $output);
                continue;
            }
            if ($choice === '5') {
                (new CareerTrainingCommand($this->services, $this->saveStore, $this->input))->execute([$saveId], $output);
                $this->home($saveId, $output);
                continue;
            }
            $action = $this->actionForChoice($saveId, $choice);
            if ($action !== null) {
                if ($action === 'decision') {
                    $this->resolvePendingDecision($saveId, $output);
                } else {
                    (new CareerActionCommand($this->services, $this->saveStore, $this->input))->execute([$action, $saveId], $output);
                }
                continue;
            }
            if ($this->isSaveChoice($saveId, $choice)) {
                $output->write('SAVE & EXIT — progress is saved automatically.');
                return 0;
            }
            $output->write('Please choose one of the listed actions.');
        }
    }

    private function home(string $saveId, ConsoleOutputInterface $output): void
    {
        (new CareerHomeCommand($this->services, $this->saveStore))->execute([$saveId], $output);
    }

    private function returnToHome(ConsoleOutputInterface $output): void
    {
        while (true) {
            $choice = $this->readChoice();
            if ($choice === null || $choice === '1') { return; }
            $output->write('Please choose 1 to return to Career Home.');
        }
    }

    private function readChoice(): ?string
    {
        $input = $this->input ?? STDIN;
        $line = fgets($input);
        if ($line === false) { return null; }

        return trim($line);
    }

    private function actionForChoice(string $saveId, string $choice): ?string
    {
        $database = ($this->saveStore ?? $this->services->saveStore())->openDatabase($saveId);
        $snapshot = (new CareerPresentationService($this->services))->snapshot($database, $saveId);
        $number = 6;
        $hasDecision = false;
        foreach (($snapshot['summary']['available_actions'] ?? []) as $action) {
            $type = is_array($action) ? ($action['type'] ?? null) : null;
            if ($type === 'resolve_opportunity') {
                $hasDecision = true;
            }
        }
        if ($hasDecision) {
            if ((string) $number === $choice) { return 'decision'; }
            ++$number;
        }
        foreach (($snapshot['summary']['available_actions'] ?? []) as $action) {
            $type = is_array($action) ? ($action['type'] ?? null) : null;
            if (!in_array($type, ['request_transfer', 'withdraw_transfer_request'], true)) { continue; }
            if ((string) $number === $choice) { return $type === 'request_transfer' ? 'request-transfer' : 'withdraw-transfer'; }
            ++$number;
        }

        return null;
    }

    private function isSaveChoice(string $saveId, string $choice): bool
    {
        $database = ($this->saveStore ?? $this->services->saveStore())->openDatabase($saveId);
        $snapshot = (new CareerPresentationService($this->services))->snapshot($database, $saveId);
        $number = 6;
        if ($this->hasDecision($snapshot['summary'])) { ++$number; }
        foreach (($snapshot['summary']['available_actions'] ?? []) as $action) {
            $type = is_array($action) ? ($action['type'] ?? null) : null;
            if (in_array($type, ['request_transfer', 'withdraw_transfer_request'], true)) { ++$number; }
        }

        return (string) $number === $choice;
    }

    /** @param array<string, mixed> $summary */
    private function hasDecision(array $summary): bool
    {
        foreach (($summary['available_actions'] ?? []) as $action) {
            if (is_array($action) && ($action['type'] ?? null) === 'resolve_opportunity') {
                return true;
            }
        }

        return false;
    }

    private function hasPendingDecision(string $saveId): bool
    {
        $database = ($this->saveStore ?? $this->services->saveStore())->openDatabase($saveId);
        $snapshot = (new CareerPresentationService($this->services))->snapshot($database, $saveId);

        return $this->hasDecision($snapshot['summary']);
    }

    private function hasPendingEvent(string $saveId): bool
    {
        $database = ($this->saveStore ?? $this->services->saveStore())->openDatabase($saveId);
        $career = (new CareerPlayerRepository($database))->get($saveId);

        return $this->services->playerModule()->service()->careerExperienceService()->pendingEvent($database, $career->playerId()) !== null;
    }

    private function resolvePendingEvent(string $saveId, ConsoleOutputInterface $output, bool $display = true): void
    {
        $database = ($this->saveStore ?? $this->services->saveStore())->openDatabase($saveId);
        $career = (new CareerPlayerRepository($database))->get($saveId);
        $experience = $this->services->playerModule()->service()->careerExperienceService();
        $formatter = new CareerFormatter();
        while (true) {
            $event = $experience->pendingEvent($database, $career->playerId());
            if ($event === null) { return; }
            if ($display) {
                foreach ($formatter->careerEvent($event->toArray()) as $line) { $output->write($line); }
            }
            $display = true;
            $choice = $this->readChoice();
            if ($choice === null) {
                $output->write('SAVE & EXIT — progress is saved automatically.');
                return;
            }
            $option = filter_var($choice, FILTER_VALIDATE_INT);
            if ($option === false || $option < 1 || $option > count($event->choices())) {
                $output->write('Please choose one of the listed event choices.');
                continue;
            }
            $worldService = $this->services->worldModule()->service();
            $world = $worldService->load($database, $saveId);
            $date = $world->currentDate($worldService->calendar());
            $resolved = $experience->resolve($database, $event->id(), $option, $date);
            foreach ($formatter->careerEventResolved($resolved->toArray()) as $line) { $output->write($line); }
            $this->home($saveId, $output);
            return;
        }
    }

    private function resolvePendingDecision(string $saveId, ConsoleOutputInterface $output): void
    {
        $database = ($this->saveStore ?? $this->services->saveStore())->openDatabase($saveId);
        $presentation = new CareerPresentationService($this->services);
        $snapshot = $presentation->snapshot($database, $saveId);
        $decision = $presentation->decision($snapshot['summary'], $database);
        if ($decision === null) { return; }
        $formatter = new CareerFormatter();
        while (true) {
            foreach ($formatter->decision($decision) as $line) { $output->write($line); }
            $choice = $this->readChoice();
            if ($choice === null) {
                $output->write('SAVE & EXIT — progress is saved automatically.');
                return;
            }
            $option = filter_var($choice, FILTER_VALIDATE_INT);
            if ($option === false || $option < 1 || $option > count($decision['options'] ?? [])) {
                $output->write('Please choose one of the listed decision options.');
                continue;
            }
            (new CareerActionCommand($this->services, $this->saveStore, $this->input))->execute(['decide', $saveId, (string) $option], $output);
            return;
        }
    }
}
