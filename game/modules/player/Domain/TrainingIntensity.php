<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use ValueError;

/**
 * The small workload policy used by the existing between-Match training
 * block.  It is derived from Career priority; it is not a second progression
 * system or a daily training schedule.
 */
enum TrainingIntensity: string
{
    case Light = 'light';
    case Normal = 'normal';
    case Intense = 'intense';

    public static function fromInput(string $value): self
    {
        try {
            return self::from(strtolower(trim($value)));
        } catch (ValueError $exception) {
            throw new PlayerException(sprintf('Unsupported training intensity "%s".', $value), 0, $exception);
        }
    }

    public static function forPriority(CareerPriority $priority): self
    {
        return match ($priority) {
            CareerPriority::Recovery, CareerPriority::Lifestyle => self::Light,
            CareerPriority::Development => self::Intense,
            CareerPriority::Professional, CareerPriority::Balanced => self::Normal,
        };
    }

    public function loadPerWeek(): int
    {
        return match ($this) {
            self::Light => 3,
            self::Normal => 8,
            self::Intense => 14,
        };
    }

    public function developmentPercent(): int
    {
        return match ($this) {
            self::Light => 60,
            self::Normal => 100,
            self::Intense => 115,
        };
    }
}
