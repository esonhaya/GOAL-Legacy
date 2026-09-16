<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\SaveStore;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Goal\Legacy\Devtools\Presentation\CareerFormatter;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;

/** Resolve one persisted between-match career event. */
final class CareerEventCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services, private readonly ?SaveStore $saveStore = null)
    {
    }

    public function name(): string { return 'career:event'; }

    public function description(): string { return 'View or resolve the pending between-match career event.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $saveId = trim((string) ($arguments[0] ?? ''));
        if ($saveId === '') { $output->error('Usage: career:event <save-id> [choice-number]'); return 1; }
        $database = ($this->saveStore ?? $this->services->saveStore())->openDatabase($saveId);
        $career = (new CareerPlayerRepository($database))->get($saveId);
        $experience = $this->services->playerModule()->service()->careerExperienceService();
        $event = $experience->pendingEvent($database, $career->playerId());
        if ($event === null) { $output->write('CAREER EVENT — no unresolved event is waiting.'); return 0; }
        $formatter = new CareerFormatter();
        $view = $event->toArray();
        $choice = filter_var($arguments[1] ?? null, FILTER_VALIDATE_INT);
        if ($choice === false || $choice === null) {
            foreach ($formatter->careerEvent($view) as $line) { $output->write($line); }
            return 0;
        }
        if ($choice < 1 || $choice > count($event->choices())) {
            foreach ($formatter->careerEvent($view) as $line) { $output->write($line); }
            $output->write('Please choose one of the listed event choices.');
            return 0;
        }
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->load($database, $saveId);
        $date = $world->currentDate($worldService->calendar());
        $resolved = $experience->resolve($database, $event->id(), $choice, $date);
        foreach ($formatter->careerEventResolved($resolved->toArray()) as $line) { $output->write($line); }
        (new CareerHomeCommand($this->services, $this->saveStore))->execute([$saveId], $output);

        return 0;
    }
}
