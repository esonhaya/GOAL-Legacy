<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\SeasonId;
final readonly class CareerPlayerReference
{
    public function __construct(
        private CareerId $careerId,
        private PlayerId $playerId,
        private SimulationDate $startDate,
        private CareerTransferRequestStatus $transferRequestStatus = CareerTransferRequestStatus::None,
        private ?SeasonId $transferRequestSeasonId = null,
        private ?OnPitchRole $preferredOnPitchRole = null,
    ) {
    }

    public function careerId(): CareerId { return $this->careerId; }

    public function playerId(): PlayerId { return $this->playerId; }

    public function startDate(): SimulationDate { return $this->startDate; }
    public function transferRequestStatus(): CareerTransferRequestStatus { return $this->transferRequestStatus; }
    public function transferRequestSeasonId(): ?SeasonId { return $this->transferRequestSeasonId; }
    public function preferredOnPitchRole(): ?OnPitchRole { return $this->preferredOnPitchRole; }
    public function hasActiveTransferRequest(SeasonId $seasonId): bool
    {
        return $this->transferRequestStatus === CareerTransferRequestStatus::Requested
            && $this->transferRequestSeasonId?->value() === $seasonId->value();
    }

    public function withTransferRequest(SeasonId $seasonId): self
    {
        return new self($this->careerId, $this->playerId, $this->startDate, CareerTransferRequestStatus::Requested, $seasonId, $this->preferredOnPitchRole);
    }

    public function withoutTransferRequest(): self
    {
        return new self($this->careerId, $this->playerId, $this->startDate, CareerTransferRequestStatus::None, null, $this->preferredOnPitchRole);
    }

    public function withPreferredOnPitchRole(OnPitchRole $role): self
    {
        return new self($this->careerId, $this->playerId, $this->startDate, $this->transferRequestStatus, $this->transferRequestSeasonId, $role);
    }

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
