<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use Goal\Legacy\Modules\World\Domain\SimulationDate;

final readonly class PositionDevelopmentState
{
    /** @param list<PlayerPosition> $secondaryPositions @param array<string,int> $progress */
    public function __construct(
        private PlayerId $playerId,
        private array $secondaryPositions,
        private ?PlayerPosition $developingPosition,
        private array $progress,
        private ?SimulationDate $updatedDate,
        private int $revision,
    ) {
    }

    public static function empty(PlayerId $playerId): self
    {
        return new self($playerId, [], null, [], null, 0);
    }

    public function playerId(): PlayerId { return $this->playerId; }

    /** @return list<PlayerPosition> */
    public function secondaryPositions(): array { return $this->secondaryPositions; }

    public function developingPosition(): ?PlayerPosition { return $this->developingPosition; }

    /** @return array<string,int> */
    public function progress(): array { return $this->progress; }

    public function progressFor(PlayerPosition $position): int { return max(0, min(100, (int) ($this->progress[$position->value] ?? 0))); }

    public function updatedDate(): ?SimulationDate { return $this->updatedDate; }

    public function revision(): int { return $this->revision; }

    public function withFocus(?PlayerPosition $position, ?SimulationDate $date): self
    {
        return new self($this->playerId, $this->secondaryPositions, $position, $this->progress, $date, $this->revision + 1);
    }

    public function withProgress(PlayerPosition $position, int $value, ?SimulationDate $date): self
    {
        $progress = $this->progress;
        $progress[$position->value] = max(0, min(100, $value));
        $secondary = $this->secondaryPositions;
        $developing = $this->developingPosition;
        if ($progress[$position->value] >= 100) {
            if (!in_array($position, $secondary, true)) {
                $secondary[] = $position;
                usort($secondary, static fn (PlayerPosition $left, PlayerPosition $right): int => strcmp($left->value, $right->value));
            }
            $developing = $developing === $position ? null : $developing;
        }

        return new self($this->playerId, $secondary, $developing, $progress, $date, $this->revision + 1);
    }

    public function afterPrimaryChange(PlayerPosition $old, PlayerPosition $new, ?SimulationDate $date): self
    {
        $secondary = array_values(array_filter($this->secondaryPositions, static fn (PlayerPosition $position): bool => $position !== $new));
        if (!in_array($old, $secondary, true)) {
            $secondary[] = $old;
        }
        usort($secondary, static fn (PlayerPosition $left, PlayerPosition $right): int => strcmp($left->value, $right->value));
        $progress = $this->progress;
        unset($progress[$new->value]);

        return new self($this->playerId, $secondary, null, $progress, $date, $this->revision + 1);
    }
}
