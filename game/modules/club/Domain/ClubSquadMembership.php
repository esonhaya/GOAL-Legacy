<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Club\Domain;

use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\World\Domain\SeasonId;

final readonly class ClubSquadMembership
{
    public function __construct(
        private ClubId $clubId,
        private PlayerId $playerId,
        private SeasonId $seasonId,
        private SquadRole $role = SquadRole::Prospect,
    ) {
    }

    public function clubId(): ClubId { return $this->clubId; }

    public function playerId(): PlayerId { return $this->playerId; }

    public function seasonId(): SeasonId { return $this->seasonId; }

    public function role(): SquadRole { return $this->role; }

    public function key(): string
    {
        return implode(':', [$this->seasonId->value(), $this->clubId->value(), $this->playerId->value()]);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'club_id' => $this->clubId->value(),
            'player_id' => $this->playerId->value(),
            'season_id' => $this->seasonId->value(),
            'role' => $this->role->value,
        ];
    }
}
