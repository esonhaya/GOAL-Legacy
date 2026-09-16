<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use ValueError;

enum CareerPriority: string
{
    case Development = 'development';
    case Recovery = 'recovery';
    case Professional = 'professional';
    case Balanced = 'balanced';
    case Lifestyle = 'lifestyle';

    public static function fromInput(string $value): self
    {
        try {
            return self::from(strtolower(trim($value)));
        } catch (ValueError $exception) {
            throw new PlayerException(sprintf('Unsupported career priority "%s".', $value), 0, $exception);
        }
    }
}
