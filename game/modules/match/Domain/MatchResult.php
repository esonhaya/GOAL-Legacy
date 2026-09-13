<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Domain;

use InvalidArgumentException;

final readonly class MatchResult
{
    public function __construct(private int $homeGoals, private int $awayGoals)
    {
        if ($homeGoals < 0 || $awayGoals < 0) { throw new InvalidArgumentException('Match scores cannot be negative.'); }
        if ($homeGoals > 12 || $awayGoals > 12) { throw new InvalidArgumentException('Match scores exceed the Phase-1 simulation bound.'); }
    }
    public function homeGoals(): int { return $this->homeGoals; }
    public function awayGoals(): int { return $this->awayGoals; }
    public function isDraw(): bool { return $this->homeGoals === $this->awayGoals; }
    public function winnerClubSide(): ?string { return $this->isDraw() ? null : ($this->homeGoals > $this->awayGoals ? 'home' : 'away'); }
    /** @return array{home_goals: int, away_goals: int} */
    public function toArray(): array { return ['home_goals' => $this->homeGoals, 'away_goals' => $this->awayGoals]; }
}
