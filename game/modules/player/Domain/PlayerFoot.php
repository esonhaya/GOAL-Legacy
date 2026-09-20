<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use ValueError;

enum PlayerFoot: string
{
    case Left = 'left';
    case Right = 'right';

    public static function fromInput(string $value): self
    {
        try {
            return self::from(strtolower(trim($value)));
        } catch (ValueError $exception) {
            throw new PlayerException(sprintf('Unsupported preferred foot "%s".', $value), 0, $exception);
        }
    }

    public function label(): string
    {
        return $this === self::Left ? 'Left' : 'Right';
    }

    public function opposite(): self
    {
        return $this === self::Left ? self::Right : self::Left;
    }

    /** Stable migration/default assignment; this intentionally has no RNG side effects. */
    public static function fromStableSeed(string $identity, int $seed = 0): self
    {
        $digest = hash('sha256', 'player-foot:v1|' . $identity . '|' . $seed);

        return hexdec(substr($digest, 0, 2)) % 2 === 0 ? self::Right : self::Left;
    }
}
