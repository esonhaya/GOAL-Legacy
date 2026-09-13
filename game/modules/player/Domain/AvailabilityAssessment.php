<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use Goal\Legacy\Modules\World\Domain\SimulationDate;

final readonly class AvailabilityAssessment
{
    public function __construct(
        private PlayerId $playerId,
        private AvailabilityStatus $status,
        private int $fatigue,
        private SimulationDate $date,
        private ?Injury $injury = null,
    ) {
    }

    public function playerId(): PlayerId { return $this->playerId; }
    public function status(): AvailabilityStatus { return $this->status; }
    public function fatigue(): int { return $this->fatigue; }
    public function date(): SimulationDate { return $this->date; }
    public function injury(): ?Injury { return $this->injury; }
    public function isAvailable(): bool { return $this->status === AvailabilityStatus::Available; }
    public function isLimited(): bool { return $this->status === AvailabilityStatus::Limited; }
    public function isUnavailable(): bool { return $this->status === AvailabilityStatus::Unavailable; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'active_injury' => $this->injury?->toArray(),
            'date' => $this->date->toIsoString(),
            'fatigue' => $this->fatigue,
            'player_id' => $this->playerId->value(),
            'status' => $this->status->value,
        ];
    }
}
