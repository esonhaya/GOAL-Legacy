<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Fixtures;

use Goal\Legacy\Core\Logging\LogLevel;
use Goal\Legacy\Core\Logging\LoggerInterface;

final class RecordingLogger implements LoggerInterface
{
    /** @var list<array{level: string, component: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log(LogLevel|string $level, string $component, string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => is_string($level) ? $level : $level->label(),
            'component' => $component,
            'message' => $message,
            'context' => $context,
        ];
    }

    public function debug(string $component, string $message, array $context = []): void { $this->log(LogLevel::Debug, $component, $message, $context); }

    public function info(string $component, string $message, array $context = []): void { $this->log(LogLevel::Info, $component, $message, $context); }

    public function warning(string $component, string $message, array $context = []): void { $this->log(LogLevel::Warning, $component, $message, $context); }

    public function error(string $component, string $message, array $context = []): void { $this->log(LogLevel::Error, $component, $message, $context); }
}
