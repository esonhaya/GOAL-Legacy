<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use Goal\Legacy\Modules\World\Domain\SimulationDate;

final readonly class DevelopmentState
{
    /** @param array<string, int> $progress */
    public function __construct(
        private PlayerId $playerId,
        private array $progress,
        private ?SimulationDate $lastProcessedDate,
        private ?TrainingFocus $currentFocus,
        private int $revision,
    ) {
    }

    public static function empty(PlayerId $playerId): self
    {
        return new self($playerId, array_fill_keys(array_keys((new PlayerAttributeSet(0, 0, 0, 0, 0, 0))->toArray()), 0), null, null, 0);
    }

    public function playerId(): PlayerId { return $this->playerId; }
    /** @return array<string, int> */
    public function progress(): array { return $this->progress; }
    public function lastProcessedDate(): ?SimulationDate { return $this->lastProcessedDate; }
    public function currentFocus(): ?TrainingFocus { return $this->currentFocus; }
    public function revision(): int { return $this->revision; }

    /** @param array<string, int> $progress */
    public function withProgress(array $progress, ?SimulationDate $date, ?TrainingFocus $focus): self
    {
        return new self($this->playerId, $progress, $date, $focus ?? $this->currentFocus, $this->revision + 1);
    }
}
