<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools;

interface ConsoleOutputInterface
{
    public function write(string $message): void;

    public function error(string $message): void;
}
