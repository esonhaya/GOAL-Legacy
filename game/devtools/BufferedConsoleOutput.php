<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools;

final class BufferedConsoleOutput implements ConsoleOutputInterface
{
    /** @var list<string> */
    private array $messages = [];

    /** @var list<string> */
    private array $errors = [];

    public function write(string $message): void { $this->messages[] = $message; }

    public function error(string $message): void { $this->errors[] = $message; }

    /** @return list<string> */
    public function messages(): array { return $this->messages; }

    /** @return list<string> */
    public function errors(): array { return $this->errors; }
}
