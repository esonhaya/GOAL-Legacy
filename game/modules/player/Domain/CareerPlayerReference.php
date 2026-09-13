<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use Goal\Legacy\Modules\World\Domain\SimulationDate;
final readonly class CareerPlayerReference
{
    public function __construct(
        private CareerId $careerId,
        private PlayerId $playerId,
        private SimulationDate $startDate,
    ) {
    }

    public function careerId(): CareerId { return $this->careerId; }

    public function playerId(): PlayerId { return $this->playerId; }

    public function startDate(): SimulationDate { return $this->startDate; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'career_id' => $this->careerId->value(),
            'player_id' => $this->playerId->value(),
            'start_date' => $this->startDate->toIsoString(),
        ];
    }
}
