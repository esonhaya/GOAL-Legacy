<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\SaveStore;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Goal\Legacy\Devtools\Presentation\CareerFormatter;
use Goal\Legacy\Devtools\Presentation\CareerPresentationService;

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
                (new CareerContinueCommand($this->services, $this->saveStore))->execute([$saveId], $output);
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
            $action = $this->actionForChoice($saveId, $choice);
            if ($action !== null) {
                (new CareerActionCommand($this->services, $this->saveStore))->execute([$action, $saveId], $output);
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
        $number = 4;
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
        $number = 4;
        foreach (($snapshot['summary']['available_actions'] ?? []) as $action) {
            $type = is_array($action) ? ($action['type'] ?? null) : null;
            if (in_array($type, ['request_transfer', 'withdraw_transfer_request'], true)) { ++$number; }
        }

        return (string) $number === $choice;
    }
}
