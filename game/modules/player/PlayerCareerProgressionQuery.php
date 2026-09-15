<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Club\Domain\Club;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Club\Persistence\ClubMembershipRepository;
use Goal\Legacy\Modules\Club\Persistence\ClubRepository;
use Goal\Legacy\Modules\Competition\Domain\Competition;
use Goal\Legacy\Modules\Competition\Persistence\CompetitionRepository;
use Goal\Legacy\Modules\Contract\Domain\Contract;
use Goal\Legacy\Modules\Contract\Persistence\ContractRepository;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Persistence\CareerOpportunityRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Transfer\Domain\TransferStatus;
use Goal\Legacy\Modules\Transfer\Persistence\TransferRepository;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Persistence\SeasonRepository;

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
        $squads = $this->clubService->squadRepository($database);
        $allMemberships = $squads->byPlayer($id);
        $contracts = new ContractRepository($database);
        $activeContract = $contracts->activeForPlayer($id);
        $currentMembership = $this->currentMembership($allMemberships, $activeContract);
        $requestedMembership = $seasonId === null
            ? $currentMembership
            : (array_values(array_filter($allMemberships, static fn (ClubSquadMembership $membership): bool => $membership->seasonId()->value() === $seasonId->value()))[0] ?? null);
        $clubRepository = $this->clubService->repository($database);
        $competitionRepository = new CompetitionRepository($database);
        $currentClub = $activeContract === null ? null : $clubRepository->get($activeContract->clubId());
        $currentCompetition = $this->competitionForMembership($database, $currentMembership, $competitionRepository);
        $seasonHistory = $this->seasonHistory($database, $id, $allMemberships, $clubRepository, $competitionRepository);
        $careerReference = (new CareerPlayerRepository($database))->byPlayer($id);
        $openOpportunities = array_values(array_filter(
            (new CareerOpportunityRepository($database))->openForPlayer($id, $date),
            static fn ($opportunity): bool => !$date->isBefore($opportunity->createdDate()),
        ));
        $developmentHistory = $development->history($database, $id);
        $summary = [
            'player' => $player->toArray(),
            'age' => $player->ageAt($date),
            'current_ovr' => $player->overallRating(),
            'career_state' => $player->careerState()->value,
            'potential' => $player->potential(),
            'development_profile' => $player->developmentProfile()->value,
            'training_focus' => $development->state($database, $id)->currentFocus()?->value,
            'career_stats' => $statistics->career($database, $id),
            'transfer_history' => array_map(static fn ($transfer): array => $transfer->toArray(), (new TransferRepository($database))->byPlayer($id)),
            'recent_development' => array_map(static fn ($entry): array => $entry->toArray(), array_slice(array_reverse($developmentHistory), 0, 5)),
            'development_history' => array_map(static fn ($entry): array => $entry->toArray(), $developmentHistory),
            'availability' => $availability->status()->value,
            'fatigue' => $availability->fatigue(),
            'active_injury' => $availability->injury()?->toArray(),
            'current_club' => $this->clubView($currentClub),
            'current_competition' => $this->competitionView($currentCompetition),
            'current_role' => $currentMembership?->role()->value,
            'current_contract' => $this->contractView($activeContract, $clubRepository),
            'season_history' => $seasonHistory,
            'role_history' => $squads->roleHistory($id),
            'contract_history' => array_map(fn (Contract $contract): array => $this->contractView($contract, $clubRepository), $contracts->byPlayer($id)),
            'movement_history' => $this->movementHistory($database, $id, $seasonHistory, $clubRepository),
        ];
        $summary['transfer_request'] = $careerReference === null ? null : [
            'status' => $careerReference->transferRequestStatus()->value,
            'season_id' => $careerReference->transferRequestSeasonId()?->value(),
        ];
        if ($seasonId !== null) {
            $summary['season_stats'] = $statistics->season($database, $id, $seasonId);
        }
        $memberships = $seasonId === null ? $allMemberships : array_values(array_filter($allMemberships, static fn (ClubSquadMembership $membership): bool => $membership->seasonId()->value() === $seasonId->value()));
        $summary['club_ids'] = array_map(static fn ($membership): string => $membership->clubId()->value(), $memberships);
        $membership = $requestedMembership;
        $summary['squad_role'] = $membership?->role()->value;
        if ($seasonId !== null) {
            $summary['season_performance'] = (new PlayerSeasonPerformanceService())->assess($database, $id, $seasonId, $membership?->clubId())->toArray();
        }
        $completedHistory = array_values(array_filter($seasonHistory, static fn (array $row): bool => $row['season_status'] === 'completed'));
        $summary['latest_season_performance'] = $completedHistory === [] ? null : $completedHistory[array_key_last($completedHistory)]['performance'];
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
        $summary['open_opportunities'] = array_map(static fn ($opportunity): array => $opportunity->toArray(), $openOpportunities);
        $summary['pending_decisions'] = array_map(static fn ($opportunity): array => [
            'id' => $opportunity->id(),
            'type' => $opportunity->type()->value,
            'status' => $opportunity->status()->value,
            'created_date' => $opportunity->createdDate()->toIsoString(),
            'expiry_date' => $opportunity->expiryDate()?->toIsoString(),
            'options' => $opportunity->context()['options'] ?? [],
        ], $openOpportunities);
        $summary['available_actions'] = $this->availableActions($careerReference, $activeContract, $currentMembership, $openOpportunities);
        $next = null;
        if ($currentMembership !== null) {
            foreach ($matchRepository->byClub($currentMembership->clubId(), $currentMembership->seasonId()) as $match) {
                if ($match->status()->value === 'scheduled' && !$match->scheduledDate()->isBefore($date)) { $next = ['match_id' => $match->id()->value(), 'date' => $match->scheduledDate()->toIsoString(), 'competition_id' => $match->competitionId()->value(), 'opponent_club_id' => $match->homeClubId()->value() === $currentMembership->clubId()->value() ? $match->awayClubId()->value() : $match->homeClubId()->value()]; break; }
            }
        }
        $summary['next_scheduled_match'] = $next;

        return $summary;
    }

    /** @param list<ClubSquadMembership> $memberships */
    private function currentMembership(array $memberships, ?Contract $contract): ?ClubSquadMembership
    {
        if ($contract === null) {
            return null;
        }
        $matches = array_values(array_filter($memberships, static fn (ClubSquadMembership $membership): bool => $membership->clubId()->value() === $contract->clubId()->value()));

        return $matches === [] ? null : $matches[array_key_last($matches)];
    }

    private function competitionForMembership(DatabaseInterface $database, ?ClubSquadMembership $membership, CompetitionRepository $competitions): ?Competition
    {
        if ($membership === null) {
            return null;
        }
        $membershipRepository = new ClubMembershipRepository($database);
        foreach ($membershipRepository->bySeason($membership->seasonId()) as $clubMembership) {
            if ($clubMembership->clubId()->value() === $membership->clubId()->value()) {
                return $competitions->get($clubMembership->competitionId());
            }
        }

        return null;
    }

    /** @param list<ClubSquadMembership> $memberships @return list<array<string, mixed>> */
    private function seasonHistory(DatabaseInterface $database, PlayerId $playerId, array $memberships, ClubRepository $clubs, CompetitionRepository $competitions): array
    {
        $seasons = [];
        foreach ((new SeasonRepository($database))->all() as $season) {
            $seasons[$season->id()->value()] = $season;
        }
        $clubMemberships = new ClubMembershipRepository($database);
        $performance = new PlayerSeasonPerformanceService();
        $history = [];
        foreach ($memberships as $membership) {
            $season = $seasons[$membership->seasonId()->value()] ?? null;
            $competition = null;
            foreach ($clubMemberships->bySeason($membership->seasonId()) as $candidate) {
                if ($candidate->clubId()->value() === $membership->clubId()->value()) {
                    $competition = $competitions->get($candidate->competitionId());
                    break;
                }
            }
            $assessment = $performance->assess($database, $playerId, $membership->seasonId(), $membership->clubId());
            $statistics = $assessment->statistics();
            $history[] = [
                'season_id' => $membership->seasonId()->value(),
                'season' => $season?->label() ?? $membership->seasonId()->value(),
                'season_start_date' => $season?->startDate()->toIsoString(),
                'season_status' => $season?->status()->value,
                'club' => $this->clubView($clubs->get($membership->clubId())),
                'competition' => $this->competitionView($competition),
                'tier' => $competition?->tier(),
                'role' => $membership->role()->value,
                'appearances' => (int) ($statistics['appearances'] ?? 0),
                'starts' => (int) ($statistics['starts'] ?? 0),
                'minutes' => (int) ($statistics['minutes'] ?? 0),
                'goals' => (int) ($statistics['goals'] ?? 0),
                'performance' => $assessment->toArray(),
            ];
        }

        usort($history, static fn (array $left, array $right): int => strcmp($left['season_id'] . ':' . ($left['club']['id'] ?? ''), $right['season_id'] . ':' . ($right['club']['id'] ?? '')));

        return $history;
    }

    /** @param list<array<string, mixed>> $seasonHistory @return list<array<string, mixed>> */
    private function movementHistory(DatabaseInterface $database, PlayerId $playerId, array $seasonHistory, ClubRepository $clubs): array
    {
        $events = [];
        foreach ((new TransferRepository($database))->byPlayer($playerId) as $transfer) {
            if ($transfer->status() !== TransferStatus::Completed) {
                continue;
            }
            $events[] = [
                'date' => $transfer->effectiveDate()->toIsoString(),
                'type' => 'transfer',
                'season_id' => $transfer->seasonId()->value(),
                'from_club' => $clubs->get($transfer->sourceClubId())->canonicalName(),
                'to_club' => $clubs->get($transfer->destinationClubId())->canonicalName(),
            ];
        }
        for ($index = 1, $count = count($seasonHistory); $index < $count; ++$index) {
            $previous = $seasonHistory[$index - 1];
            $current = $seasonHistory[$index];
            if (($previous['club']['id'] ?? null) !== ($current['club']['id'] ?? null) || $previous['tier'] === null || $current['tier'] === null || $previous['tier'] === $current['tier']) {
                continue;
            }
            $events[] = [
                'date' => $current['season_start_date'] ?? $current['season_id'],
                'type' => $current['tier'] < $previous['tier'] ? 'club_promoted' : 'club_relegated',
                'season_id' => $current['season_id'],
                'club' => $current['club'],
                'from_tier' => $previous['tier'],
                'to_tier' => $current['tier'],
            ];
        }
        usort($events, static fn (array $left, array $right): int => strcmp($left['date'] . ':' . $left['type'], $right['date'] . ':' . $right['type']));

        return $events;
    }

    /** @return array<string, mixed>|null */
    private function clubView(?Club $club): ?array
    {
        return $club === null ? null : ['id' => $club->id()->value(), 'name' => $club->canonicalName(), 'short_name' => $club->shortName(), 'nation_id' => $club->nationId()->value()];
    }

    /** @return array<string, mixed>|null */
    private function competitionView(?Competition $competition): ?array
    {
        return $competition === null ? null : ['id' => $competition->id()->value(), 'name' => $competition->name(), 'short_name' => $competition->shortName(), 'nation_id' => $competition->nationId()->value(), 'tier' => $competition->tier()];
    }

    /** @return array<string, mixed>|null */
    private function contractView(?Contract $contract, ClubRepository $clubs): ?array
    {
        if ($contract === null) {
            return null;
        }
        return ['club' => $this->clubView($clubs->get($contract->clubId())), 'start_date' => $contract->startDate()->toIsoString(), 'end_date' => $contract->endDate()->toIsoString(), 'status' => $contract->status()->value];
    }

    /** @param list<\Goal\Legacy\Modules\Player\Domain\CareerOpportunity> $opportunities @return list<array<string, mixed>> */
    private function availableActions(?\Goal\Legacy\Modules\Player\Domain\CareerPlayerReference $career, ?Contract $contract, ?ClubSquadMembership $membership, array $opportunities): array
    {
        $actions = [];
        $hasBlockingDecision = array_filter($opportunities, static fn ($opportunity): bool => in_array($opportunity->type()->value, ['contract_renewal', 'transfer_interest'], true));
        if ($career !== null && $contract !== null && $membership !== null && $career->transferRequestStatus()->value === 'none' && $hasBlockingDecision === []) {
            $actions[] = ['type' => 'request_transfer'];
        }
        if ($career !== null && $career->transferRequestStatus()->value === 'requested') {
            $actions[] = ['type' => 'withdraw_transfer_request'];
        }
        foreach ($opportunities as $opportunity) {
            $actions[] = ['type' => 'resolve_opportunity', 'opportunity_id' => $opportunity->id(), 'options' => $opportunity->context()['options'] ?? []];
        }

        return $actions;
    }
}

/** Small local adapter keeps the read model independent of PlayerService's Nation/Club construction dependencies. */
final class PlayerServiceProxy
{
    public function __construct(private readonly DatabaseInterface $database) {}
    public function get(PlayerId $id): \Goal\Legacy\Modules\Player\Domain\Player { return (new \Goal\Legacy\Modules\Player\Persistence\PlayerRepository($this->database))->get($id); }
}
