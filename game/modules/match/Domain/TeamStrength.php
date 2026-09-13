<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Domain;

final readonly class TeamStrength
{
    public function __construct(private int $value, private bool $hasRealPlayers = false)
    {
        if ($value < 0 || $value > 100) { throw new \InvalidArgumentException('Team strength must be between 0 and 100.'); }
    }
    public function value(): int { return $this->value; }
    public function hasRealPlayers(): bool { return $this->hasRealPlayers; }
}
