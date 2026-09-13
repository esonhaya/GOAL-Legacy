<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use Goal\Legacy\Modules\World\Domain\SimulationDate;
use InvalidArgumentException;

final readonly class Injury
{
    public function __construct(
        private string $id,
        private PlayerId $playerId,
        private string $sourceType,
        private string $sourceId,
        private InjuryCategory $category,
        private InjurySeverity $severity,
        private SimulationDate $startDate,
        private SimulationDate $recoveryDate,
        private InjuryStatus $status = InjuryStatus::Active,
        private ?SimulationDate $actualRecoveryDate = null,
    ) {
        if (trim($this->id) === '' || trim($this->sourceType) === '' || trim($this->sourceId) === '') {
            throw new InvalidArgumentException('Injury identity and source references cannot be empty.');
        }
        if (!$this->recoveryDate->isAfter($this->startDate)) {
            throw new InvalidArgumentException('Injury recovery must be after its start date.');
        }
        if ($this->status === InjuryStatus::Recovered && $this->actualRecoveryDate === null) {
            throw new InvalidArgumentException('Recovered Injuries require an actual recovery date.');
        }
    }

    public function id(): string { return $this->id; }
    public function playerId(): PlayerId { return $this->playerId; }
    public function sourceType(): string { return $this->sourceType; }
    public function sourceId(): string { return $this->sourceId; }
    public function category(): InjuryCategory { return $this->category; }
    public function severity(): InjurySeverity { return $this->severity; }
    public function startDate(): SimulationDate { return $this->startDate; }
    public function recoveryDate(): SimulationDate { return $this->recoveryDate; }
    public function status(): InjuryStatus { return $this->status; }
    public function actualRecoveryDate(): ?SimulationDate { return $this->actualRecoveryDate; }

    public function isActiveAt(SimulationDate $date): bool
    {
        return !$date->isBefore($this->startDate) && $date->isBefore($this->recoveryDate);
    }

    public function recovered(SimulationDate $date): self
    {
        return new self($this->id, $this->playerId, $this->sourceType, $this->sourceId, $this->category, $this->severity, $this->startDate, $this->recoveryDate, InjuryStatus::Recovered, $date);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'actual_recovery_date' => $this->actualRecoveryDate?->toIsoString(),
            'category' => $this->category->value,
            'id' => $this->id,
            'player_id' => $this->playerId->value(),
            'recovery_date' => $this->recoveryDate->toIsoString(),
            'severity' => $this->severity->value,
            'source_id' => $this->sourceId,
            'source_type' => $this->sourceType,
            'start_date' => $this->startDate->toIsoString(),
            'status' => $this->status->value,
        ];
    }
}
