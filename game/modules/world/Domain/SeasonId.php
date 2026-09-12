<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\World\Domain;

use InvalidArgumentException;

final readonly class SeasonId
{
    public function __construct(private string $value)
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $value) !== 1) {
            throw new InvalidArgumentException('Season IDs must be 1-64 lowercase letters, numbers, dots, hyphens, or underscores.');
        }
    }

    public function value(): string { return $this->value; }

    public function __toString(): string { return $this->value; }
}
