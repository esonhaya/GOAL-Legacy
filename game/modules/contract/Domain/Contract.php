<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Contract\Domain;

use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use InvalidArgumentException;

final readonly class Contract
{
    public function __construct(
        private ContractId $id,
        private PlayerId $playerId,
        private ClubId $clubId,
        private SimulationDate $startDate,
        private SimulationDate $endDate,
        private int $wage,
        private ContractStatus $status,
    ) {
        if ($this->endDate->isBefore($this->startDate)) {
            throw new InvalidArgumentException('Contract end date cannot precede its start date.');
        }
        if ($this->wage < 0) {
            throw new InvalidArgumentException('Contract wage cannot be negative.');
        }
    }

    public static function forDate(ContractId $id, PlayerId $playerId, ClubId $clubId, SimulationDate $startDate, SimulationDate $endDate, int $wage, SimulationDate $asOfDate): self
    {
        $status = $asOfDate->isBefore($startDate)
            ? ContractStatus::Pending
            : ($asOfDate->isAfter($endDate) ? ContractStatus::Expired : ContractStatus::Active);

        return new self($id, $playerId, $clubId, $startDate, $endDate, $wage, $status);
    }

    public function id(): ContractId { return $this->id; }
    public function playerId(): PlayerId { return $this->playerId; }
    public function clubId(): ClubId { return $this->clubId; }
    public function startDate(): SimulationDate { return $this->startDate; }
    public function endDate(): SimulationDate { return $this->endDate; }
    public function wage(): int { return $this->wage; }
    public function status(): ContractStatus { return $this->status; }

    public function withStatus(ContractStatus $status): self
    {
        return new self($this->id, $this->playerId, $this->clubId, $this->startDate, $this->endDate, $this->wage, $status);
    }

    public function terminate(): self
    {
        if ($this->status === ContractStatus::Expired) {
            throw new ContractException('Expired Contracts cannot be terminated.');
        }
        return $this->withStatus(ContractStatus::Terminated);
    }

    public function isActive(): bool
    {
        return $this->status === ContractStatus::Active;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id->value(),
            'player_id' => $this->playerId->value(),
            'club_id' => $this->clubId->value(),
            'start_date' => $this->startDate->toIsoString(),
            'end_date' => $this->endDate->toIsoString(),
            'wage' => $this->wage,
            'status' => $this->status->value,
        ];
    }
}
