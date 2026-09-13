<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

final class PlayerCareerProgressionQuery
{
    public function __construct(private readonly ClubService $clubService)
    {
    }

    /** @return array<string, mixed> */
    public function summary(DatabaseInterface $database, PlayerId|string $playerId, SimulationDate $date, ?SeasonId $seasonId = null): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $player = (new PlayerServiceProxy($database))->get($id);
        $development = new PlayerDevelopmentService();
        $statistics = new PlayerCareerStatisticsService();
        $summary = [
            'player' => $player->toArray(),
            'age' => $player->ageAt($date),
            'current_ovr' => $player->overallRating(),
            'potential' => $player->potential(),
            'development_profile' => $player->developmentProfile()->value,
            'training_focus' => $development->state($database, $id)->currentFocus()?->value,
            'career_stats' => $statistics->career($database, $id),
            'recent_development' => array_map(static fn ($entry): array => $entry->toArray(), array_slice(array_reverse($development->history($database, $id)), 0, 5)),
        ];
        if ($seasonId !== null) {
            $summary['season_stats'] = $statistics->season($database, $id, $seasonId);
        }
        $memberships = $this->clubService->squadRepository($database)->byPlayer($id, $seasonId);
        $summary['club_ids'] = array_map(static fn ($membership): string => $membership->clubId()->value(), $memberships);

        return $summary;
    }
}

/** Small local adapter keeps the read model independent of PlayerService's Nation/Club construction dependencies. */
final class PlayerServiceProxy
{
    public function __construct(private readonly DatabaseInterface $database) {}
    public function get(PlayerId $id): \Goal\Legacy\Modules\Player\Domain\Player { return (new \Goal\Legacy\Modules\Player\Persistence\PlayerRepository($this->database))->get($id); }
}
