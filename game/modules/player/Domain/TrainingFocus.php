<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use ValueError;

enum TrainingFocus: string
{
    case Balanced = 'balanced';
    case Pace = 'pace';
    case Shooting = 'shooting';
    case Passing = 'passing';
    case Dribbling = 'dribbling';
    case Defending = 'defending';
    case Physicality = 'physicality';

    public static function fromInput(string $value): self
    {
        try {
            return self::from(strtolower(trim($value)));
        } catch (ValueError $exception) {
            throw new PlayerException(sprintf('Unsupported training focus "%s".', $value), 0, $exception);
        }
    }
}
