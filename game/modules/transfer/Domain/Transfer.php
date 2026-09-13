<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Transfer\Domain;

use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use InvalidArgumentException;

final readonly class Transfer
{
    public function __construct(private TransferId $id, private PlayerId $playerId, private ClubId $sourceClubId, private ClubId $destinationClubId, private SeasonId $seasonId, private int $fee, private SimulationDate $effectiveDate, private TransferStatus $status = TransferStatus::Agreed, private ?ContractId $sourceContractId = null, private ?ContractId $destinationContractId = null)
    {
        if ($this->sourceClubId->value() === $this->destinationClubId->value()) { throw new InvalidArgumentException('Transfer source and destination Clubs must differ.'); }
        if ($this->fee < 0) { throw new InvalidArgumentException('Transfer fee cannot be negative.'); }
        if ($this->status === TransferStatus::Completed && $this->destinationContractId === null) { throw new InvalidArgumentException('Completed Transfers require a destination Contract.'); }
    }
    public function id(): TransferId { return $this->id; }
    public function playerId(): PlayerId { return $this->playerId; }
    public function sourceClubId(): ClubId { return $this->sourceClubId; }
    public function destinationClubId(): ClubId { return $this->destinationClubId; }
    public function seasonId(): SeasonId { return $this->seasonId; }
    public function fee(): int { return $this->fee; }
    public function effectiveDate(): SimulationDate { return $this->effectiveDate; }
    public function status(): TransferStatus { return $this->status; }
    public function sourceContractId(): ?ContractId { return $this->sourceContractId; }
    public function destinationContractId(): ?ContractId { return $this->destinationContractId; }
    public function complete(ContractId $sourceContractId, ContractId $destinationContractId): self { return new self($this->id, $this->playerId, $this->sourceClubId, $this->destinationClubId, $this->seasonId, $this->fee, $this->effectiveDate, TransferStatus::Completed, $sourceContractId, $destinationContractId); }
    /** @return array<string, mixed> */
    public function toArray(): array { return ['id' => $this->id->value(), 'player_id' => $this->playerId->value(), 'source_club_id' => $this->sourceClubId->value(), 'destination_club_id' => $this->destinationClubId->value(), 'season_id' => $this->seasonId->value(), 'fee' => $this->fee, 'effective_date' => $this->effectiveDate->toIsoString(), 'status' => $this->status->value, 'source_contract_id' => $this->sourceContractId?->value(), 'destination_contract_id' => $this->destinationContractId?->value()]; }
}
