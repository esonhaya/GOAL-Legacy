<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Logging;

interface LoggerInterface
{
    /** @param array<string, mixed> $context */
    public function log(LogLevel|string $level, string $component, string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function debug(string $component, string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function info(string $component, string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function warning(string $component, string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function error(string $component, string $message, array $context = []): void;
}
