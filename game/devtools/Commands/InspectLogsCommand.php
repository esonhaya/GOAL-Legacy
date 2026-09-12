<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;

final class InspectLogsCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services, private readonly string $projectRoot)
    {
    }

    public function name(): string { return 'logs:recent'; }

    public function description(): string { return 'Inspect recent Core log status and entries.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $path = $this->services->configuration()->string('logging.path');
        if (!str_starts_with($path, '/')) {
            $path = $this->projectRoot . '/' . $path;
        }
        $output->write('Log file: ' . $path);
        if (!is_file($path)) {
            $output->write('No Core log file exists yet.');
            return 0;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            $output->error('Unable to read the Core log file.');
            return 1;
        }
        $limit = isset($arguments[0]) && ctype_digit($arguments[0]) ? max(1, (int) $arguments[0]) : 20;
        foreach (array_slice($lines, -$limit) as $line) {
            $output->write($line);
        }
        return 0;
    }
}
