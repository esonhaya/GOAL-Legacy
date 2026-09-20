<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use Goal\Legacy\Modules\World\Domain\SimulationDate;

final readonly class WeakFootDevelopmentState
{
    public function __construct(
        private PlayerId $playerId,
        private int $progress,
        private ?SimulationDate $updatedDate,
        private int $revision,
    ) {
    }

    public static function empty(PlayerId $playerId, WeakFootTier $tier = WeakFootTier::Limited): self
    {
        return new self($playerId, $tier->progressFloor(), null, 0);
    }

    public function playerId(): PlayerId { return $this->playerId; }
    public function progress(): int { return max(0, min(100, $this->progress)); }
    public function updatedDate(): ?SimulationDate { return $this->updatedDate; }
    public function revision(): int { return $this->revision; }

    public function withProgress(int $progress, ?SimulationDate $date): self
    {
        return new self($this->playerId, max(0, min(100, $progress)), $date, $this->revision + 1);
    }
}
