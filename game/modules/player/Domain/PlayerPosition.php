<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use ValueError;

enum PlayerPosition: string
{
    case Goalkeeper = 'GK';
    case CentreBack = 'CB';
    case LeftBack = 'LB';
    case RightBack = 'RB';
    case DefensiveMidfielder = 'DM';
    case CentralMidfielder = 'CM';
    case AttackingMidfielder = 'AM';
    case LeftWinger = 'LW';
    case RightWinger = 'RW';
    case Striker = 'ST';

    public static function fromInput(string $value): self
    {
        try {
            return self::from(strtoupper(trim($value)));
        } catch (ValueError $exception) {
            throw new PlayerException(sprintf('Unsupported Player position "%s".', $value), 0, $exception);
        }
    }
}
