<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use ValueError;

enum DevelopmentProfile: string
{
    case LateBloomer = 'late_bloomer';
    case Regular = 'regular';
    case Prodigy = 'prodigy';

    public static function fromInput(string $value): self
    {
        try {
            return self::from(strtolower(trim($value)));
        } catch (ValueError $exception) {
            throw new PlayerException(sprintf('Unsupported development profile "%s".', $value), 0, $exception);
        }
    }
}
