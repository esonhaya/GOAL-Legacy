<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use ValueError;

enum WeakFootTier: string
{
    case Limited = 'limited';
    case Usable = 'usable';
    case Comfortable = 'comfortable';
    case Strong = 'strong';

    public static function fromInput(string $value): self
    {
        try {
            return self::from(strtolower(trim($value)));
        } catch (ValueError $exception) {
            throw new PlayerException(sprintf('Unsupported weak-foot tier "%s".', $value), 0, $exception);
        }
    }

    public function label(): string
    {
        return match ($this) {
            self::Limited => 'Limited',
            self::Usable => 'Usable',
            self::Comfortable => 'Comfortable',
            self::Strong => 'Strong',
        };
    }

    /** Chance that a meaningful controlled action uses the non-preferred foot. */
    public function actionChance(): float
    {
        return match ($this) {
            self::Limited => 0.10,
            self::Usable => 0.25,
            self::Comfortable => 0.40,
            self::Strong => 0.50,
        };
    }

    /** Stable compact progress bands used by controlled weak-foot training. */
    public function progressFloor(): int
    {
        return match ($this) {
            self::Limited => 0,
            self::Usable => 25,
            self::Comfortable => 50,
            self::Strong => 75,
        };
    }

    public static function fromProgress(int $progress): self
    {
        $value = max(0, min(100, $progress));
        if ($value < 25) { return self::Limited; }
        if ($value < 50) { return self::Usable; }
        if ($value < 75) { return self::Comfortable; }

        return self::Strong;
    }

    /** Stable legacy/newgen default with a modest, non-point-buy distribution. */
    public static function fromStableSeed(string $identity, int $seed, DevelopmentProfile $profile): self
    {
        $digest = hash('sha256', 'player-weak-foot:v1|' . $identity . '|' . $seed . '|' . $profile->value);
        $roll = hexdec(substr($digest, 0, 4)) % 100;

        return match ($profile) {
            DevelopmentProfile::Prodigy => $roll < 12 ? self::Comfortable : ($roll < 72 ? self::Usable : self::Limited),
            DevelopmentProfile::LateBloomer => $roll < 8 ? self::Comfortable : ($roll < 55 ? self::Usable : self::Limited),
            DevelopmentProfile::Regular => $roll < 4 ? self::Comfortable : ($roll < 48 ? self::Usable : self::Limited),
        };
    }
}
