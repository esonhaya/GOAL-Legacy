<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools;

use Throwable;

final class ConsoleApplication
{
    public function __construct(private readonly CommandRegistry $commands)
    {
    }

    /** @param list<string> $arguments */
    public function run(array $arguments, ConsoleOutputInterface $output): int
    {
        $commandName = $arguments[1] ?? 'help';
        if ($commandName === 'help' || $commandName === '--help' || $commandName === '-h') {
            $this->printHelp($output);
            return 0;
        }

        $command = $this->commands->get($commandName);
        if ($command === null) {
            $output->error(sprintf('Unknown command "%s".', $commandName));
            $this->printHelp($output);
            return 1;
        }

        try {
            return $command->execute(array_values(array_slice($arguments, 2)), $output);
        } catch (Throwable $exception) {
            $output->error(sprintf('Command failed: %s', $exception->getMessage()));
            return 1;
        }
    }

    private function printHelp(ConsoleOutputInterface $output): void
    {
        $output->write('GOAL: Legacy developer console');
        foreach ($this->commands->all() as $command) {
            $output->write(sprintf('  %-20s %s', $command->name(), $command->description()));
        }
    }
}
