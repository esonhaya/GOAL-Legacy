<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Logging;

use Throwable;

final class FileLogger implements LoggerInterface
{
    private bool $failureReported = false;

    public function __construct(
        private readonly string $path,
        private readonly LogLevel $minimumLevel = LogLevel::Debug,
    ) {
    }

    public function log(LogLevel|string $level, string $component, string $message, array $context = []): void
    {
        $level = is_string($level) ? LogLevel::fromName($level) : $level;
        if ($level->value < $this->minimumLevel->value) {
            return;
        }

        try {
            $contextText = $context === [] ? '' : ' ' . json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $line = sprintf(
                "[%s] %-8s [%s] %s%s\n",
                (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
                strtoupper($level->label()),
                $component,
                $message,
                $contextText,
            );
            $directory = dirname($this->path);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Unable to create log directory "%s".', $directory));
            }
            if (file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
                throw new \RuntimeException(sprintf('Unable to write log file "%s".', $this->path));
            }
        } catch (Throwable $exception) {
            $this->reportFailure($exception);
        }
    }

    public function debug(string $component, string $message, array $context = []): void
    {
        $this->log(LogLevel::Debug, $component, $message, $context);
    }

    public function info(string $component, string $message, array $context = []): void
    {
        $this->log(LogLevel::Info, $component, $message, $context);
    }

    public function warning(string $component, string $message, array $context = []): void
    {
        $this->log(LogLevel::Warning, $component, $message, $context);
    }

    public function error(string $component, string $message, array $context = []): void
    {
        $this->log(LogLevel::Error, $component, $message, $context);
    }

    private function reportFailure(Throwable $exception): void
    {
        if ($this->failureReported) {
            return;
        }
        $this->failureReported = true;
        error_log(sprintf('[GOAL: Legacy] Logging failure: %s', $exception->getMessage()));
    }
}
