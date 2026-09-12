<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools;

use InvalidArgumentException;

final class CommandRegistry
{
    /** @var array<string, CommandInterface> */
    private array $commands = [];

    public function register(CommandInterface $command): void
    {
        $name = trim($command->name());
        if ($name === '') {
            throw new InvalidArgumentException('Command names cannot be empty.');
        }
        if (isset($this->commands[$name])) {
            throw new InvalidArgumentException(sprintf('Command "%s" is already registered.', $name));
        }
        $this->commands[$name] = $command;
    }

    public function get(string $name): ?CommandInterface
    {
        return $this->commands[$name] ?? null;
    }

    /** @return list<CommandInterface> */
    public function all(): array
    {
        return array_values($this->commands);
    }
}
