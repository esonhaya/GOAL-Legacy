<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use InvalidArgumentException;

final readonly class PlayerAttributeSet
{
    public function __construct(
        private int $pace,
        private int $shooting,
        private int $passing,
        private int $dribbling,
        private int $defending,
        private int $physicality,
    ) {
        foreach ($this->toArray() as $name => $value) {
            if ($value < 0 || $value > 99) {
                throw new InvalidArgumentException(sprintf('Player attribute %s must be between 0 and 99.', $name));
            }
        }
    }

    public function pace(): int { return $this->pace; }

    public function shooting(): int { return $this->shooting; }

    public function passing(): int { return $this->passing; }

    public function dribbling(): int { return $this->dribbling; }

    public function defending(): int { return $this->defending; }

    public function physicality(): int { return $this->physicality; }

    public function overallRating(): int
    {
        return intdiv(array_sum($this->toArray()), 6);
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'pace' => $this->pace,
            'shooting' => $this->shooting,
            'passing' => $this->passing,
            'dribbling' => $this->dribbling,
            'defending' => $this->defending,
            'physicality' => $this->physicality,
        ];
    }
}
