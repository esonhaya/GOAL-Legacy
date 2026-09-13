<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Domain;

use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use InvalidArgumentException;

final readonly class GameMatch
{
    public function __construct(
        private MatchId $id,
        private CompetitionId $competitionId,
        private SeasonId $seasonId,
        private int $round,
        private SimulationDate $scheduledDate,
        private ClubId $homeClubId,
        private ClubId $awayClubId,
        private MatchStatus $status = MatchStatus::Scheduled,
        private ?MatchResult $result = null,
    ) {
        if ($round < 1) { throw new InvalidArgumentException('Match rounds must be positive.'); }
        if ($this->homeClubId->value() === $this->awayClubId->value()) { throw new InvalidArgumentException('A Match cannot have the same home and away Club.'); }
        if ($this->status === MatchStatus::Completed && $this->result === null) { throw new InvalidArgumentException('Completed Matches require a result.'); }
        if ($this->status === MatchStatus::Scheduled && $this->result !== null) { throw new InvalidArgumentException('Scheduled Matches cannot have a result.'); }
    }
    public function id(): MatchId { return $this->id; }
    public function competitionId(): CompetitionId { return $this->competitionId; }
    public function seasonId(): SeasonId { return $this->seasonId; }
    public function round(): int { return $this->round; }
    public function scheduledDate(): SimulationDate { return $this->scheduledDate; }
    public function homeClubId(): ClubId { return $this->homeClubId; }
    public function awayClubId(): ClubId { return $this->awayClubId; }
    public function status(): MatchStatus { return $this->status; }
    public function result(): ?MatchResult { return $this->result; }
    public function complete(MatchResult $result): self { if ($this->status === MatchStatus::Completed) { throw new MatchException('Completed Matches are immutable.'); } return new self($this->id, $this->competitionId, $this->seasonId, $this->round, $this->scheduledDate, $this->homeClubId, $this->awayClubId, MatchStatus::Completed, $result); }
    /** @return array<string, mixed> */
    public function toArray(): array { return ['id' => $this->id->value(), 'competition_id' => $this->competitionId->value(), 'season_id' => $this->seasonId->value(), 'round_number' => $this->round, 'scheduled_date' => $this->scheduledDate->toIsoString(), 'home_club_id' => $this->homeClubId->value(), 'away_club_id' => $this->awayClubId->value(), 'status' => $this->status->value, 'home_goals' => $this->result?->homeGoals(), 'away_goals' => $this->result?->awayGoals()]; }
}
