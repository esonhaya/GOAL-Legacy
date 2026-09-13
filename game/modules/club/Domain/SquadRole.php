<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Club\Domain;

use ValueError;

enum SquadRole: string
{
    case Prospect = 'prospect';
    case Rotation = 'rotation';
    case Regular = 'regular';
    case KeyPlayer = 'key_player';

    public function weight(): int
    {
        return match ($this) {
            self::Prospect => 80,
            self::Rotation => 180,
            self::Regular => 300,
            self::KeyPlayer => 400,
        };
    }

    public function expectationScore(): int
    {
        return match ($this) {
            self::Prospect => 55,
            self::Rotation => 60,
            self::Regular => 68,
            self::KeyPlayer => 75,
        };
    }

    public function promoted(): ?self
    {
        return match ($this) {
            self::Prospect => self::Rotation,
            self::Rotation => self::Regular,
            self::Regular => self::KeyPlayer,
            self::KeyPlayer => null,
        };
    }

    public function demoted(): ?self
    {
        return match ($this) {
            self::Prospect => null,
            self::Rotation => self::Prospect,
            self::Regular => self::Rotation,
            self::KeyPlayer => self::Regular,
        };
    }

    public static function fromInput(string $value): self
    {
        try {
            return self::from(strtolower(trim($value)));
        } catch (ValueError $exception) {
            throw new ClubException(sprintf('Unsupported squad role "%s".', $value), 0, $exception);
        }
    }
}
