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
use Goal\Legacy\Modules\Player\Domain\CareerPriority;
use Goal\Legacy\Modules\Player\Domain\TrainingFocus;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;

/** Player-facing controls for the existing deterministic development path. */
final class CareerTrainingCommand implements CommandInterface
{
    /** @param resource|null $input */
    public function __construct(private readonly CoreServices $services, private readonly ?SaveStore $saveStore = null, private $input = null)
    {
    }

    public function name(): string { return 'career:training'; }

    public function description(): string { return 'Choose a training focus or medium-term career priority.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $saveId = trim((string) ($arguments[0] ?? ''));
        if ($saveId === '') { $output->error('Usage: career:training <save-id>'); return 1; }
        while (true) {
            $database = ($this->saveStore ?? $this->services->saveStore())->openDatabase($saveId);
            $snapshot = (new CareerPresentationService($this->services))->snapshot($database, $saveId);
            foreach ((new CareerFormatter())->training($snapshot['summary']) as $line) { $output->write($line); }
            $choice = $this->readChoice();
            if ($choice === null || $choice === '3') { return 0; }
            if ($choice === '1') {
                $this->changeFocus($saveId, $output);
                continue;
            }
            if ($choice === '2') {
                $this->changePriority($saveId, $output);
                continue;
            }
            $output->write('Please choose one of the listed options.');
        }
    }

    private function changeFocus(string $saveId, ConsoleOutputInterface $output): void
    {
        $output->write('TRAINING FOCUS');
        $options = array_values(TrainingFocus::cases());
        foreach ($options as $index => $focus) { $output->write(($index + 1) . '. ' . CareerLabels::value($focus->value)); }
        $choice = filter_var($this->readChoice(), FILTER_VALIDATE_INT);
        if ($choice === false || $choice < 1 || $choice > count($options)) { $output->write('Please choose one of the listed training focuses.'); return; }
        $database = ($this->saveStore ?? $this->services->saveStore())->openDatabase($saveId);
        $date = $this->currentDate($database, $saveId);
        $playerId = (new CareerPlayerRepository($database))->get($saveId)->playerId();
        $focus = $this->services->playerModule()->service()->careerExperienceService()->setTrainingFocus($database, $playerId, $options[$choice - 1], $date);
        $output->write('TRAINING — focus changed to ' . CareerLabels::value($focus->value) . '.');
    }

    private function changePriority(string $saveId, ConsoleOutputInterface $output): void
    {
        $output->write('CAREER PRIORITY');
        $options = array_values(CareerPriority::cases());
        foreach ($options as $index => $priority) { $output->write(($index + 1) . '. ' . CareerLabels::value($priority->value)); }
        $choice = filter_var($this->readChoice(), FILTER_VALIDATE_INT);
        if ($choice === false || $choice < 1 || $choice > count($options)) { $output->write('Please choose one of the listed priorities.'); return; }
        $database = ($this->saveStore ?? $this->services->saveStore())->openDatabase($saveId);
        $date = $this->currentDate($database, $saveId);
        $playerId = (new CareerPlayerRepository($database))->get($saveId)->playerId();
        $priority = $this->services->playerModule()->service()->careerExperienceService()->setPriority($database, $playerId, $options[$choice - 1], $date);
        $output->write('CAREER — priority changed to ' . CareerLabels::value($priority->value) . '.');
    }

    private function currentDate(DatabaseInterface $database, string $saveId): \Goal\Legacy\Modules\World\Domain\SimulationDate
    {
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->load($database, $saveId);

        return $world->currentDate($worldService->calendar());
    }

    private function readChoice(): ?string
    {
        $line = fgets($this->input ?? STDIN);

        return $line === false ? null : trim($line);
    }
}
