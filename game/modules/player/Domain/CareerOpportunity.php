<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

final readonly class CareerOpportunity
{
    /** @param array<string, mixed> $context */
    public function __construct(private string $id, private PlayerId $playerId, private CareerOpportunityType $type, private ClubId $sourceClubId, private ?ClubId $targetClubId, private SimulationDate $createdDate, private ?SimulationDate $expiryDate, private CareerOpportunityStatus $status, private array $context, private string $sourceKey)
    {
    }

    public function id(): string { return $this->id; }
    public function playerId(): PlayerId { return $this->playerId; }
    public function type(): CareerOpportunityType { return $this->type; }
    public function sourceClubId(): ClubId { return $this->sourceClubId; }
    public function targetClubId(): ?ClubId { return $this->targetClubId; }
    public function createdDate(): SimulationDate { return $this->createdDate; }
    public function expiryDate(): ?SimulationDate { return $this->expiryDate; }
    public function status(): CareerOpportunityStatus { return $this->status; }
    /** @return array<string, mixed> */
    public function context(): array { return $this->context; }
    public function sourceKey(): string { return $this->sourceKey; }

    public function withStatus(CareerOpportunityStatus $status): self
    {
        return new self($this->id, $this->playerId, $this->type, $this->sourceClubId, $this->targetClubId, $this->createdDate, $this->expiryDate, $status, $this->context, $this->sourceKey);
    }

    /** @param array<string, mixed> $context */
    public function withStatusAndContext(CareerOpportunityStatus $status, array $context): self
    {
        return new self($this->id, $this->playerId, $this->type, $this->sourceClubId, $this->targetClubId, $this->createdDate, $this->expiryDate, $status, $context, $this->sourceKey);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'player_id' => $this->playerId->value(), 'type' => $this->type->value, 'source_club_id' => $this->sourceClubId->value(), 'target_club_id' => $this->targetClubId?->value(), 'created_date' => $this->createdDate->toIsoString(), 'expiry_date' => $this->expiryDate?->toIsoString(), 'status' => $this->status->value, 'context' => $this->context, 'source_key' => $this->sourceKey];
    }
}
