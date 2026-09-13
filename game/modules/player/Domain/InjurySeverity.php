<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

enum InjurySeverity: string
{
    case Minor = 'minor';
    case Moderate = 'moderate';
    case Major = 'major';

    public function durationDays(): int
    {
        return match ($this) {
            self::Minor => 3,
            self::Moderate => 10,
            self::Major => 28,
        };
    }
}
