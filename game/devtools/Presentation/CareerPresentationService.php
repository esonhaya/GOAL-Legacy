<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Presentation;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
use Goal\Legacy\Modules\Competition\DomesticCupService;
use Goal\Legacy\Modules\Competition\EuropeanCompetitionService;
use Goal\Legacy\Modules\Competition\Domain\Competition;
use Goal\Legacy\Modules\Competition\Persistence\CompetitionRepository;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Match\Domain\SelectionStatus;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
use Goal\Legacy\Modules\Match\Persistence\MatchSubstitutionRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Match\PlayerMatchRatingService;
use Goal\Legacy\Modules\Nation\Persistence\NationRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerOpportunityRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerLegacyRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\Player\CareerLegacyService;
use Goal\Legacy\Modules\Player\PlayerCareerStatisticsService;
use Goal\Legacy\Modules\Player\PlayerFormService;
use Goal\Legacy\Modules\Club\Persistence\ClubMembershipRepository;
use Goal\Legacy\Modules\Club\Persistence\ClubSquadRepository;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\SeasonId;

/** Assembles presentation input from canonical services and persisted facts. */
final class CareerPresentationService
{
    public function __construct(private readonly CoreServices $services)
    {
    }

    /** @return array{world:object,summary:array<string,mixed>,date:SimulationDate} */
    public function snapshot(DatabaseInterface $database, string $saveId, bool $includeLegacy = true): array
    {
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->load($database, $saveId);
        $date = $world->currentDate($worldService->calendar());
        $career = (new CareerPlayerRepository($database))->get($saveId);
        $summary = (new PlayerCareerProgressionQuery($this->services->clubModule()->service(), $this->services->playerModule()->service()->socialService()))->summary(
            $database,
            $career->playerId(),
            $date,
            $world->currentSeasonId(),
        );
        if ($includeLegacy) {
            $summary['legacy'] = $this->legacyService()->summary($database, $career->playerId()->value());
        }
        $currentClubId = is_array($summary['current_club'] ?? null) ? (string) ($summary['current_club']['id'] ?? '') : '';
        $summary['cup_history'] = $currentClubId === '' ? [] : (new DomesticCupService($this->services->clubModule()->service()))->historyForClub($database, $currentClubId);
        $summary['europe_history'] = $currentClubId === '' ? [] : (new EuropeanCompetitionService($this->services->clubModule()->service(), new DomesticCupService($this->services->clubModule()->service())))->historyForClub($database, $currentClubId);

        return ['world' => $world, 'summary' => $summary, 'date' => $date];
    }

    /**
     * Public player read model for profile and squad screens.
     * Compact world-fidelity aggregates are the fallback for NPCs; no hidden
     * development fields are exposed by this boundary.
     * @return array<string, mixed>
     */
    public function playerProfile(DatabaseInterface $database, string $saveId, string $playerId): array
    {
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->load($database, $saveId);
        $date = $world->currentDate($worldService->calendar());
        $seasonId = $world->currentSeasonId();
        $player = (new PlayerRepository($database))->get($playerId);
        $memberships = (new ClubSquadRepository($database))->byPlayer($playerId, $seasonId);
        $career = (new CareerPlayerRepository($database))->get($saveId);
        $controlled = $career->playerId()->value() === $playerId;
        $controlledSummary = $controlled ? $this->controlledSummary($database, $saveId) : [];
        if ($controlled) {
            $currentClubId = is_array($controlledSummary['current_club'] ?? null)
                ? (string) ($controlledSummary['current_club']['id'] ?? '')
                : '';
            $memberships = array_values(array_filter(
                $memberships,
                static fn ($membership): bool => $currentClubId !== '' && $membership->clubId()->value() === $currentClubId,
            ));
        }
        $membership = $memberships[0] ?? null;
        $club = null;
        $competition = null;
        if ($membership !== null) {
            $club = $this->services->clubModule()->service()->repository($database)->get($membership->clubId());
            $competition = $this->primaryCompetitionForClub($database, $club->id()->value(), $seasonId);
        }
        $statistics = new PlayerCareerStatisticsService();
        $stats = $statistics->seasonDetailed($database, $playerId, $seasonId);
        $careerStats = $statistics->careerDetailed($database, $playerId);
        $form = (new PlayerFormService())->recent($database, $playerId, 5, $club?->id()->value());
        $nation = (new NationRepository($database))->find($player->primaryNationId());
        $cupStats = null;
        $cupHistory = [];
        $europeStats = [];
        $europeHistory = [];
        $internationalStats = $this->services->nationalTeams()->playerStats($database, $playerId, $seasonId);
        $internationalHistory = $this->services->internationalCompetitions()->history($database, $playerId);
        $internationalContext = (new PlayerCareerProgressionQuery($this->services->clubModule()->service()))->summary($database, $playerId, $date, $seasonId)['international'] ?? [];
        $social = $this->services->playerModule()->service()->socialService();
        $legacyRepository = new CareerLegacyRepository($database, false);
        $legacy = $controlled ? [
            'honours' => $legacyRepository->honoursForPlayer($playerId),
            'awards' => $legacyRepository->awardsForPlayer($playerId),
            'records' => $legacyRepository->recordsForPlayer($playerId),
            'milestones' => $legacyRepository->milestonesForPlayer($playerId),
        ] : ['honours' => [], 'awards' => [], 'records' => [], 'milestones' => []];
        if ($club !== null) {
            $cups = new DomesticCupService($this->services->clubModule()->service());
            $europe = new EuropeanCompetitionService($this->services->clubModule()->service(), $cups);
            foreach (array_filter((new ClubMembershipRepository($database))->byClub($club->id()), static fn ($candidate): bool => $candidate->seasonId()->value() === $seasonId->value()) as $clubMembership) {
                $cup = (new CompetitionRepository($database))->get($clubMembership->competitionId());
                if ($cup->type() === CompetitionType::DomesticCup) {
                    $cupHistory = $cups->historyForClub($database, $club->id()->value());
                    if ($controlled) {
                        $cupStats = $statistics->seasonCompetitionDetailed($database, $playerId, $seasonId, $cup->id()->value());
                    }
                } elseif ($cup->type() === CompetitionType::Continental) {
                    $europeHistory = $europe->historyForClub($database, $club->id()->value());
                    if ($controlled) {
                        $europeStats[$cup->id()->value()] = $statistics->seasonCompetitionDetailed($database, $playerId, $seasonId, $cup->id()->value());
                    }
                }
            }
        }

        return [
            'player' => $player,
            'age' => $player->ageAt($date),
            'nationality' => $nation?->displayName() ?? $player->primaryNationId()->value(),
            'club' => $club,
            'competition' => $competition,
            'role' => $membership?->role()->value,
            'season_id' => $seasonId->value(),
            'season_stats' => $stats,
            'cup_stats' => $cupStats,
            'cup_history' => $cupHistory,
            'europe_stats' => $europeStats,
            'europe_history' => $europeHistory,
            'international_stats' => $internationalStats,
            'international_history' => $internationalHistory,
            'international' => $internationalContext,
            'social' => $social->context($database, $playerId),
            'relationships' => $controlled ? $social->relationships($database, $playerId) : [],
            'social_history' => $controlled ? $social->history($database, $playerId, 8) : [],
            'career_stats' => $careerStats,
            'recent_form' => $form,
            'match_history' => $this->playerMatchHistory($database, $playerId, $seasonId, $club?->id()->value()),
            'legacy' => $legacy,
            'controlled' => $controlled,
            'training_focus' => $controlled ? $controlledSummary['training_focus'] ?? null : null,
            'priority' => $controlled ? $controlledSummary['priority'] ?? null : null,
            'contract' => $controlled ? $controlledSummary['current_contract'] ?? null : null,
        ];
    }

    /** @return array<string, mixed> */
    public function competitionView(DatabaseInterface $database, string $competitionId, SeasonId $seasonId, SimulationDate $date, ?string $controlledClubId = null): array
    {
        $competition = (new CompetitionRepository($database))->get($competitionId);
        $clubs = $this->services->clubModule()->service()->repository($database);
        $matchService = $this->services->matchModule()->service();
        $standings = [];
        if ($competition->type() === CompetitionType::DomesticLeague) {
            foreach ($matchService->standings($database, $competition->id(), $seasonId) as $row) {
                $standings[] = $row + [
                    'club' => $clubs->get((string) $row['club_id'])->canonicalName(),
                    'controlled' => $controlledClubId !== null && (string) $row['club_id'] === $controlledClubId,
                ];
            }
        }
        $recent = [];
        $upcoming = [];
        foreach ($matchService->repository($database)->byCompetition($competition->id(), $seasonId) as $match) {
            if ($match->status() === MatchStatus::Completed && !$match->scheduledDate()->isAfter($date)) {
                $recent[] = $this->fixtureText($database, $match, $controlledClubId ?? '');
            }
            if ($match->status() === MatchStatus::Scheduled && !$match->scheduledDate()->isBefore($date)) {
                $upcoming[] = $this->fixtureText($database, $match, $controlledClubId ?? '');
            }
        }

        $view = [
            'competition' => $competition,
            'standings' => $standings,
            'recent_results' => array_slice(array_reverse($recent), 0, 8),
            'upcoming_fixtures' => array_slice($upcoming, 0, 8),
        ];
        if ($competition->type() === CompetitionType::DomesticCup) {
            $view['cup'] = (new DomesticCupService($this->services->clubModule()->service()))->view($database, $competition->id()->value(), $seasonId, $controlledClubId);
        } elseif ($competition->type() === CompetitionType::Continental) {
            $cups = new DomesticCupService($this->services->clubModule()->service());
            $view['europe'] = (new EuropeanCompetitionService($this->services->clubModule()->service(), $cups))->view($database, $competition->id()->value(), $seasonId, $controlledClubId);
        } elseif ($competition->type() === CompetitionType::International) {
            $controlledTeamId = $this->services->nationalTeams()->isNationalTeam((string) ($controlledClubId ?? '')) ? $controlledClubId : null;
            $view['international'] = $this->services->internationalCompetitions()->view($database, $competition->id()->value(), $seasonId, $controlledTeamId);
        }

        return $view;
    }

    /** @return array<string, mixed> */
    public function clubView(DatabaseInterface $database, string $clubId, SeasonId $seasonId, SimulationDate $date, ?string $controlledClubId = null): array
    {
        $club = $this->services->clubModule()->service()->repository($database)->get($clubId);
        $membership = null;
        $competition = null;
        foreach ((new ClubMembershipRepository($database))->byClub($club->id()) as $candidate) {
            if ($candidate->seasonId()->value() === $seasonId->value()) { $membership = $candidate; break; }
        }
        $competition = $this->primaryCompetitionForClub($database, $club->id()->value(), $seasonId);
        $standings = $competition === null ? [] : $this->competitionView($database, $competition->id()->value(), $seasonId, $date, $controlledClubId)['standings'];
        $position = null;
        foreach ($standings as $index => $row) {
            if ((string) ($row['club_id'] ?? '') === $clubId) { $position = $index + 1; break; }
        }
        $matches = $this->services->matchModule()->service()->repository($database)->byClub($club->id(), $seasonId);
        $recent = [];
        $upcoming = [];
        foreach ($matches as $match) {
            if ($match->status() === MatchStatus::Completed && !$match->scheduledDate()->isAfter($date)) { $recent[] = $this->fixtureText($database, $match, $controlledClubId ?? ''); }
            if ($match->status() === MatchStatus::Scheduled && !$match->scheduledDate()->isBefore($date)) { $upcoming[] = $this->fixtureText($database, $match, $controlledClubId ?? ''); }
        }

        return [
            'club' => $club,
            'competition' => $competition,
            'position' => $position,
            'recent_results' => array_slice(array_reverse($recent), 0, 5),
            'upcoming_fixtures' => array_slice($upcoming, 0, 5),
        ];
    }

    /** @return array<string, mixed> */
    private function controlledSummary(DatabaseInterface $database, string $saveId): array
    {
        return $this->snapshot($database, $saveId)['summary'];
    }

    /**
     * Controlled Players retain detailed personal Match evidence. World-only
     * Players receive compact Club result history because their action rows
     * are intentionally not persisted by World fidelity.
     * @return list<array<string, mixed>>
     */
    private function playerMatchHistory(DatabaseInterface $database, string $playerId, SeasonId $seasonId, ?string $clubId): array
    {
        $matches = $this->services->matchModule()->service()->repository($database);
        $competitions = new CompetitionRepository($database);
        $player = (new PlayerRepository($database))->get($playerId);
        $ratings = new PlayerMatchRatingService();
        $detailed = [];
        foreach ((new PlayerMatchStatRepository($database))->byPlayer($playerId) as $stat) {
            $match = $matches->get($stat->matchId());
            if ($match->seasonId()->value() !== $seasonId->value() || $match->status() !== MatchStatus::Completed) { continue; }
            $result = $match->result();
            $detailed[] = [
                'date' => $match->scheduledDate()->toIsoString(),
                'competition' => $competitions->get($match->competitionId())->name(),
                'home' => $this->teamName($database, $match->homeClubId()->value()),
                'away' => $this->teamName($database, $match->awayClubId()->value()),
                'home_goals' => $result?->homeGoals() ?? 0,
                'away_goals' => $result?->awayGoals() ?? 0,
                'minutes' => $stat->appeared() ? $stat->minutes() : null,
                'rating' => $stat->appeared() ? $ratings->rate($stat, $player->primaryPosition()) : null,
                'detailed' => true,
            ];
        }
        if ($detailed !== []) {
            usort($detailed, static fn (array $left, array $right): int => strcmp((string) $right['date'], (string) $left['date']));
            return array_slice($detailed, 0, 5);
        }
        if ($clubId === null) { return []; }
        $compact = [];
        foreach ($matches->byClub($clubId, $seasonId) as $match) {
            if ($match->status() !== MatchStatus::Completed) { continue; }
            $result = $match->result();
            $compact[] = [
                'date' => $match->scheduledDate()->toIsoString(),
                'competition' => $competitions->get($match->competitionId())->name(),
                'home' => $this->teamName($database, $match->homeClubId()->value()),
                'away' => $this->teamName($database, $match->awayClubId()->value()),
                'home_goals' => $result?->homeGoals() ?? 0,
                'away_goals' => $result?->awayGoals() ?? 0,
                'minutes' => null,
                'rating' => null,
                'detailed' => false,
            ];
        }
        usort($compact, static fn (array $left, array $right): int => strcmp((string) $right['date'], (string) $left['date']));

        return array_slice($compact, 0, 5);
    }

    /** @param array<string, mixed> $summary @return array<string, mixed>|null */
    public function nextMatch(DatabaseInterface $database, array $summary): ?array
    {
        $next = $summary['next_scheduled_match'] ?? null;
        if (!is_array($next) || !isset($next['match_id'])) {
            return null;
        }
        $match = $this->services->matchModule()->service()->repository($database)->get((string) $next['match_id']);
        $competition = (new CompetitionRepository($database))->get($match->competitionId());

        return [
            'match_id' => $match->id()->value(),
            'date' => $match->scheduledDate()->toIsoString(),
            'home_club' => $this->teamName($database, $match->homeClubId()->value()),
            'away_club' => $this->teamName($database, $match->awayClubId()->value()),
            'competition' => $competition->name(),
            'competition_type' => $competition->type()->value,
            'competition_id' => $competition->id()->value(),
            'season_id' => $match->seasonId()->value(),
            'round' => in_array($competition->type(), [CompetitionType::DomesticCup, CompetitionType::Continental, CompetitionType::International], true)
                ? (($competition->type() === CompetitionType::DomesticCup
                    ? (new DomesticCupService($this->services->clubModule()->service()))->matchResolution($database, $match->id()->value())
                    : ($competition->type() === CompetitionType::Continental
                        ? (new EuropeanCompetitionService($this->services->clubModule()->service(), new DomesticCupService($this->services->clubModule()->service())))->matchResolution($database, $match->id()->value())
                        : $this->services->internationalCompetitions()->matchResolution($database, $match->id()->value())))['stage'] ?? null)
                : null,
        ];
    }

    /** @param array<string, mixed> $summary @return array<string, int|string>|null */
    public function clubContext(DatabaseInterface $database, array $summary): ?array
    {
        $club = is_array($summary['current_club'] ?? null) ? $summary['current_club'] : null;
        $competition = is_array($summary['current_competition'] ?? null) ? $summary['current_competition'] : null;
        $seasonId = $this->seasonId($summary);
        if ($club === null || $competition === null || $seasonId === null || !isset($competition['id'])) {
            return null;
        }
        $competitionRecord = (new CompetitionRepository($database))->get((string) $competition['id']);
        if ($competitionRecord->type() !== CompetitionType::DomesticLeague) {
            return null;
        }
        $table = $this->services->matchModule()->service()->standings($database, $competitionRecord->id(), $seasonId);
        foreach ($table as $index => $row) {
            if (($row['club_id'] ?? null) === ($club['id'] ?? null)) {
                return [
                    'position' => $index + 1,
                    'played' => (int) ($row['played'] ?? 0),
                    'points' => (int) ($row['points'] ?? 0),
                ];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $summary @return array<string, mixed> */
    public function matchday(DatabaseInterface $database, GameMatch $match, string $playerId, ?string $controlledClubId = null): array
    {
        $matchService = $this->services->matchModule()->service();
        $players = new PlayerRepository($database);
        $competition = (new CompetitionRepository($database))->get($match->competitionId());
        $homeName = $this->teamName($database, $match->homeClubId()->value());
        $awayName = $this->teamName($database, $match->awayClubId()->value());
        $player = $players->get($playerId);
        $selection = $this->playerSelection($database, $match, $playerId);
        $performance = $matchService->playerSummary($database, $match->id(), $playerId);
        $performance ??= [
            'appeared' => false,
            'started' => false,
            'minutes' => 0,
            'rating' => null,
        ];
        $performance['selection_status'] = $selection?->status()->value ?? SelectionStatus::NotSelected->value;
        $performance['position'] = $player->primaryPosition()->value;
        $performance['player_name'] = $player->preferredName();
        $performance['substitution_minute'] = $this->substitutionMinute($database, $match, $playerId);
        $controlledClubId ??= (string) ($this->services->clubModule()->service()->squadRepository($database)->byPlayer($playerId, $match->seasonId())[0]?->clubId()->value() ?? ($performance['club_id'] ?? ''));
        if ($competition->type() === CompetitionType::International && !in_array($controlledClubId, [$match->homeClubId()->value(), $match->awayClubId()->value()], true)) {
            $controlledClubId = 'national-team-' . $player->primaryNationId()->value();
        }
        if ($controlledClubId === '') {
            $controlledClubId = $performance['club_id'] ?? $match->homeClubId()->value();
        }
        $result = $match->result();
        $homeGoals = $result?->homeGoals() ?? 0;
        $awayGoals = $result?->awayGoals() ?? 0;
        $controlledHome = $controlledClubId === $match->homeClubId()->value();
        $controlledGoals = $controlledHome ? $homeGoals : $awayGoals;
        $opponentGoals = $controlledHome ? $awayGoals : $homeGoals;
        $perspectiveResult = $controlledGoals === $opponentGoals ? 'draw' : ($controlledGoals > $opponentGoals ? 'win' : 'loss');
        $cupResolution = null;
        $cupProgression = null;
        $europeResolution = null;
        $europeProgression = null;
        $internationalResolution = null;
        $internationalProgression = null;
        if ($competition->type() === CompetitionType::DomesticCup) {
            $cups = new DomesticCupService($this->services->clubModule()->service());
            $cupResolution = $cups->matchResolution($database, $match->id()->value());
            if (is_array($cupResolution) && is_string($cupResolution['winner_club_id'] ?? null)) {
                $perspectiveResult = $cupResolution['winner_club_id'] === $controlledClubId ? 'win' : 'loss';
                $cup = $cups->view($database, $competition->id()->value(), $match->seasonId(), $controlledClubId);
                if (($cup['status'] ?? null) === 'completed') {
                    $cupProgression = ($cup['winner_club_id'] ?? null) === $controlledClubId
                        ? 'WINNER'
                        : (($cup['runner_up_club_id'] ?? null) === $controlledClubId ? 'RUNNER-UP' : 'ELIMINATED');
                } else {
                    $cupProgression = ($cupResolution['winner_club_id'] ?? null) === $controlledClubId ? 'ADVANCED' : 'ELIMINATED';
                }
            }
        }
        if ($competition->type() === CompetitionType::Continental) {
            $europe = new EuropeanCompetitionService($this->services->clubModule()->service(), new DomesticCupService($this->services->clubModule()->service()));
            $europeResolution = $europe->matchResolution($database, $match->id()->value());
            if (is_array($europeResolution) && is_string($europeResolution['winner_club_id'] ?? null)) {
                $perspectiveResult = $europeResolution['winner_club_id'] === $controlledClubId ? 'win' : 'loss';
                $europeView = $europe->view($database, $competition->id()->value(), $match->seasonId(), $controlledClubId);
                $europeProgression = ($europeView['status'] ?? null) === 'completed'
                    ? (($europeView['winner_club_id'] ?? null) === $controlledClubId ? 'WINNER' : (($europeView['runner_up_club_id'] ?? null) === $controlledClubId ? 'RUNNER-UP' : 'ELIMINATED'))
                    : ($europeResolution['winner_club_id'] === $controlledClubId ? 'ADVANCED' : 'ELIMINATED');
            }
        }
        if ($competition->type() === CompetitionType::International) {
            $internationalResolution = $this->services->internationalCompetitions()->matchResolution($database, $match->id()->value());
            if (is_array($internationalResolution) && is_string($internationalResolution['winner_club_id'] ?? null)) {
                $perspectiveResult = $internationalResolution['winner_club_id'] === $controlledClubId ? 'win' : 'loss';
                $internationalView = $this->services->internationalCompetitions()->view($database, $competition->id()->value(), $match->seasonId(), $controlledClubId);
                $internationalProgression = ($internationalView['status'] ?? null) === 'completed'
                    ? (($internationalView['winner_team_id'] ?? null) === $controlledClubId ? 'WINNER' : (($internationalView['runner_up_team_id'] ?? null) === $controlledClubId ? 'RUNNER-UP' : 'ELIMINATED'))
                    : ($internationalResolution['winner_club_id'] === $controlledClubId ? 'ADVANCED' : 'ELIMINATED');
            }
        }
        $postDate = $match->scheduledDate();
        $postSummary = (new PlayerCareerProgressionQuery($this->services->clubModule()->service(), $this->services->playerModule()->service()->socialService()))->summary(
            $database,
            $player->id(),
            $postDate,
            $match->seasonId(),
        );
        $postContext = $competition->type() === CompetitionType::DomesticLeague
            ? $this->clubContext($database, $postSummary)
            : null;

        return [
            'competition' => $competition->name(),
            'competition_type' => $competition->type()->value,
            'date' => $match->scheduledDate()->toIsoString(),
            'home_club' => $homeName,
            'away_club' => $awayName,
            'controlled_club_id' => $controlledClubId,
            'controlled_club' => $controlledHome ? $homeName : $awayName,
            'result' => ['home_goals' => $homeGoals, 'away_goals' => $awayGoals],
            'perspective_result' => $perspectiveResult,
            'cup_resolution' => $cupResolution,
            'cup_progression' => $cupProgression,
            'europe_resolution' => $europeResolution,
            'europe_progression' => $europeProgression,
            'international_resolution' => $internationalResolution,
            'international_progression' => $internationalProgression,
            'rival_context' => $this->services->playerModule()->service()->socialService()->matchContext($database, $match, $playerId),
            'performance' => $performance,
            'highlights' => $this->highlightLines($database, $match, $playerId),
            'post_match' => [
                'recent_form' => $postSummary['recent_form'] ?? [],
                'season_stats' => $postSummary['season_stats'] ?? [],
                'season_performance' => $postSummary['season_performance'] ?? [],
                'club_position' => $postContext['position'] ?? null,
                'club_points' => $postContext['points'] ?? null,
            ],
        ];
    }

    /** @param array<string, mixed> $summary @return array<string, mixed> */
    public function world(DatabaseInterface $database, array $summary, SimulationDate $date): array
    {
        $competition = is_array($summary['current_competition'] ?? null) ? $summary['current_competition'] : null;
        $club = is_array($summary['current_club'] ?? null) ? $summary['current_club'] : null;
        $seasonId = $this->seasonId($summary);
        if ($competition === null || $club === null || $seasonId === null) {
            return ['competition' => 'No current competition', 'standings' => [], 'recent_result' => null, 'next_fixture' => null, 'recent_results' => [], 'upcoming_fixtures' => []];
        }
        $competitionRecord = (new CompetitionRepository($database))->get((string) $competition['id']);
        $matchService = $this->services->matchModule()->service();
        $clubs = $this->services->clubModule()->service()->repository($database);
        $standings = [];
        if ($competitionRecord->type() === CompetitionType::DomesticLeague) {
            foreach ($matchService->standings($database, $competitionRecord->id(), $seasonId) as $row) {
                $standings[] = $row + [
                    'club' => $clubs->get((string) $row['club_id'])->canonicalName(),
                    'controlled' => (string) $row['club_id'] === (string) $club['id'],
                ];
            }
        }
        $matches = $matchService->repository($database)->byCompetition($competitionRecord->id(), $seasonId);
        $recentResults = [];
        foreach (array_reverse($matches) as $match) {
            if ($match->status() !== MatchStatus::Completed || $match->scheduledDate()->isAfter($date)) { continue; }
            $recentResults[] = $this->fixtureText($database, $match, (string) $club['id']);
            if (count($recentResults) >= 5) { break; }
        }
        $upcomingMatches = [];
        foreach ($matches as $match) {
            if ($match->status() !== MatchStatus::Scheduled || $match->scheduledDate()->isBefore($date)) { continue; }
            $upcomingMatches[] = $match;
        }
        usort($upcomingMatches, static function (GameMatch $left, GameMatch $right) use ($club): int {
            $leftControlled = $left->homeClubId()->value() === (string) $club['id'] || $left->awayClubId()->value() === (string) $club['id'];
            $rightControlled = $right->homeClubId()->value() === (string) $club['id'] || $right->awayClubId()->value() === (string) $club['id'];
            return (($rightControlled <=> $leftControlled) ?: strcmp($left->scheduledDate()->toIsoString() . $left->id()->value(), $right->scheduledDate()->toIsoString() . $right->id()->value()));
        });
        $upcomingFixtures = array_map(fn (GameMatch $match): string => $this->fixtureText($database, $match, (string) $club['id']), array_slice($upcomingMatches, 0, 5));

        return [
            'competition' => $competitionRecord->name(),
            'standings' => $standings,
            'recent_result' => $recentResults[0] ?? null,
            'recent_results' => array_reverse($recentResults),
            'upcoming_fixtures' => $upcomingFixtures,
            'next_fixture' => ($next = $this->nextMatch($database, $summary)) === null ? null : $this->fixtureTextFromView($next),
            'competitions' => $this->competitionLinks($database, $seasonId, (string) $club['id']),
        ];
    }

    /** @param array<string, mixed> $summary @return array<string, mixed>|null */
    public function decision(array $summary, DatabaseInterface $database): ?array
    {
        $pending = is_array($summary['pending_decisions'] ?? null) ? $summary['pending_decisions'] : [];
        $pending = array_values(array_filter($pending, 'is_array'));
        if ($pending === []) {
            return null;
        }
        $decision = $pending[0];
        $options = is_array($decision['options'] ?? null) ? $decision['options'] : [];
        $context = $this->opportunityContext($database, (string) ($decision['id'] ?? ''));
        $seasonId = isset($context['season_id']) && is_string($context['season_id']) ? new SeasonId($context['season_id']) : null;
        $clubs = $this->services->clubModule()->service()->repository($database);
        $competitions = new CompetitionRepository($database);
        $nations = new NationRepository($database);
        $formatted = [];
        foreach ($options as $option) {
            if (!is_array($option)) { continue; }
            $kind = (string) ($option['kind'] ?? '');
            $clubId = isset($option['club_id']) && is_string($option['club_id']) && $option['club_id'] !== '' ? $option['club_id'] : null;
            $clubView = null;
            if ($clubId !== null) {
                $club = $clubs->get($clubId);
                $competition = $seasonId === null ? null : $this->primaryCompetitionForClub($database, $clubId, $seasonId);
                $nation = $nations->find($club->nationId());
                $clubView = [
                    'name' => $club->canonicalName(),
                    'country' => $nation?->displayName() ?? CareerLabels::nationality($club->nationId()->value()),
                    'competition' => $competition?->name(),
                    'tier' => $competition?->tier(),
                ];
            }
            $label = match ($kind) {
                'stay' => 'Stay at your current Club',
                'accept_transfer' => 'Accept transfer',
                'renew_current_club' => 'Renew with your current Club',
                'sign_with_club' => 'Join Club',
                'enter_free_agency' => 'Enter free agency',
                default => CareerLabels::value($kind, 'Available choice'),
            };
            $formatted[] = [
                'id' => $option['id'] ?? null,
                'label' => $label,
                'club' => $clubView,
                'role' => isset($option['role']) ? CareerLabels::value($option['role']) : null,
            ];
        }
        $currentClub = is_array($summary['current_club'] ?? null) ? ($summary['current_club']['name'] ?? null) : null;
        $currentCompetition = is_array($summary['current_competition'] ?? null) ? $summary['current_competition'] : null;
        $contextCurrentClubId = (string) ($context['current_club_id'] ?? $context['source_club_id'] ?? '');
        if ($currentClub === null && $contextCurrentClubId !== '') {
            $currentClubRecord = $clubs->get($contextCurrentClubId);
            $currentClub = $currentClubRecord->canonicalName();
            if ($seasonId !== null) {
                $currentCompetitionRecord = $this->primaryCompetitionForClub($database, $contextCurrentClubId, $seasonId);
                if ($currentCompetitionRecord !== null) {
                    $currentCompetition = ['name' => $currentCompetitionRecord->name(), 'tier' => $currentCompetitionRecord->tier()];
                }
            }
        }

        return [
            'id' => $decision['id'] ?? null,
            'type' => $decision['type'] ?? null,
            'decision_kind' => $context['decision_kind'] ?? $decision['type'] ?? null,
            'current_club' => $currentClub,
            'current_competition' => $currentCompetition,
            'contract' => $this->contractText($summary['current_contract'] ?? null),
            'options' => $formatted,
        ];
    }

    /** @param array<string, mixed> $summary @return list<array{date:string,headline:string}> */
    public function news(DatabaseInterface $database, array $summary, SimulationDate $date): array
    {
        $player = is_array($summary['player'] ?? null) ? $summary['player'] : [];
        $playerId = (string) ($player['id'] ?? '');
        if ($playerId === '') { return []; }
        $matches = new MatchRepository($database);
        $items = [];
        $club = is_array($summary['current_club'] ?? null) ? $summary['current_club'] : null;
        $currentClubId = is_array($club) ? (string) ($club['id'] ?? '') : '';
        $currentSeasonId = $this->seasonId($summary);
        if ($currentClubId !== '' && $currentSeasonId !== null) {
            foreach (array_reverse($matches->byClub($currentClubId, $currentSeasonId)) as $match) {
                if ($match->status() !== MatchStatus::Completed || $match->scheduledDate()->isAfter($date)) { continue; }
                $result = $match->result();
                if ($result === null) { continue; }
                $competition = (new CompetitionRepository($database))->get($match->competitionId());
                $prefix = $competition->type() === CompetitionType::DomesticCup ? 'DOMESTIC CUP — ' : ($competition->type() === CompetitionType::Continental ? 'EUROPE — ' : ($competition->type() === CompetitionType::International ? 'INTERNATIONAL — ' : 'RESULT — '));
                $items[] = ['date' => $match->scheduledDate()->toIsoString(), 'headline' => $prefix . $this->teamName($database, $match->homeClubId()->value()) . ' ' . $result->homeGoals() . '-' . $result->awayGoals() . ' ' . $this->teamName($database, $match->awayClubId()->value()) . ' (' . $competition->name() . ')'];
                if (count($items) >= 8) { break; }
            }
        }
        $players = new PlayerRepository($database);
        $playerRecord = $players->get($playerId);
        $ratingService = new PlayerMatchRatingService();
        $ratingEvidence = (new PlayerMatchStatRepository($database))->recentCompletedRatingEvidence(new PlayerId($playerId), 12);
        foreach ($ratingEvidence as $evidence) {
            $stat = $evidence['stat'];
            $match = $matches->get($stat->matchId());
            if ($match->scheduledDate()->isAfter($date) || !$stat->appeared()) { continue; }
            $rating = $ratingService->rate($stat, $playerRecord->primaryPosition());
            $ratingText = $rating === null ? '' : ', Rating ' . number_format($rating, 1);
            $items[] = ['date' => $match->scheduledDate()->toIsoString(), 'headline' => 'YOUR PERFORMANCE — ' . ($stat->started() ? 'Started' : 'Appeared') . ' for ' . $this->teamName($database, $stat->clubId()->value()) . $ratingText];
            if (count($items) >= 12) { break; }
        }
        foreach (array_reverse((array) ($summary['movement_history'] ?? [])) as $event) {
            if (!is_array($event)) { continue; }
            $headline = $event['type'] === 'transfer'
                ? 'TRANSFER — ' . (string) ($event['from_club'] ?? 'Previous Club') . ' to ' . (string) ($event['to_club'] ?? 'New Club')
                : CareerLabels::value($event['type'] ?? null) . ' — ' . (string) ($event['club']['name'] ?? 'Club');
            $items[] = ['date' => (string) ($event['date'] ?? ''), 'headline' => $headline];
        }
        foreach (array_reverse((array) ($summary['development_history'] ?? [])) as $entry) {
            if (!is_array($entry)) { continue; }
            $items[] = ['date' => (string) ($entry['date'] ?? $entry['occurred_date'] ?? ''), 'headline' => 'DEVELOPMENT — OVR ' . (string) ($entry['before_ovr'] ?? '?') . ' -> ' . (string) ($entry['after_ovr'] ?? '?')];
        }
        $roleHistory = is_array($summary['role_history'] ?? null) ? $summary['role_history'] : [];
        foreach (array_reverse($roleHistory) as $role) {
            if (!is_array($role)) { continue; }
            $items[] = ['date' => (string) ($role['occurred_date'] ?? ''), 'headline' => 'ROLE — ' . CareerLabels::value($role['role'] ?? null)];
        }
        foreach (array_reverse((array) ($summary['career_life_history'] ?? [])) as $event) {
            if (!is_array($event) || !is_array($event['context'] ?? null) || ($event['context']['newsworthy'] ?? false) !== true) { continue; }
            $consequence = is_array($event['consequence'] ?? null) ? $event['consequence'] : [];
            $history = trim((string) ($consequence['history'] ?? ''));
            if ($history === '') { continue; }
            $items[] = ['date' => (string) ($event['date'] ?? ''), 'headline' => 'CAREER — ' . $history];
        }
        foreach ($this->services->playerModule()->service()->socialService()->history($database, $playerId, 12) as $socialItem) {
            if (!is_array($socialItem)) { continue; }
            $items[] = ['date' => (string) ($socialItem['event_date'] ?? ''), 'headline' => strtoupper((string) ($socialItem['importance'] ?? 'notable')) . ' — ' . (string) ($socialItem['headline'] ?? ''), 'importance' => (string) ($socialItem['importance'] ?? 'notable')];
        }
        $request = is_array($summary['transfer_request'] ?? null) ? $summary['transfer_request'] : [];
        if (($request['status'] ?? null) === 'requested') {
            $items[] = ['date' => $date->toIsoString(), 'headline' => 'TRANSFER REQUEST — Active'];
        }
        $importance = ['routine' => 0, 'notable' => 1, 'major' => 2, 'landmark' => 3];
        usort($items, static fn (array $left, array $right): int => (($importance[(string) ($right['importance'] ?? 'routine')] ?? 0) <=> ($importance[(string) ($left['importance'] ?? 'routine')] ?? 0)) ?: strcmp($right['date'] . $right['headline'], $left['date'] . $left['headline']));
        $unique = [];
        foreach ($items as $item) {
            $key = $item['date'] . '|' . $item['headline'];
            if (isset($unique[$key])) { continue; }
            $unique[$key] = true;
            if (count($unique) >= 20) { break; }
        }

        return array_map(static fn (string $key): array => ['date' => explode('|', $key, 2)[0], 'headline' => explode('|', $key, 2)[1]], array_keys($unique));
    }

    /** @param array<string, mixed> $summary @return array<string, mixed> */
    public function seasonSummary(DatabaseInterface $database, array $summary, ?string $seasonId = null): array
    {
        $seasonId ??= is_string($summary['current_season_id'] ?? null) ? $summary['current_season_id'] : null;
        $history = is_array($summary['season_history'] ?? null) ? $summary['season_history'] : [];
        $row = null;
        foreach ($history as $candidate) {
            if (is_array($candidate) && ($seasonId === null || ($candidate['season_id'] ?? null) === $seasonId)) {
                $row = $candidate;
                break;
            }
        }
        $stats = $seasonId !== null && is_array($summary['season_stats'] ?? null) ? $summary['season_stats'] : [];
        $ovrBefore = null;
        $development = is_array($summary['development_history'] ?? null) ? $summary['development_history'] : [];
        $seasonStart = is_array($row) ? (string) ($row['season_start_date'] ?? '') : '';
        foreach ($development as $entry) {
            if (!is_array($entry) || ($seasonStart !== '' && strcmp((string) ($entry['date'] ?? $entry['occurred_date'] ?? ''), $seasonStart) < 0)) { continue; }
            $ovrBefore = (int) ($entry['before_ovr'] ?? 0);
            break;
        }
        $roleChange = null;
        $roles = [];
        foreach ((array) ($summary['role_history'] ?? []) as $entry) {
            if (!is_array($entry) || ($seasonId !== null && ($entry['season_id'] ?? null) !== $seasonId)) { continue; }
            $role = (string) ($entry['role'] ?? '');
            if ($role !== '' && ($roles === [] || $roles[array_key_last($roles)] !== $role)) { $roles[] = $role; }
        }
        if (count($roles) > 1) {
            $roleChange = CareerLabels::value($roles[0]) . ' -> ' . CareerLabels::value($roles[array_key_last($roles)]);
        }
        $clubContext = $this->clubContext($database, $summary);
        $playerId = is_array($summary['player'] ?? null) ? (string) ($summary['player']['id'] ?? '') : '';
        $competitionStats = $playerId !== '' && $seasonId !== null
            ? $this->seasonCompetitionStats($database, $playerId, new SeasonId($seasonId))
            : [];
        $internationalStats = $playerId !== '' && $seasonId !== null
            ? $this->services->nationalTeams()->playerStats($database, $playerId, new SeasonId($seasonId))
            : [];
        $legacy = is_array($summary['legacy'] ?? null) ? $summary['legacy'] : [];
        $legacyAwards = array_values(array_filter((array) ($legacy['awards'] ?? []), static fn (array $row): bool => ($row['season_id'] ?? null) === $seasonId));
        $legacyHonours = array_values(array_filter((array) ($legacy['honours'] ?? []), static fn (array $row): bool => ($row['season_id'] ?? null) === $seasonId));
        $cupResults = $this->seasonCompetitionOutcomes((array) ($summary['cup_history'] ?? []), $seasonId);
        $europeResults = $this->seasonCompetitionOutcomes((array) ($summary['europe_history'] ?? []), $seasonId);

        return [
            'season' => is_array($row) ? ($row['season'] ?? $seasonId) : $seasonId,
            'club' => is_array($row) ? ($row['club']['name'] ?? null) : (is_array($summary['current_club'] ?? null) ? ($summary['current_club']['name'] ?? null) : null),
            'competition' => is_array($row) ? ($row['competition']['name'] ?? null) : (is_array($summary['current_competition'] ?? null) ? ($summary['current_competition']['name'] ?? null) : null),
            'position' => $clubContext['position'] ?? null,
            'stats' => $stats,
            'performance' => is_array($summary['season_performance'] ?? null) ? ($summary['season_performance']['classification'] ?? null) : null,
            'ovr_before' => $ovrBefore,
            'ovr_after' => $ovrBefore === null ? null : ($summary['current_ovr'] ?? null),
            'role_change' => $roleChange,
            'competition_stats' => $competitionStats,
            'cup_results' => $cupResults,
            'europe_results' => $europeResults,
            'international_stats' => $internationalStats,
            'legacy_awards' => $legacyAwards,
            'legacy_honours' => $legacyHonours,
        ];
    }

    /** @return list<array{competition_id:string,competition:string,type:string,stats:array<string,int|float|null>}> */
    private function seasonCompetitionStats(DatabaseInterface $database, string $playerId, SeasonId $seasonId): array
    {
        $matches = new MatchRepository($database);
        $stats = new PlayerMatchStatRepository($database);
        $competitions = new CompetitionRepository($database);
        $ids = [];
        foreach ($stats->byPlayer(new PlayerId($playerId)) as $stat) {
            if (!$stat->appeared()) { continue; }
            $match = $matches->get($stat->matchId());
            if ($match->seasonId()->value() !== $seasonId->value()) { continue; }
            $competition = $competitions->get($match->competitionId());
            if ($competition->type() === CompetitionType::International) { continue; }
            $ids[$competition->id()->value()] = $competition;
        }
        uasort($ids, static fn (Competition $left, Competition $right): int => strcmp($left->id()->value(), $right->id()->value()));
        $service = new PlayerCareerStatisticsService();
        $result = [];
        foreach ($ids as $competition) {
            $result[] = [
                'competition_id' => $competition->id()->value(),
                'competition' => $competition->name(),
                'type' => $competition->type()->value,
                'stats' => $service->seasonCompetitionDetailed($database, $playerId, $seasonId, $competition->id()->value()),
            ];
        }

        return $result;
    }

    /** @param list<array<string,mixed>> $history @return list<array{competition:string,result:string}> */
    private function seasonCompetitionOutcomes(array $history, ?string $seasonId): array
    {
        if ($seasonId === null) { return []; }
        $result = [];
        foreach ($history as $row) {
            if (!is_array($row) || (string) ($row['season_id'] ?? '') !== $seasonId) { continue; }
            $clubId = (string) ($row['club_id'] ?? '');
            $status = (string) ($row['status'] ?? '');
            $result[] = [
                'competition' => (string) ($row['competition_name'] ?? 'Competition'),
                'result' => $status === 'completed' && (string) ($row['winner_club_id'] ?? '') === $clubId
                    ? 'Won'
                    : ($status === 'completed' && (string) ($row['runner_up_club_id'] ?? '') === $clubId
                        ? 'Runner-up'
                        : ((string) ($row['club_status'] ?? '') === 'eliminated' ? 'Eliminated' : ($status === 'completed' ? 'Completed' : 'In progress'))),
            ];
        }

        return $result;
    }

    /** @param array<string, mixed> $summary @return array<string, mixed> */
    public function rolloverSummary(array $summary, string $previousSeasonId): array
    {
        $currentSeasonId = is_string($summary['current_season_id'] ?? null) ? $summary['current_season_id'] : null;
        $history = is_array($summary['season_history'] ?? null) ? $summary['season_history'] : [];
        $previous = null;
        $current = null;
        foreach ($history as $row) {
            if (!is_array($row)) { continue; }
            if (($row['season_id'] ?? null) === $previousSeasonId) { $previous = $row; }
            if ($currentSeasonId !== null && ($row['season_id'] ?? null) === $currentSeasonId) { $current = $row; }
        }
        $tierOutcome = null;
        if (is_array($previous) && is_array($current) && isset($previous['tier'], $current['tier']) && $previous['tier'] !== null && $current['tier'] !== null && $previous['tier'] !== $current['tier']) {
            $tierOutcome = (int) $current['tier'] < (int) $previous['tier']
                ? 'PROMOTED — ' . (string) ($current['club']['name'] ?? 'Your Club') . ' will play in ' . (string) ($current['competition']['name'] ?? 'a higher tier') . ' this Season.'
                : 'RELEGATED — ' . (string) ($current['club']['name'] ?? 'Your Club') . ' will play in ' . (string) ($current['competition']['name'] ?? 'a lower tier') . ' this Season.';
        }
        $roleChange = null;
        if (is_array($previous) && is_array($current) && ($previous['role'] ?? null) !== ($current['role'] ?? null)) {
            $roleChange = CareerLabels::value($previous['role'] ?? null) . ' -> ' . CareerLabels::value($current['role'] ?? null);
        }

        return [
            'season' => is_array($current) ? ($current['season'] ?? $currentSeasonId) : $currentSeasonId,
            'club' => is_array($current) ? ($current['club']['name'] ?? null) : (is_array($summary['current_club'] ?? null) ? ($summary['current_club']['name'] ?? null) : null),
            'competition' => is_array($current) ? ($current['competition']['name'] ?? null) : (is_array($summary['current_competition'] ?? null) ? ($summary['current_competition']['name'] ?? null) : null),
            'tier_outcome' => $tierOutcome,
            'role_change' => $roleChange,
            'ovr' => $summary['current_ovr'] ?? null,
            'awards' => array_values(array_filter((array) (($summary['legacy'] ?? [])['awards'] ?? []), static fn (array $row): bool => ($row['season_id'] ?? null) === $previousSeasonId)),
            'honours' => array_values(array_filter((array) (($summary['legacy'] ?? [])['honours'] ?? []), static fn (array $row): bool => ($row['season_id'] ?? null) === $previousSeasonId)),
        ];
    }

    private function legacyService(): CareerLegacyService
    {
        return new CareerLegacyService(
            $this->services->clubModule()->service(),
            $this->services->nationalTeams(),
            $this->services->internationalCompetitions(),
            $this->services->playerModule()->service()->socialService(),
        );
    }

    /** @return array<string, mixed> */
    private function opportunityContext(DatabaseInterface $database, string $id): array
    {
        if ($id === '') { return []; }
        $opportunity = (new CareerOpportunityRepository($database))->get($id);

        return $opportunity?->context() ?? [];
    }

    private function contractText(mixed $contract): string
    {
        if (!is_array($contract)) { return ''; }
        $status = CareerLabels::value($contract['status'] ?? null);
        $end = trim((string) ($contract['end_date'] ?? ''));

        return $end === '' ? $status : $status . ' through ' . $end;
    }

    /** @return object|null */
    private function playerSelection(DatabaseInterface $database, GameMatch $match, string $playerId): ?object
    {
        foreach ((new MatchSelectionRepository($database))->byMatch($match->id()) as $selection) {
            if ($selection->playerId()->value() === $playerId) { return $selection; }
        }

        return null;
    }

    private function substitutionMinute(DatabaseInterface $database, GameMatch $match, string $playerId): ?int
    {
        foreach ((new MatchSubstitutionRepository($database))->byMatch($match->id()) as $substitution) {
            if ($substitution->incomingPlayerId()->value() === $playerId) { return $substitution->minute(); }
        }

        return null;
    }

    /** @return list<string> */
    private function highlightLines(DatabaseInterface $database, GameMatch $match, string $playerId): array
    {
        $players = new PlayerRepository($database);
        $lines = [];
        foreach ($this->services->matchModule()->service()->highlightRepository($database)->byMatch($match->id()) as $highlight) {
            $type = match ($highlight->type()) {
                'goal' => 'GOAL',
                'yellow_card' => 'YELLOW CARD',
                'red_card' => 'RED CARD',
                'substitution' => 'SUBSTITUTION',
                default => CareerLabels::value($highlight->type()),
            };
            $clubName = $highlight->clubId() === null ? null : $this->teamName($database, $highlight->clubId()->value());
            $playerName = $highlight->playerId() === null ? null : $players->get($highlight->playerId())->preferredName();
            $controlled = $highlight->playerId()?->value() === $playerId;
            $data = $highlight->data();
            if ($highlight->type() === 'substitution') {
                $incomingId = (string) ($data['incoming_player_id'] ?? $highlight->playerId()?->value() ?? '');
                $outgoingId = (string) ($data['outgoing_player_id'] ?? '');
                $incoming = $incomingId === '' ? 'Player' : $players->get($incomingId)->preferredName();
                $outgoing = $outgoingId === '' ? 'Player' : $players->get($outgoingId)->preferredName();
                if ($incomingId === $playerId) { $lines[] = $highlight->minute() . "' YOU ENTER THE MATCH — " . $incoming . ' on for ' . $outgoing; }
                elseif ($outgoingId === $playerId) { $lines[] = $highlight->minute() . "' YOU LEAVE THE MATCH — " . $incoming . ' on for ' . $outgoing; }
                else { $lines[] = $highlight->minute() . "' SUBSTITUTION — " . $incoming . ' on for ' . $outgoing . ' (' . ($clubName ?? 'Club') . ')'; }
                continue;
            }
            $actor = $playerName === null ? ($clubName ?? 'Club') : (($controlled ? 'YOU — ' : '') . $playerName . ' (' . ($clubName ?? 'Club') . ')');
            $assistId = $data['assist_player_id'] ?? null;
            $assist = is_string($assistId) && $assistId !== '' ? $players->get($assistId)->preferredName() : null;
            $assistText = $assistId === $playerId ? 'YOU' : $assist;
            $suffix = $assistText === null ? '' : ' — assist: ' . $assistText;
            $lines[] = $highlight->minute() . "' " . $type . ' — ' . $actor . $suffix;
        }

        return $lines;
    }

    private function fixtureText(DatabaseInterface $database, GameMatch $match, string $controlledClubId): string
    {
        $competition = (new CompetitionRepository($database))->get($match->competitionId());
        $home = $this->teamName($database, $match->homeClubId()->value());
        $away = $this->teamName($database, $match->awayClubId()->value());
        $result = $match->result();
        $fixture = $result === null
            ? $home . ' vs ' . $away
            : $home . ' ' . $result->homeGoals() . '-' . $result->awayGoals() . ' ' . $away;

        $mark = $match->homeClubId()->value() === $controlledClubId || $match->awayClubId()->value() === $controlledClubId ? '* ' : '';
        $round = '';
        if ($competition->type() === CompetitionType::DomesticCup) {
            $resolution = (new DomesticCupService($this->services->clubModule()->service()))->matchResolution($database, $match->id()->value());
            $round = is_array($resolution) ? ' · ' . (string) ($resolution['stage'] ?? 'Knockout round') : '';
        } elseif ($competition->type() === CompetitionType::Continental) {
            $resolution = (new EuropeanCompetitionService($this->services->clubModule()->service(), new DomesticCupService($this->services->clubModule()->service())))->matchResolution($database, $match->id()->value());
            $round = is_array($resolution) ? ' · ' . (string) ($resolution['stage'] ?? 'European round') : '';
        } elseif ($competition->type() === CompetitionType::International) {
            $resolution = $this->services->internationalCompetitions()->matchResolution($database, $match->id()->value());
            $round = is_array($resolution) ? ' · ' . (string) ($resolution['stage'] ?? 'International round') : '';
        }

        return $mark . $match->scheduledDate()->toIsoString() . ': ' . $fixture . ' (' . $competition->name() . $round . ')';
    }

    private function teamName(DatabaseInterface $database, string $teamId): string
    {
        if ($this->services->nationalTeams()->isNationalTeam($teamId)) {
            return $this->services->nationalTeams()->displayName($database, $teamId);
        }

        return $this->services->clubModule()->service()->repository($database)->get($teamId)->canonicalName();
    }

    /** @param array<string, mixed> $view */
    private function fixtureTextFromView(array $view): string
    {
        $round = ($view['round'] ?? null) === null ? '' : ' · ' . (string) $view['round'];
        return (string) ($view['date'] ?? 'Date unavailable') . ': ' . (string) ($view['home_club'] ?? 'Home') . ' vs ' . (string) ($view['away_club'] ?? 'Away') . ' (' . (string) ($view['competition'] ?? 'Competition') . $round . ')';
    }

    private function seasonId(array $summary): ?\Goal\Legacy\Modules\World\Domain\SeasonId
    {
        $current = $summary['current_season_id'] ?? null;
        if (is_string($current) && $current !== '') {
            return new \Goal\Legacy\Modules\World\Domain\SeasonId($current);
        }
        $history = is_array($summary['season_history'] ?? null) ? $summary['season_history'] : [];
        $current = $history === [] ? null : $history[array_key_last($history)];
        $id = is_array($current) ? ($current['season_id'] ?? null) : null;
        if (!is_string($id) || $id === '') {
            return null;
        }

        return new \Goal\Legacy\Modules\World\Domain\SeasonId($id);
    }

    private function primaryCompetitionForClub(DatabaseInterface $database, string $clubId, SeasonId $seasonId): ?\Goal\Legacy\Modules\Competition\Domain\Competition
    {
        $competitions = new CompetitionRepository($database);
        $memberships = array_values(array_filter((new ClubMembershipRepository($database))->byClub($clubId), static fn ($membership): bool => $membership->seasonId()->value() === $seasonId->value()));
        usort($memberships, function ($left, $right) use ($competitions): int {
            $leftCompetition = $competitions->get($left->competitionId());
            $rightCompetition = $competitions->get($right->competitionId());
            return (($leftCompetition->type() === CompetitionType::DomesticLeague ? 0 : 1) <=> ($rightCompetition->type() === CompetitionType::DomesticLeague ? 0 : 1)) ?: strcmp($leftCompetition->id()->value(), $rightCompetition->id()->value());
        });

        return $memberships === [] ? null : $competitions->get($memberships[0]->competitionId());
    }

    /** @return list<array{id:string,name:string,type:string,controlled:bool}> */
    private function competitionLinks(DatabaseInterface $database, SeasonId $seasonId, string $controlledClubId): array
    {
        $competitions = new CompetitionRepository($database);
        $memberships = new ClubMembershipRepository($database);
        $links = [];
        foreach ($competitions->bySeason($seasonId) as $competition) {
            $controlled = false;
            foreach ($memberships->byCompetition($competition->id(), $seasonId) as $membership) {
                if ($membership->clubId()->value() === $controlledClubId) { $controlled = true; break; }
            }
            $links[] = ['id' => $competition->id()->value(), 'name' => $competition->name(), 'type' => $competition->type()->value, 'controlled' => $controlled];
        }

        return $links;
    }
}
