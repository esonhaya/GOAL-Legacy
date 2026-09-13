<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Competition\Domain;

use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\World\Domain\SeasonId;

final readonly class PlayerRegistration
{
    public function __construct(private SeasonId $seasonId, private CompetitionId $competitionId, private ClubId $clubId, private PlayerId $playerId) {}
    public function seasonId(): SeasonId { return $this->seasonId; }
    public function competitionId(): CompetitionId { return $this->competitionId; }
    public function clubId(): ClubId { return $this->clubId; }
    public function playerId(): PlayerId { return $this->playerId; }
    public function key(): string { return implode(':', [$this->seasonId->value(), $this->competitionId->value(), $this->clubId->value(), $this->playerId->value()]); }
    /** @return array<string, string> */
    public function toArray(): array { return ['season_id' => $this->seasonId->value(), 'competition_id' => $this->competitionId->value(), 'club_id' => $this->clubId->value(), 'player_id' => $this->playerId->value()]; }
}
