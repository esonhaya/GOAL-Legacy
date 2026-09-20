<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Club\ClubSeasonObjectiveService;
use Goal\Legacy\Modules\Club\Persistence\ClubSeasonObjectiveRepository;
use Goal\Legacy\Modules\Club\Domain\Club;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Club\Persistence\ClubMembershipRepository;
use Goal\Legacy\Modules\Club\Persistence\ClubRepository;
use Goal\Legacy\Modules\Competition\Domain\Competition;
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
use Goal\Legacy\Modules\Competition\Persistence\CompetitionRepository;
use Goal\Legacy\Modules\Contract\Domain\Contract;
use Goal\Legacy\Modules\Contract\Persistence\ContractRepository;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\TrainingIntensity;
use Goal\Legacy\Modules\Player\Persistence\CareerEventRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerOpportunityRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerPriorityRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRetirementRepository;
use Goal\Legacy\Modules\Transfer\Domain\TransferStatus;
use Goal\Legacy\Modules\Transfer\Persistence\TransferRepository;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Persistence\SeasonRepository;

final class PlayerCareerProgressionQuery
{
    public function __construct(private readonly ClubService $clubService, private readonly ?FootballSocialService $social = null)
    {
    }

    /** @return array<string, mixed> */
    public function summary(DatabaseInterface $database, PlayerId|string $playerId, SimulationDate $date, ?SeasonId $seasonId = null): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $player = (new PlayerServiceProxy($database))->get($id);
        $retirement = (new PlayerRetirementRepository($database, false))->get($id);
        $development = new PlayerDevelopmentService();
        $positions = new PositionDevelopmentService();
        $statistics = new PlayerCareerStatisticsService();
        $availability = (new PlayerAvailabilityService())->assess($database, $id, $date);
        $squads = $this->clubService->squadRepository($database);
        $allMemberships = $squads->byPlayer($id);
        $contracts = new ContractRepository($database);
        $activeContract = $player->isRetired() ? null : $contracts->activeForPlayer($id);
        $priority = (new PlayerPriorityRepository($database))->current($id);
        $currentMembership = $this->currentMembership($allMemberships, $activeContract);
        $requestedMembership = $seasonId === null
            ? $currentMembership
            : (array_values(array_filter($allMemberships, static fn (ClubSquadMembership $membership): bool => $membership->seasonId()->value() === $seasonId->value()))[0] ?? null);
        $clubRepository = $this->clubService->repository($database);
        $competitionRepository = new CompetitionRepository($database);
        $currentClub = $activeContract === null ? null : $clubRepository->get($activeContract->clubId());
        $currentCompetition = $this->competitionForMembership($database, $currentMembership, $competitionRepository);
        $seasonHistory = $this->seasonHistory($database, $id, $allMemberships, $clubRepository, $competitionRepository);
        $positionContext = $positions->context($database, $id, $date);
        $positionCompetition = $this->positionCompetition($database, $currentMembership, $player, $positionContext['secondary_positions'] ?? [], $positionContext['developing_position'] ?? null);
        $careerReference = (new CareerPlayerRepository($database))->byPlayer($id);
        $openOpportunities = $player->isRetired() ? [] : array_values(array_filter(
            (new CareerOpportunityRepository($database))->openForPlayer($id, $date),
            static fn ($opportunity): bool => !$date->isBefore($opportunity->createdDate()),
        ));
        $developmentHistory = $development->history($database, $id);
        $summary = [
            'player' => $player->toArray(),
            'position_development' => $positionContext,
            'position_history' => $positions->history($database, $id),
            'age' => $player->ageAt($date),
            'current_ovr' => $player->overallRating(),
            'current_season_id' => $seasonId?->value(),
            'current_season_label' => $seasonId === null ? null : (new SeasonRepository($database))->get($seasonId)->label(),
            'career_state' => $player->careerState()->value,
            'career_phase' => PlayerLifecycleService::careerPhase($player, $date),
            'retirement' => $retirement,
            'potential' => $player->potential(),
            'development_profile' => $player->developmentProfile()->value,
            'training_focus' => $development->state($database, $id)->currentFocus()?->value,
            'training_intensity' => TrainingIntensity::forPriority($priority)->value,
            'priority' => $priority->value,
            'career_life_history' => array_map(
                static fn ($event): array => $event->toArray(),
                array_values(array_filter(
                    (new CareerEventRepository($database))->resolvedForPlayer($id, 40),
                    static fn ($event): bool => ($event->context()['historyworthy'] ?? true) === true,
                )),
            ),
            'career_stats' => $statistics->career($database, $id),
            'transfer_history' => array_map(static fn ($transfer): array => $transfer->toArray(), (new TransferRepository($database))->byPlayer($id)),
            'recent_development' => array_map(static fn ($entry): array => $entry->toArray(), array_slice(array_reverse($developmentHistory), 0, 5)),
            'development_history' => array_map(static fn ($entry): array => $entry->toArray(), $developmentHistory),
            'availability' => $availability->status()->value,
            'fatigue' => $availability->fatigue(),
            'active_injury' => $availability->injury()?->toArray(),
            'readiness' => $availability->readiness(),
            'current_club' => $this->clubView($currentClub),
            'current_competition' => $this->competitionView($currentCompetition),
            'current_role' => $currentMembership?->role()->value,
            'position_competition' => $positionCompetition,
            'current_contract' => $this->contractView($activeContract, $clubRepository),
            'season_history' => $seasonHistory,
            'role_history' => $squads->roleHistory($id),
            'contract_history' => array_map(fn (Contract $contract): array => $this->contractView($contract, $clubRepository), $contracts->byPlayer($id)),
            'movement_history' => $this->movementHistory($database, $id, $seasonHistory, $clubRepository),
            'club_season_history' => (new ClubSeasonObjectiveRepository($database))->byPlayer($id->value()),
        ];
        $summary['transfer_request'] = $careerReference === null ? null : [
            'status' => $careerReference->transferRequestStatus()->value,
            'season_id' => $careerReference->transferRequestSeasonId()?->value(),
        ];
        if ($seasonId !== null) {
            $summary['season_stats'] = $statistics->seasonDetailed($database, $id, $seasonId);
        }
        $memberships = $seasonId === null ? $allMemberships : array_values(array_filter($allMemberships, static fn (ClubSquadMembership $membership): bool => $membership->seasonId()->value() === $seasonId->value()));
        $summary['club_ids'] = array_map(static fn ($membership): string => $membership->clubId()->value(), $memberships);
        $membership = $requestedMembership;
        $summary['squad_role'] = $membership?->role()->value;
        if ($seasonId !== null) {
            $summary['season_performance'] = (new PlayerSeasonPerformanceService())->assess($database, $id, $seasonId)->toArray();
        }
        $completedHistory = array_values(array_filter($seasonHistory, static fn (array $row): bool => $row['season_status'] === 'completed'));
        $summary['latest_season_performance'] = $completedHistory === [] ? null : $completedHistory[array_key_last($completedHistory)]['performance'];
        $summary['recent_form'] = (new PlayerFormService())->recent($database, $id);
        if ($seasonId !== null && $membership !== null && $currentClub !== null && $currentClub->id()->value() === $membership->clubId()->value()) {
            $seasonStats = is_array($summary['season_stats'] ?? null) ? $summary['season_stats'] : [];
            $summary['club_season'] = (new ClubSeasonObjectiveService($this->clubService))->context(
                $database,
                $membership->clubId(),
                $seasonId,
                $date,
                [
                    'player_id' => $id->value(),
                    'role' => $membership->role()->value,
                    'appearances' => $seasonStats['appearances'] ?? 0,
                    'starts' => $seasonStats['starts'] ?? 0,
                    'minutes' => $seasonStats['minutes'] ?? 0,
                    'goals' => $seasonStats['goals'] ?? 0,
                    'assists' => $seasonStats['assists'] ?? 0,
                    'average_match_rating' => $seasonStats['average_match_rating'] ?? null,
                    'availability' => $summary['availability'] ?? 'available',
                ],
            );
        } else {
            $summary['club_season'] = null;
        }
        $selectionRepository = new MatchSelectionRepository($database);
        $matchRepository = new MatchRepository($database);
        $selectionHistory = [];
        foreach ($selectionRepository->byPlayer($id) as $selection) {
            $match = $matchRepository->get($selection->matchId());
            $selectionHistory[] = ['date' => $match->scheduledDate()->toIsoString(), 'match_id' => $selection->matchId()->value(), 'club_id' => $selection->clubId()->value(), 'status' => $selection->status()->value];
        }
        usort($selectionHistory, static fn (array $a, array $b): int => strcmp($b['date'] . $b['match_id'], $a['date'] . $a['match_id']));
        $summary['recent_selection'] = array_slice($selectionHistory, 0, 5);
        $summary['recent_playing_time'] = $this->recentPlayingTime($database, $id, $date, $membership?->clubId()->value(), $summary['recent_selection']);
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
        $summary['available_actions'] = $player->isRetired() ? [] : $this->availableActions($careerReference, $activeContract, $currentMembership, $openOpportunities);
        $pendingCareerEvent = $player->isRetired() ? null : ((new CareerEventRepository($database))->pendingForPlayer($id)[0] ?? null);
        $summary['pending_career_event'] = $pendingCareerEvent?->toArray();
        $next = null;
        if ($currentMembership !== null) {
            foreach ($matchRepository->byClub($currentMembership->clubId(), $currentMembership->seasonId()) as $match) {
                if ($match->status()->value === 'scheduled' && !$match->scheduledDate()->isBefore($date)) { $next = ['match_id' => $match->id()->value(), 'date' => $match->scheduledDate()->toIsoString(), 'competition_id' => $match->competitionId()->value(), 'opponent_club_id' => $match->homeClubId()->value() === $currentMembership->clubId()->value() ? $match->awayClubId()->value() : $match->homeClubId()->value(), 'controlled_team_id' => $currentMembership->clubId()->value()]; break; }
            }
        }
        $summary['international'] = $this->internationalContext($database, $player, $seasonId, $date);
        $socialService = $this->social ?? new FootballSocialService($this->clubService);
        $summary['social'] = $socialService->context($database, $id);
        $summary['social_history'] = $socialService->history($database, $id, 12);
        $summary['manager_context'] = (new ManagerTrustService())->derive($summary);
        $summary['career_outlook'] = (new CareerOutlookService())->derive($summary, $date);
        $internationalNext = $summary['international']['next_fixture'] ?? null;
        if (is_array($internationalNext) && ($next === null || strcmp((string) $internationalNext['date'] . (string) $internationalNext['match_id'], (string) $next['date'] . (string) $next['match_id']) < 0)) {
            $next = ['match_id' => $internationalNext['match_id'], 'date' => $internationalNext['date'], 'competition_id' => $internationalNext['competition_id'], 'opponent_club_id' => $internationalNext['opponent_team_id'], 'controlled_team_id' => $summary['international']['team_id']];
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
        $clubMemberships = array_values(array_filter($membershipRepository->bySeason($membership->seasonId()), static fn ($clubMembership): bool => $clubMembership->clubId()->value() === $membership->clubId()->value()));
        usort($clubMemberships, function ($left, $right) use ($competitions): int {
            $leftCompetition = $competitions->get($left->competitionId());
            $rightCompetition = $competitions->get($right->competitionId());
            return (($leftCompetition->type() === CompetitionType::DomesticLeague ? 0 : 1) <=> ($rightCompetition->type() === CompetitionType::DomesticLeague ? 0 : 1)) ?: strcmp($leftCompetition->id()->value(), $rightCompetition->id()->value());
        });
        foreach ($clubMemberships as $clubMembership) {
            return $competitions->get($clubMembership->competitionId());
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function positionCompetition(DatabaseInterface $database, ?ClubSquadMembership $membership, Player $player, array $secondaryPositions = [], ?string $developingPosition = null): ?array
    {
        if ($membership === null) {
            return null;
        }
        $samePosition = [];
        $players = new PlayerRepository($database);
        $roles = [];
        foreach ($this->clubService->squadRepository($database)->byClub($membership->clubId(), $membership->seasonId()) as $candidateMembership) {
            $roles[$candidateMembership->playerId()->value()] = $candidateMembership->role()->value;
            if ($candidateMembership->playerId()->value() === $player->id()->value()) {
                continue;
            }
            $candidate = $players->get($candidateMembership->playerId());
            $candidatePosition = $candidate->primaryPosition()->value;
            $capablePositions = array_merge([$player->primaryPosition()->value], array_values(array_filter(array_map('strval', $secondaryPositions))));
            if ($developingPosition !== null && in_array($developingPosition, array_map(static fn ($position): string => $position->value, PositionDevelopmentRules::compatibleWith($player->primaryPosition())), true)) {
                $capablePositions[] = $developingPosition;
            }
            if (!in_array($candidatePosition, array_values(array_unique($capablePositions)), true)) {
                continue;
            }
            $samePosition[] = [
                'player_id' => $candidate->id()->value(),
                'name' => $candidate->preferredName(),
                'position' => $candidate->primaryPosition()->value,
                'overall' => $candidate->overallRating(),
                'role' => $roles[$candidate->id()->value()] ?? null,
            ];
        }
        usort($samePosition, static fn (array $left, array $right): int => (($right['overall'] <=> $left['overall']) ?: strcmp($left['player_id'], $right['player_id'])));
        $higher = array_values(array_filter($samePosition, static fn (array $candidate): bool => $candidate['overall'] > $player->overallRating()));
        $ratings = array_map(static fn (array $candidate): int => (int) $candidate['overall'], $samePosition);

        return [
            'position' => $player->primaryPosition()->value,
            'same_position_count' => count($samePosition),
            'higher_ovr_count' => count($higher),
            'average_ovr' => $ratings === [] ? null : (int) round(array_sum($ratings) / count($ratings)),
            'competitors' => array_slice($samePosition, 0, 3),
        ];
    }

    /**
     * Derive a compact current-club playing-time window from canonical
     * selections and Player Match statistics. No daily or weekly rows are
     * introduced, and this is only read for the controlled Career summary.
     * @param list<array<string, mixed>> $recentSelection
     * @return array<string, mixed>
     */
    private function recentPlayingTime(DatabaseInterface $database, PlayerId $playerId, SimulationDate $date, ?string $clubId, array $recentSelection): array
    {
        if ($clubId === null || $clubId === '' || $recentSelection === []) {
            return ['window' => 0, 'appearances' => 0, 'starts' => 0, 'minutes' => 0, 'minutes_share' => 0.0, 'bench' => 0, 'not_selected' => 0, 'unavailable' => 0, 'red_cards' => 0];
        }
        $stats = [];
        foreach ((new PlayerMatchStatRepository($database))->byPlayer($playerId) as $stat) {
            $stats[$stat->matchId()->value()] = $stat;
        }
        $matches = new MatchRepository($database);
        $window = 0;
        $appearances = 0;
        $starts = 0;
        $minutes = 0;
        $bench = 0;
        $notSelected = 0;
        $unavailable = 0;
        $redCards = 0;
        foreach ($recentSelection as $selection) {
            if (($selection['club_id'] ?? null) !== $clubId || !is_string($selection['match_id'] ?? null)) { continue; }
            try {
                $match = $matches->get((string) $selection['match_id']);
            } catch (\Throwable) {
                continue;
            }
            if ($match->scheduledDate()->isAfter($date)) { continue; }
            ++$window;
            $status = (string) ($selection['status'] ?? 'not_selected');
            if ($status === 'bench') { ++$bench; }
            if ($status === 'not_selected') { ++$notSelected; }
            if ($status === 'unavailable') { ++$unavailable; }
            $stat = $stats[(string) $selection['match_id']] ?? null;
            if ($stat === null || !$stat->appeared()) { continue; }
            ++$appearances;
            $starts += $stat->started() ? 1 : 0;
            $minutes += $stat->minutes();
            $redCards += $stat->redCards();
        }

        return [
            'window' => $window,
            'appearances' => $appearances,
            'starts' => $starts,
            'minutes' => $minutes,
            'minutes_share' => $window === 0 ? 0.0 : round(min(1.0, $minutes / ($window * 90)), 4),
            'bench' => $bench,
            'not_selected' => $notSelected,
            'unavailable' => $unavailable,
            'red_cards' => $redCards,
        ];
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
            $candidates = array_values(array_filter($clubMemberships->bySeason($membership->seasonId()), static fn ($candidate): bool => $candidate->clubId()->value() === $membership->clubId()->value()));
            usort($candidates, function ($left, $right) use ($competitions): int {
                $leftCompetition = $competitions->get($left->competitionId());
                $rightCompetition = $competitions->get($right->competitionId());
                return (($leftCompetition->type() === CompetitionType::DomesticLeague ? 0 : 1) <=> ($rightCompetition->type() === CompetitionType::DomesticLeague ? 0 : 1)) ?: strcmp($leftCompetition->id()->value(), $rightCompetition->id()->value());
            });
            if ($candidates !== []) { $competition = $competitions->get($candidates[0]->competitionId()); }
            $assessment = $performance->assess($database, $playerId, $membership->seasonId());
            $factualStatistics = (new PlayerCareerStatisticsService())->seasonDetailed($database, $playerId, $membership->seasonId());
            $history[] = [
                'season_id' => $membership->seasonId()->value(),
                'season' => $season?->label() ?? $membership->seasonId()->value(),
                'season_start_date' => $season?->startDate()->toIsoString(),
                'season_status' => $season?->status()->value,
                'club' => $this->clubView($clubs->get($membership->clubId())),
                'competition' => $this->competitionView($competition),
                'tier' => $competition?->tier(),
                'role' => $membership->role()->value,
                'appearances' => (int) ($factualStatistics['appearances'] ?? 0),
                'starts' => (int) ($factualStatistics['starts'] ?? 0),
                'minutes' => (int) ($factualStatistics['minutes'] ?? 0),
                'goals' => (int) ($factualStatistics['goals'] ?? 0),
                'assists' => (int) ($factualStatistics['assists'] ?? 0),
                'rated_appearances' => (int) ($factualStatistics['rated_appearances'] ?? 0),
                'average_match_rating' => $factualStatistics['average_match_rating'] ?? null,
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

    /** @return array<string,mixed> */
    private function internationalContext(DatabaseInterface $database, Player $player, ?SeasonId $seasonId, SimulationDate $date): array
    {
        if ($seasonId === null || (int) $database->connection()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'international_team_squads'")->fetchColumn() === 0) {
            return ['team_id' => null, 'country' => null, 'selection_status' => 'not_selected', 'selected' => false, 'next_fixture' => null, 'stats' => [], 'history' => []];
        }
        $teamId = 'national-team-' . $player->primaryNationId()->value();
        $status = $database->connection()->prepare('SELECT status FROM international_team_squads WHERE season_id = :season_id AND national_team_id = :team_id AND player_id = :player_id LIMIT 1');
        $status->execute(['season_id' => $seasonId->value(), 'team_id' => $teamId, 'player_id' => $player->id()->value()]);
        $selection = $status->fetchColumn();
        $name = $database->connection()->prepare('SELECT display_name FROM national_team_records WHERE id = :id'); $name->execute(['id' => $teamId]); $country = $name->fetchColumn();
        $next = null;
        if ($selection === 'selected') {
            foreach ((new MatchRepository($database))->byClub($teamId, $seasonId) as $match) {
                if ($match->status()->value === 'scheduled' && !$match->scheduledDate()->isBefore($date)) { $next = ['match_id' => $match->id()->value(), 'date' => $match->scheduledDate()->toIsoString(), 'competition_id' => $match->competitionId()->value(), 'opponent_team_id' => $match->homeClubId()->value() === $teamId ? $match->awayClubId()->value() : $match->homeClubId()->value()]; break; }
            }
        }
        $stats = ['caps' => 0, 'starts' => 0, 'minutes' => 0, 'goals' => 0, 'assists' => 0, 'yellow_cards' => 0, 'red_cards' => 0, 'rated_appearances' => 0, 'average_rating' => null];
        $history = [];
        if ((int) $database->connection()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'international_player_statistics'")->fetchColumn() > 0) {
            $row = $database->connection()->prepare('SELECT COALESCE(SUM(caps),0) caps, COALESCE(SUM(starts),0) starts, COALESCE(SUM(minutes),0) minutes, COALESCE(SUM(goals),0) goals, COALESCE(SUM(assists),0) assists, COALESCE(SUM(yellow_cards),0) yellow_cards, COALESCE(SUM(red_cards),0) red_cards, COALESCE(SUM(rated_appearances),0) rated_appearances, COALESCE(SUM(rating_total),0) rating_total FROM international_player_statistics WHERE player_id = :player_id AND season_id = :season_id'); $row->execute(['player_id' => $player->id()->value(), 'season_id' => $seasonId->value()]); $values = $row->fetch(\PDO::FETCH_ASSOC) ?: []; foreach (array_keys($stats) as $key) { if ($key !== 'average_rating') { $stats[$key] = (int) ($values[$key] ?? 0); } } $stats['average_rating'] = ((int) ($stats['caps'] ?? 0)) === 0 ? null : round((float) ($values['rating_total'] ?? 0) / (int) $stats['caps'], 2);
            $historyStatement = $database->connection()->prepare('SELECT season_id, competition_id, caps, goals, starts, minutes FROM international_player_statistics WHERE player_id = :player_id ORDER BY season_id ASC, competition_id ASC'); $historyStatement->execute(['player_id' => $player->id()->value()]); $history = $historyStatement->fetchAll(\PDO::FETCH_ASSOC);
        }

        return ['team_id' => $teamId, 'country' => is_string($country) ? $country : $player->primaryNationId()->value() . ' National Team', 'selection_status' => is_string($selection) ? $selection : 'not_selected', 'selected' => $selection === 'selected', 'next_fixture' => $next, 'stats' => $stats, 'history' => $history];
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
