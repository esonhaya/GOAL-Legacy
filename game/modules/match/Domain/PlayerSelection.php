<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Domain;

use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Player\Domain\PlayerId;

final readonly class PlayerSelection
{
    public function __construct(private MatchId $matchId, private PlayerId $playerId, private ClubId $clubId, private SelectionStatus $status)
    {
    }

    public function matchId(): MatchId { return $this->matchId; }
    public function playerId(): PlayerId { return $this->playerId; }
    public function clubId(): ClubId { return $this->clubId; }
    public function status(): SelectionStatus { return $this->status; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['match_id' => $this->matchId->value(), 'player_id' => $this->playerId->value(), 'club_id' => $this->clubId->value(), 'status' => $this->status->value];
    }
}
