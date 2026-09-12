<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Logging;

use InvalidArgumentException;

enum LogLevel: int
{
    case Debug = 100;
    case Info = 200;
    case Warning = 300;
    case Error = 400;
    case Critical = 500;

    public static function fromName(string $name): self
    {
        return match (strtolower($name)) {
            'debug' => self::Debug,
            'info' => self::Info,
            'warning', 'warn' => self::Warning,
            'error' => self::Error,
            'critical', 'fatal' => self::Critical,
            default => throw new InvalidArgumentException(sprintf('Unknown log level "%s".', $name)),
        };
    }

    public function label(): string
    {
        return strtolower($this->name);
    }
}
