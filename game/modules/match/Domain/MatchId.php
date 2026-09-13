<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Domain;

use InvalidArgumentException;

final readonly class MatchId
{
    public function __construct(private string $value)
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/', $value) !== 1) { throw new InvalidArgumentException('Match IDs must be 1-128 lowercase letters, numbers, dots, hyphens, or underscores.'); }
    }
    public function value(): string { return $this->value; }
    public function __toString(): string { return $this->value; }
}
