<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Presentation;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
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
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\Club\Persistence\ClubMembershipRepository;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\SeasonId;

/** Assembles presentation input from canonical services and persisted facts. */
final class CareerPresentationService
{
    public function __construct(private readonly CoreServices $services)
    {
    }

    /** @return array{world:object,summary:array<string,mixed>,date:SimulationDate} */
    public function snapshot(DatabaseInterface $database, string $saveId): array
    {
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->load($database, $saveId);
        $date = $world->currentDate($worldService->calendar());
        $career = (new CareerPlayerRepository($database))->get($saveId);
        $summary = (new PlayerCareerProgressionQuery($this->services->clubModule()->service()))->summary(
            $database,
            $career->playerId(),
            $date,
            $world->currentSeasonId(),
        );

        return ['world' => $world, 'summary' => $summary, 'date' => $date];
    }

    /** @param array<string, mixed> $summary @return array<string, mixed>|null */
    public function nextMatch(DatabaseInterface $database, array $summary): ?array
    {
        $next = $summary['next_scheduled_match'] ?? null;
        if (!is_array($next) || !isset($next['match_id'])) {
            return null;
        }
        $match = $this->services->matchModule()->service()->repository($database)->get((string) $next['match_id']);
        $clubs = $this->services->clubModule()->service()->repository($database);
        $competition = (new CompetitionRepository($database))->get($match->competitionId());

        return [
            'match_id' => $match->id()->value(),
            'date' => $match->scheduledDate()->toIsoString(),
            'home_club' => $clubs->get($match->homeClubId())->canonicalName(),
            'away_club' => $clubs->get($match->awayClubId())->canonicalName(),
            'competition' => $competition->name(),
            'competition_type' => $competition->type()->value,
            'competition_id' => $competition->id()->value(),
            'season_id' => $match->seasonId()->value(),
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
        $clubs = $this->services->clubModule()->service()->repository($database);
        $players = new PlayerRepository($database);
        $competition = (new CompetitionRepository($database))->get($match->competitionId());
        $homeClub = $clubs->get($match->homeClubId());
        $awayClub = $clubs->get($match->awayClubId());
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
        $postDate = $match->scheduledDate();
        $postSummary = (new PlayerCareerProgressionQuery($this->services->clubModule()->service()))->summary(
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
            'home_club' => $homeClub->canonicalName(),
            'away_club' => $awayClub->canonicalName(),
            'controlled_club_id' => $controlledClubId,
            'controlled_club' => $controlledHome ? $homeClub->canonicalName() : $awayClub->canonicalName(),
            'result' => ['home_goals' => $homeGoals, 'away_goals' => $awayGoals],
            'perspective_result' => $perspectiveResult,
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
        $membershipRepository = new ClubMembershipRepository($database);
        $formatted = [];
        foreach ($options as $option) {
            if (!is_array($option)) { continue; }
            $kind = (string) ($option['kind'] ?? '');
            $clubId = isset($option['club_id']) && is_string($option['club_id']) && $option['club_id'] !== '' ? $option['club_id'] : null;
            $clubView = null;
            if ($clubId !== null) {
                $club = $clubs->get($clubId);
                $competition = null;
                if ($seasonId !== null) {
                    foreach ($membershipRepository->bySeason($seasonId) as $membership) {
                        if ($membership->clubId()->value() === $clubId) {
                            $competition = $competitions->get($membership->competitionId());
                            break;
                        }
                    }
                }
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
                foreach ($membershipRepository->bySeason($seasonId) as $membership) {
                    if ($membership->clubId()->value() !== $contextCurrentClubId) { continue; }
                    $currentCompetitionRecord = $competitions->get($membership->competitionId());
                    $currentCompetition = ['name' => $currentCompetitionRecord->name(), 'tier' => $currentCompetitionRecord->tier()];
                    break;
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
        $clubs = $this->services->clubModule()->service()->repository($database);
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
                $items[] = ['date' => $match->scheduledDate()->toIsoString(), 'headline' => 'RESULT — ' . $clubs->get($match->homeClubId())->canonicalName() . ' ' . $result->homeGoals() . '-' . $result->awayGoals() . ' ' . $clubs->get($match->awayClubId())->canonicalName()];
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
            $items[] = ['date' => $match->scheduledDate()->toIsoString(), 'headline' => 'YOUR PERFORMANCE — ' . ($stat->started() ? 'Started' : 'Appeared') . ' for ' . $clubs->get($stat->clubId())->canonicalName() . $ratingText];
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
        $request = is_array($summary['transfer_request'] ?? null) ? $summary['transfer_request'] : [];
        if (($request['status'] ?? null) === 'requested') {
            $items[] = ['date' => $date->toIsoString(), 'headline' => 'TRANSFER REQUEST — Active'];
        }
        usort($items, static fn (array $left, array $right): int => strcmp($right['date'] . $right['headline'], $left['date'] . $left['headline']));
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
        ];
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
        ];
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
        $clubs = $this->services->clubModule()->service()->repository($database);
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
            $clubName = $highlight->clubId() === null ? null : $clubs->get($highlight->clubId())->canonicalName();
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
        $clubs = $this->services->clubModule()->service()->repository($database);
        $competition = (new CompetitionRepository($database))->get($match->competitionId());
        $home = $clubs->get($match->homeClubId())->canonicalName();
        $away = $clubs->get($match->awayClubId())->canonicalName();
        $result = $match->result();
        $score = $result === null ? '' : ' ' . $result->homeGoals() . '-' . $result->awayGoals();

        $mark = $match->homeClubId()->value() === $controlledClubId || $match->awayClubId()->value() === $controlledClubId ? '* ' : '';

        return $mark . $match->scheduledDate()->toIsoString() . ': ' . $home . $score . ' ' . $away . ' (' . $competition->name() . ')';
    }

    /** @param array<string, mixed> $view */
    private function fixtureTextFromView(array $view): string
    {
        return (string) ($view['date'] ?? 'Date unavailable') . ': ' . (string) ($view['home_club'] ?? 'Home') . ' vs ' . (string) ($view['away_club'] ?? 'Away') . ' (' . (string) ($view['competition'] ?? 'Competition') . ')';
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
}
