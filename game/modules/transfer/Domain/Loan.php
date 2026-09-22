<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Transfer\Domain;

use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

/** One active temporary registration; the parent Contract remains separate. */
final readonly class Loan
{
    public function __construct(
        private string $id,
        private PlayerId $playerId,
        private ClubId $parentClubId,
        private ClubId $loanClubId,
        private SeasonId $seasonId,
        private SimulationDate $startDate,
        private SimulationDate $scheduledEndDate,
        private SquadRole $parentRole,
        private SquadRole $loanRole,
        private LoanStatus $status = LoanStatus::Active,
    ) {
    }

    public function id(): string { return $this->id; }
    public function playerId(): PlayerId { return $this->playerId; }
    public function parentClubId(): ClubId { return $this->parentClubId; }
    public function loanClubId(): ClubId { return $this->loanClubId; }
    public function seasonId(): SeasonId { return $this->seasonId; }
    public function startDate(): SimulationDate { return $this->startDate; }
    public function scheduledEndDate(): SimulationDate { return $this->scheduledEndDate; }
    public function parentRole(): SquadRole { return $this->parentRole; }
    public function loanRole(): SquadRole { return $this->loanRole; }
    public function status(): LoanStatus { return $this->status; }

    public function complete(): self
    {
        return new self($this->id, $this->playerId, $this->parentClubId, $this->loanClubId, $this->seasonId, $this->startDate, $this->scheduledEndDate, $this->parentRole, $this->loanRole, LoanStatus::Completed);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'player_id' => $this->playerId->value(),
            'parent_club_id' => $this->parentClubId->value(),
            'loan_club_id' => $this->loanClubId->value(),
            'season_id' => $this->seasonId->value(),
            'start_date' => $this->startDate->toIsoString(),
            'scheduled_end_date' => $this->scheduledEndDate->toIsoString(),
            'parent_role' => $this->parentRole->value,
            'loan_role' => $this->loanRole->value,
            'status' => $this->status->value,
        ];
    }
}
