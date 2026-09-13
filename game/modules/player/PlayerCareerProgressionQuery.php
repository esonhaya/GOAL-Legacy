<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Transfer\Persistence\TransferRepository;
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
        $availability = (new PlayerAvailabilityService())->assess($database, $id, $date);
        $summary = [
            'player' => $player->toArray(),
            'age' => $player->ageAt($date),
            'current_ovr' => $player->overallRating(),
            'potential' => $player->potential(),
            'development_profile' => $player->developmentProfile()->value,
            'training_focus' => $development->state($database, $id)->currentFocus()?->value,
            'career_stats' => $statistics->career($database, $id),
            'transfer_history' => array_map(static fn ($transfer): array => $transfer->toArray(), (new TransferRepository($database))->byPlayer($id)),
            'recent_development' => array_map(static fn ($entry): array => $entry->toArray(), array_slice(array_reverse($development->history($database, $id)), 0, 5)),
            'availability' => $availability->status()->value,
            'fatigue' => $availability->fatigue(),
            'active_injury' => $availability->injury()?->toArray(),
        ];
        if ($seasonId !== null) {
            $summary['season_stats'] = $statistics->season($database, $id, $seasonId);
        }
        $memberships = $this->clubService->squadRepository($database)->byPlayer($id, $seasonId);
        $summary['club_ids'] = array_map(static fn ($membership): string => $membership->clubId()->value(), $memberships);
        $membership = $memberships[0] ?? null;
        $summary['squad_role'] = $membership?->role()->value;
        $summary['recent_form'] = (new PlayerFormService())->recent($database, $id);
        $selectionRepository = new MatchSelectionRepository($database);
        $matchRepository = new MatchRepository($database);
        $selectionHistory = [];
        foreach ($selectionRepository->byPlayer($id) as $selection) {
            $match = $matchRepository->get($selection->matchId());
            $selectionHistory[] = ['date' => $match->scheduledDate()->toIsoString(), 'match_id' => $selection->matchId()->value(), 'club_id' => $selection->clubId()->value(), 'status' => $selection->status()->value];
        }
        usort($selectionHistory, static fn (array $a, array $b): int => strcmp($b['date'] . $b['match_id'], $a['date'] . $a['match_id']));
        $summary['recent_selection'] = array_slice($selectionHistory, 0, 5);
        $summary['expectation'] = $membership === null ? null : (new ClubExpectationService($this->clubService))->latest($database, $membership);
        $summary['open_opportunities'] = array_map(static fn ($opportunity): array => $opportunity->toArray(), (new CareerOpportunityService())->openForPlayer($database, $id));
        $next = null;
        if ($membership !== null) {
            foreach ($matchRepository->byClub($membership->clubId(), $membership->seasonId()) as $match) {
                if ($match->status()->value === 'scheduled' && !$match->scheduledDate()->isBefore($date)) { $next = ['match_id' => $match->id()->value(), 'date' => $match->scheduledDate()->toIsoString(), 'competition_id' => $match->competitionId()->value(), 'opponent_club_id' => $match->homeClubId()->value() === $membership->clubId()->value() ? $match->awayClubId()->value() : $match->homeClubId()->value()]; break; }
            }
        }
        $summary['next_scheduled_match'] = $next;

        return $summary;
    }
}

/** Small local adapter keeps the read model independent of PlayerService's Nation/Club construction dependencies. */
final class PlayerServiceProxy
{
    public function __construct(private readonly DatabaseInterface $database) {}
    public function get(PlayerId $id): \Goal\Legacy\Modules\Player\Domain\Player { return (new \Goal\Legacy\Modules\Player\Persistence\PlayerRepository($this->database))->get($id); }
}
