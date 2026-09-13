<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Club\Domain;

use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\World\Domain\SeasonId;

final readonly class ClubCompetitionMembership
{
    public function __construct(
        private ClubId $clubId,
        private CompetitionId $competitionId,
        private SeasonId $seasonId,
    ) {
    }

    public function clubId(): ClubId { return $this->clubId; }

    public function competitionId(): CompetitionId { return $this->competitionId; }

    public function seasonId(): SeasonId { return $this->seasonId; }

    public function key(): string
    {
        return $this->seasonId->value() . ':' . $this->competitionId->value() . ':' . $this->clubId->value();
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'club_id' => $this->clubId->value(),
            'competition_id' => $this->competitionId->value(),
            'season_id' => $this->seasonId->value(),
        ];
    }
}
