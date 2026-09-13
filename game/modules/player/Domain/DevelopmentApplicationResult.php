<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

final readonly class DevelopmentApplicationResult
{
    /** @param array<string, int> $attributeDeltas */
    public function __construct(
        private PlayerId $playerId,
        private string $source,
        private string $sourceId,
        private array $attributeDeltas,
        private int $beforeOverall,
        private int $afterOverall,
        private bool $applied,
    ) {
    }

    public function playerId(): PlayerId { return $this->playerId; }
    public function source(): string { return $this->source; }
    public function sourceId(): string { return $this->sourceId; }
    /** @return array<string, int> */
    public function attributeDeltas(): array { return $this->attributeDeltas; }
    public function beforeOverall(): int { return $this->beforeOverall; }
    public function afterOverall(): int { return $this->afterOverall; }
    public function applied(): bool { return $this->applied; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['after_ovr' => $this->afterOverall, 'applied' => $this->applied, 'attribute_deltas' => $this->attributeDeltas, 'before_ovr' => $this->beforeOverall, 'player_id' => $this->playerId->value(), 'source' => $this->source, 'source_id' => $this->sourceId];
    }
}
