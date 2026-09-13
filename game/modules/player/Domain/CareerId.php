<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use InvalidArgumentException;

final readonly class CareerId
{
    public function __construct(private string $value)
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $value) !== 1) {
            throw new InvalidArgumentException('Career IDs must be 1-64 lowercase letters, numbers, dots, hyphens, or underscores.');
        }
    }

    public function value(): string { return $this->value; }
}
