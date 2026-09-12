<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools;

final class StreamConsoleOutput implements ConsoleOutputInterface
{
    /** @param resource $output @param resource $error */
    public function __construct(private $output, private $error)
    {
    }

    public function write(string $message): void
    {
        fwrite($this->output, $message . PHP_EOL);
    }

    public function error(string $message): void
    {
        fwrite($this->error, $message . PHP_EOL);
    }
}
