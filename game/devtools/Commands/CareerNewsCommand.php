<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\SaveStore;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Goal\Legacy\Devtools\Presentation\CareerFormatter;
use Goal\Legacy\Devtools\Presentation\CareerPresentationService;

/** Open the bounded player-facing feed derived from canonical career facts. */
final class CareerNewsCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services, private readonly ?SaveStore $saveStore = null)
    {
    }

    public function name(): string { return 'career:news'; }

    public function description(): string { return 'Open the player-facing Career News feed for a save ID.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $saveId = trim((string) ($arguments[0] ?? ''));
        if ($saveId === '') { $output->error('Usage: career:news <save-id>'); return 1; }
        $database = ($this->saveStore ?? $this->services->saveStore())->openDatabase($saveId);
        $presentation = new CareerPresentationService($this->services);
        $snapshot = $presentation->snapshot($database, $saveId);
        foreach ((new CareerFormatter())->news($presentation->news($database, $snapshot['summary'], $snapshot['date'])) as $line) {
            $output->write($line);
        }

        return 0;
    }
}
