<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

final readonly class CareerEvent
{
    /** @param list<array<string, mixed>> $choices @param array<string, mixed> $context @param array<string, mixed>|null $consequence */
    public function __construct(
        private string $id,
        private PlayerId $playerId,
        private SeasonId $seasonId,
        private SimulationDate $date,
        private string $sourceKey,
        private string $category,
        private string $definition,
        private string $title,
        private string $description,
        private array $choices,
        private CareerEventStatus $status,
        private ?string $selectedChoice,
        private array $context,
        private ?array $consequence,
    ) {
    }

    /** @param list<array<string, mixed>> $choices @param array<string, mixed> $context */
    public static function pending(string $id, PlayerId $playerId, SeasonId $seasonId, SimulationDate $date, string $sourceKey, string $category, string $definition, string $title, string $description, array $choices, array $context = []): self
    {
        return new self($id, $playerId, $seasonId, $date, $sourceKey, $category, $definition, $title, $description, $choices, CareerEventStatus::Pending, null, $context, null);
    }

    public function id(): string { return $this->id; }
    public function playerId(): PlayerId { return $this->playerId; }
    public function seasonId(): SeasonId { return $this->seasonId; }
    public function date(): SimulationDate { return $this->date; }
    public function sourceKey(): string { return $this->sourceKey; }
    public function category(): string { return $this->category; }
    public function definition(): string { return $this->definition; }
    public function title(): string { return $this->title; }
    public function description(): string { return $this->description; }
    /** @return list<array<string, mixed>> */
    public function choices(): array { return $this->choices; }
    public function status(): CareerEventStatus { return $this->status; }
    public function selectedChoice(): ?string { return $this->selectedChoice; }
    /** @return array<string, mixed> */
    public function context(): array { return $this->context; }
    /** @return array<string, mixed>|null */
    public function consequence(): ?array { return $this->consequence; }

    /** @param array<string, mixed> $consequence */
    public function resolved(string $choice, array $consequence): self
    {
        return new self($this->id, $this->playerId, $this->seasonId, $this->date, $this->sourceKey, $this->category, $this->definition, $this->title, $this->description, $this->choices, CareerEventStatus::Resolved, $choice, $this->context, $consequence);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'player_id' => $this->playerId->value(),
            'season_id' => $this->seasonId->value(),
            'date' => $this->date->toIsoString(),
            'source_key' => $this->sourceKey,
            'category' => $this->category,
            'definition' => $this->definition,
            'title' => $this->title,
            'description' => $this->description,
            'choices' => $this->choices,
            'status' => $this->status->value,
            'selected_choice' => $this->selectedChoice,
            'context' => $this->context,
            'consequence' => $this->consequence,
        ];
    }
}
