<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools;

interface CommandInterface
{
    public function name(): string;

    public function description(): string;

    /** @param list<string> $arguments */
    public function execute(array $arguments, ConsoleOutputInterface $output): int;
}
