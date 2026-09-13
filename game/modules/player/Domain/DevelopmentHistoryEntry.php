<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use Goal\Legacy\Modules\World\Domain\SimulationDate;

final readonly class DevelopmentHistoryEntry
{
    /** @param array<string, int> $attributeDeltas */
    public function __construct(
        private string $id,
        private PlayerId $playerId,
        private SimulationDate $date,
        private string $source,
        private string $sourceId,
        private array $attributeDeltas,
        private int $beforeOverall,
        private int $afterOverall,
    ) {
    }

    public function id(): string { return $this->id; }
    public function playerId(): PlayerId { return $this->playerId; }
    public function date(): SimulationDate { return $this->date; }
    public function source(): string { return $this->source; }
    public function sourceId(): string { return $this->sourceId; }
    /** @return array<string, int> */
    public function attributeDeltas(): array { return $this->attributeDeltas; }
    public function beforeOverall(): int { return $this->beforeOverall; }
    public function afterOverall(): int { return $this->afterOverall; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'after_ovr' => $this->afterOverall,
            'attribute_deltas' => $this->attributeDeltas,
            'before_ovr' => $this->beforeOverall,
            'date' => $this->date->toIsoString(),
            'id' => $this->id,
            'player_id' => $this->playerId->value(),
            'source' => $this->source,
            'source_id' => $this->sourceId,
        ];
    }
}
