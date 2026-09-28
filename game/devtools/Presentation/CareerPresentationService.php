<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Presentation;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Domain\Club;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
use Goal\Legacy\Modules\Competition\DomesticCupService;
use Goal\Legacy\Modules\Competition\EuropeanCompetitionService;
use Goal\Legacy\Modules\Competition\Domain\Competition;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
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
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\CareerRecoveryService;
use Goal\Legacy\Modules\Player\PlayerDisciplineService;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\Player\CareerLegacyService;
use Goal\Legacy\Modules\Player\PlayerCareerStatisticsService;
use Goal\Legacy\Modules\Player\PlayerSeasonPerformanceService;
use Goal\Legacy\Modules\Player\PlayerTraitService;
use Goal\Legacy\Modules\Player\PlayerFormService;
use Goal\Legacy\Modules\Player\CompetitionStatisticsQuery;
use Goal\Legacy\Modules\Player\PositionDevelopmentService;
use Goal\Legacy\Modules\Player\OnPitchRoleService;
use Goal\Legacy\Modules\Club\ClubCaptaincyService;
use Goal\Legacy\Modules\Club\ClubFixtureContextService;
use Goal\Legacy\Modules\Club\SetPieceResponsibilityService;
use Goal\Legacy\Modules\Club\Persistence\ClubMembershipRepository;
use Goal\Legacy\Modules\Club\Persistence\ClubSquadRepository;
use Goal\Legacy\Modules\Club\ClubSeasonObjectiveService;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Persistence\SeasonRepository;
use WeakMap;

/** Assembles presentation input from canonical services and persisted facts. */
final class CareerPresentationService
{
    /** @var WeakMap<object, array<string, Club>> */
    private WeakMap $clubReadCache;

    /** @var WeakMap<object, array<string, Competition>> */
    private WeakMap $competitionReadCache;

    public function __construct(private readonly CoreServices $services)
    {
        $this->clubReadCache = new WeakMap();
        $this->competitionReadCache = new WeakMap();
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
        $summary['decision_history'] = $this->decisionHistory($database, $career->playerId()->value());
        $summary['injury_recovery'] = $this->recoveryService()->context($database, $career->playerId(), $date);
        $summary['discipline'] = (new PlayerDisciplineService())->context($database, $career->playerId());
        $summary['career_outlook']['recovery_context'] = ($summary['injury_recovery']['visible'] ?? false) === true
            ? ['phase' => $summary['injury_recovery']['phase'] ?? null, 'message' => $summary['injury_recovery']['message'] ?? null]
            : null;
        $summary['career_outlook']['discipline_context'] = ($summary['discipline']['active'] ?? false) === true
            ? ['message' => $summary['discipline']['message'] ?? null]
            : null;
        $summary['next_career_milestone'] = (new CareerLegacyService(
            $this->services->clubModule()->service(),
            $this->services->nationalTeams(),
            $this->services->internationalCompetitions(),
            $this->services->playerModule()->service()->socialService(),
        ))->nextMilestone((array) ($summary['career_stats'] ?? []), (array) (($summary['international']['stats'] ?? [])));
        $summary['next_fixture_context'] = ($next = $this->nextMatch($database, $summary)) === null ? null : ($next['fixture_context'] ?? null);
        if ($includeLegacy) {
            $summary['legacy'] = $this->legacyService()->summary($database, $career->playerId()->value());
            $summary['legacy']['club_journey'] = $summary['career_context']['club_journey'] ?? [];
            $summary['legacy']['breakthrough_club'] = $summary['career_context']['breakthrough_club'] ?? null;
            $summary['legacy']['longest_club_spell'] = $summary['career_context']['longest_club_spell'] ?? null;
            $summary['legacy']['returns'] = $summary['career_context']['returns'] ?? [];
            $summary['legacy']['one_club_career'] = $summary['career_context']['one_club_career'] ?? false;
            $summary['legacy']['injury_comebacks'] = $summary['injury_recovery']['episodes'] ?? [];
            $summary['legacy'] = $this->appendMovementLandmarks($summary['legacy'], (array) ($summary['movement_history'] ?? []));
        }
        $summary['market'] = $this->services->transferModule()->service()->careerMovement()->marketContext($database, $career->playerId(), $world->currentSeasonId(), $date);
        $pulse = $this->services->playerModule()->service()->pulseService();
        $summary['pulse'] = $pulse->context($database, $career->playerId());
        $summary['pulse_feed'] = $pulse->feed($database, $career->playerId(), 3);
        $summary['pulse_response'] = $pulse->pendingResponse($database, $career->playerId());
        $currentClubId = is_array($summary['current_club'] ?? null) ? (string) ($summary['current_club']['id'] ?? '') : '';
        $summary['captaincy'] = (new ClubCaptaincyService($this->services->clubModule()->service()))->contextForPlayer(
            $database,
            $career->playerId()->value(),
            $currentClubId === '' ? null : $currentClubId,
            $world->currentSeasonId(),
            $date,
        );
        $summary['set_piece_responsibility'] = (new SetPieceResponsibilityService($this->services->clubModule()->service()))->contextForPlayer(
            $database,
            $career->playerId()->value(),
            $currentClubId === '' ? null : $currentClubId,
            $world->currentSeasonId(),
            $date,
        );
        $summary['cup_history'] = $currentClubId === '' ? [] : (new DomesticCupService($this->services->clubModule()->service()))->historyForClub($database, $currentClubId);
        $summary['europe_history'] = $currentClubId === '' ? [] : (new EuropeanCompetitionService($this->services->clubModule()->service(), new DomesticCupService($this->services->clubModule()->service())))->historyForClub($database, $currentClubId);

        return ['world' => $world, 'summary' => $summary, 'date' => $date];
    }

    /**
     * Project the small set of facts that Career Home needs to orient the
     * player. Decisions remain owned by their domain services; this method
     * only orders and explains already persisted/read-model facts.
     *
     * @param array<string, mixed> $summary
     * @param array<string, mixed>|null $nextMatch
     * @return array<string, mixed>
     */
    public function careerHome(array $summary, SimulationDate $date, ?array $nextMatch = null, ?array $recentMatch = null): array
    {
        $model = $this->homeContext($summary, $nextMatch, $date);
        $model['recent_match'] = $recentMatch;
        $model['recent_story'] = $this->homeRecentStory($summary);

        return $model;
    }

    /** @param array<string, mixed> $summary @return list<array{date:string,headline:string}> */
    private function homeRecentStory(array $summary): array
    {
        $items = [];
        foreach ((array) ($summary['career_life_history'] ?? []) as $event) {
            if (!is_array($event)) { continue; }
            $context = is_array($event['context'] ?? null) ? $event['context'] : [];
            if (($context['historyworthy'] ?? true) !== true) { continue; }
            $consequence = is_array($event['consequence'] ?? null) ? $event['consequence'] : [];
            $headline = trim((string) ($consequence['history'] ?? $event['title'] ?? ''));
            if ($headline === '') { continue; }
            $items[] = ['date' => (string) ($event['date'] ?? ''), 'headline' => 'CAREER — ' . $headline];
        }
        foreach ((array) ($summary['movement_history'] ?? []) as $movement) {
            if (!is_array($movement)) { continue; }
            $type = (string) ($movement['type'] ?? 'movement');
            $from = (string) ($movement['from_club'] ?? 'Previous Club');
            $to = (string) ($movement['to_club'] ?? ($movement['club']['name'] ?? 'Club'));
            $headline = $type === 'transfer'
                ? 'TRANSFER — ' . $from . ' to ' . $to
                : CareerLabels::value($type, 'Club movement') . ' — ' . $to;
            $items[] = ['date' => (string) ($movement['date'] ?? ''), 'headline' => $headline];
        }
        foreach ((array) ($summary['recent_development'] ?? []) as $development) {
            if (!is_array($development)) { continue; }
            $items[] = ['date' => (string) ($development['date'] ?? $development['occurred_date'] ?? ''), 'headline' => 'DEVELOPMENT — OVR ' . (string) ($development['before_ovr'] ?? '?') . ' → ' . (string) ($development['after_ovr'] ?? '?')];
        }
        foreach ((array) ($summary['role_history'] ?? []) as $role) {
            if (!is_array($role)) { continue; }
            $items[] = ['date' => (string) ($role['occurred_date'] ?? ''), 'headline' => 'ROLE — ' . CareerLabels::value($role['role'] ?? null, 'Role changed')];
        }
        foreach ((array) ($summary['decision_history'] ?? []) as $decision) {
            if (!is_array($decision) || trim((string) ($decision['story'] ?? '')) === '') { continue; }
            $items[] = ['date' => (string) ($decision['date'] ?? ''), 'headline' => 'DECISION — ' . (string) $decision['story']];
        }
        foreach ((array) ($summary['social_history'] ?? []) as $social) {
            if (!is_array($social) || trim((string) ($social['headline'] ?? '')) === '') { continue; }
            $items[] = ['date' => (string) ($social['event_date'] ?? ''), 'headline' => strtoupper((string) ($social['importance'] ?? 'notable')) . ' — ' . (string) $social['headline']];
        }
        usort($items, static fn (array $left, array $right): int => strcmp((string) $right['date'] . (string) $right['headline'], (string) $left['date'] . (string) $left['headline']));
        $unique = [];
        foreach ($items as $item) {
            $key = $item['date'] . '|' . $item['headline'];
            if (isset($unique[$key])) { continue; }
            $unique[$key] = $item;
            if (count($unique) >= 5) { break; }
        }

        return array_values($unique);
    }

    /**
     * Pure presentation projection used by the web page and low-cost tests.
     * It deliberately has no repository access, writes, or hidden state.
     *
     * @param array<string, mixed> $summary
     * @param array<string, mixed>|null $nextMatch
     * @return array<string, mixed>
     */
    public function homeContext(array $summary, ?array $nextMatch = null, ?SimulationDate $date = null): array
    {
        $player = is_array($summary['player'] ?? null) ? $summary['player'] : [];
        $club = is_array($summary['current_club'] ?? null) ? $summary['current_club'] : null;
        $competition = is_array($summary['current_competition'] ?? null) ? $summary['current_competition'] : null;
        $contract = is_array($summary['current_contract'] ?? null) ? $summary['current_contract'] : null;
        $loan = is_array($summary['active_loan'] ?? null) ? $summary['active_loan'] : null;
        $discipline = is_array($summary['discipline'] ?? null) ? $summary['discipline'] : [];
        $recovery = is_array($summary['injury_recovery'] ?? null) ? $summary['injury_recovery'] : [];
        $readiness = is_array($summary['readiness'] ?? null) ? $summary['readiness'] : [];
        $outlook = is_array($summary['career_outlook'] ?? null) ? $summary['career_outlook'] : [];
        $season = is_array($summary['season_stats'] ?? null) ? $summary['season_stats'] : [];
        $form = is_array($summary['recent_form'] ?? null) ? $summary['recent_form'] : [];
        $manager = is_array($summary['manager_context'] ?? null) ? $summary['manager_context'] : [];
        $positionCompetition = is_array($summary['position_competition'] ?? null) ? $summary['position_competition'] : [];
        $state = (string) ($summary['career_state'] ?? 'active');
        $retired = $state === 'retired';
        $transfer = is_array($summary['transfer_request'] ?? null) ? $summary['transfer_request'] : [];

        $status = $this->homeAvailability($summary, $discipline, $recovery, $readiness, $retired);
        $playing = $this->homePlayingStatus($summary, $season, $manager, $positionCompetition, $status);
        $attention = $this->homeAttention($summary, $outlook, $status, $date);
        $primary = $this->homePrimaryAction($summary, $attention, $status, $nextMatch);
        $contractProjection = $this->homeContract($contract, $date);
        $movement = [
            'transfer_request' => (string) ($transfer['status'] ?? 'none'),
            'active_loan' => $loan !== null,
            'current_club' => $club['name'] ?? null,
            'parent_club' => $loan['parent_club']['name'] ?? ($summary['parent_club']['name'] ?? null),
            'loan_club' => $loan['loan_club']['name'] ?? null,
        ];

        return [
            'header' => [
                'name' => $player['preferred_name'] ?? 'Player',
                'age' => $summary['age'] ?? null,
                'position' => $player['primary_position'] ?? null,
                'club' => $club['name'] ?? null,
                'competition' => $competition['name'] ?? null,
                'ovr' => $summary['current_ovr'] ?? null,
                'role' => $summary['current_role'] ?? $summary['squad_role'] ?? null,
                'career_phase' => $summary['career_phase'] ?? null,
                'career_state' => $state,
                'free_agent' => $club === null || $contract === null,
                'loaned' => $loan !== null,
            ],
            'next_up' => [
                'action' => $primary,
                'fixture' => $nextMatch,
            ],
            'recent_match' => null,
            'current_status' => $status,
            'playing_status' => $playing,
            'season_snapshot' => $season,
            'form' => $form,
            'development' => [
                'ovr' => $summary['current_ovr'] ?? null,
                'focus' => $summary['training_focus'] ?? null,
                'recent' => array_slice((array) ($summary['recent_development'] ?? []), 0, 2),
            ],
            'outlook' => $outlook,
            'needs_attention' => $attention,
            'contract' => $contractProjection,
            'movement' => $movement,
            'loan' => $loan,
            'decision_hub' => $this->careerDecisionHub($summary, $date),
            'quick_links' => $this->homeQuickLinks($summary, $retired),
        ];
    }

    /**
     * Assemble the player-facing contract and movement hub from the existing
     * Career summary. This is deliberately a read-only projection: domain
     * eligibility, outlook classification, and decision semantics stay with
     * their canonical services.
     *
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    public function careerDecisionHub(array $summary, ?SimulationDate $date = null): array
    {
        $club = is_array($summary['current_club'] ?? null) ? $summary['current_club'] : null;
        $contract = is_array($summary['current_contract'] ?? null) ? $summary['current_contract'] : null;
        $loan = is_array($summary['active_loan'] ?? null) ? $summary['active_loan'] : null;
        $outlook = is_array($summary['career_outlook'] ?? null) ? $summary['career_outlook'] : [];
        $request = is_array($summary['transfer_request'] ?? null) ? $summary['transfer_request'] : [];
        $pending = array_values(array_filter((array) ($summary['pending_decisions'] ?? []), 'is_array'));
        $availableActions = array_values(array_filter((array) ($summary['available_actions'] ?? []), 'is_array'));
        $contractProjection = $this->homeContract($contract, $date);
        $contractStatus = (string) ($contract['status'] ?? '');
        $hasContractDecision = false;
        foreach ($pending as $decision) {
            if (($decision['type'] ?? null) === 'contract_renewal') {
                $hasContractDecision = true;
                break;
            }
        }
        $contractOutlook = (string) ($outlook['contract_outlook'] ?? '');
        $contractCode = 'active';
        $contractLabel = 'Under contract';
        $contractExplanation = 'Your current Club and Contract are active.';
        if ($contract === null || $club === null) {
            $contractCode = 'free_agent';
            $contractLabel = 'Free agent';
            $contractExplanation = 'No active Club and Contract are recorded for the current Career state.';
        } elseif ($contractStatus !== '' && $contractStatus !== 'active') {
            $contractCode = $contractStatus;
            $contractLabel = CareerLabels::value($contractStatus, 'Contract status');
            $contractExplanation = 'Your Contract is not currently marked active.';
        } elseif ($hasContractDecision || $contractOutlook === 'pending_decision') {
            $contractCode = 'renewal_available';
            $contractLabel = 'Renewal decision available';
            $contractExplanation = 'A Contract decision is waiting for your response.';
        } elseif ($contractOutlook === 'approaching_decision') {
            $contractCode = 'expiring';
            $contractLabel = 'Contract expiring';
            $contractExplanation = 'Your Contract is approaching its recorded end date.';
        }

        $requestStatus = (string) ($request['status'] ?? 'none');
        $canRequest = false;
        $canWithdraw = false;
        foreach ($availableActions as $action) {
            if (($action['type'] ?? null) === 'request_transfer') { $canRequest = true; }
            if (($action['type'] ?? null) === 'withdraw_transfer_request') { $canWithdraw = true; }
        }
        $requestActive = $requestStatus === 'requested';
        $requestProjection = [
            'status' => $requestStatus === '' ? 'none' : $requestStatus,
            'active' => $requestActive,
            'season_id' => $request['season_id'] ?? null,
            'label' => $requestActive ? 'Transfer request active' : 'No active transfer request',
            'explanation' => $requestActive
                ? 'Your request is active, but it does not guarantee an offer.'
                : ($canRequest ? 'A transfer request is available from your current Career state.' : 'No transfer request can be made from the current Career state.'),
            'can_request' => $canRequest,
            'can_withdraw' => $canWithdraw,
            'action' => $canWithdraw ? 'withdraw_transfer' : ($canRequest ? 'request_transfer' : null),
        ];

        $openById = [];
        foreach ((array) ($summary['open_opportunities'] ?? []) as $opportunity) {
            if (is_array($opportunity) && isset($opportunity['id'])) {
                $openById[(string) $opportunity['id']] = $opportunity;
            }
        }
        $required = [];
        foreach ($pending as $decision) {
            $type = (string) ($decision['type'] ?? 'career_decision');
            $opportunity = $openById[(string) ($decision['id'] ?? '')] ?? [];
            $context = is_array($opportunity['context'] ?? null) ? $opportunity['context'] : [];
            $kind = (string) ($context['decision_kind'] ?? $type);
            $label = match ($type) {
                'contract_renewal' => 'Contract decision',
                'transfer_interest' => 'Transfer opportunity',
                'loan' => 'Loan decision',
                'retirement' => 'Retirement decision',
                default => CareerLabels::value($kind, 'Career decision'),
            };
            $options = array_values(array_filter((array) ($decision['options'] ?? $context['options'] ?? []), 'is_array'));
            $destination = null;
            foreach ($options as $option) {
                $destination = $option['target_club_name'] ?? $option['club']['name'] ?? null;
                if (is_string($destination) && trim($destination) !== '') { break; }
            }
            $required[] = [
                'id' => $decision['id'] ?? null,
                'type' => $type,
                'kind' => $kind,
                'label' => $label,
                'status' => $decision['status'] ?? 'open',
                'created_date' => $decision['created_date'] ?? null,
                'expiry_date' => $decision['expiry_date'] ?? null,
                'options_count' => count($options),
                'destination' => $destination,
                'action_label' => 'Review ' . strtolower($label),
            ];
        }

        $guidance = array_values(array_filter((array) ($outlook['guidance'] ?? []), 'is_array'));
        $firstGuidance = $guidance[0] ?? [];
        $outlookLabel = trim((string) ($outlook['label'] ?? ''));
        $outlookExplanation = trim((string) ($firstGuidance['message'] ?? ''));
        if ($outlookLabel === '') {
            $outlookLabel = CareerLabels::value($outlook['category'] ?? null, 'Not available');
        }
        if ($outlookExplanation === '') {
            $outlookExplanation = 'Your outlook follows the latest recorded Career evidence.';
        }
        $evidence = [];
        $outlookEvidence = is_array($outlook['evidence'] ?? null) ? $outlook['evidence'] : [];
        foreach ([
            'role' => 'Squad role',
            'performance' => 'Recent performance',
            'manager_trust' => 'Manager trust',
            'playing_time_status' => 'Playing time',
        ] as $key => $label) {
            $value = $outlookEvidence[$key] ?? null;
            if ($value === null || $value === '') { continue; }
            $evidence[] = ['label' => $label, 'value' => CareerLabels::value($value, (string) $value)];
        }

        $recent = [];
        foreach ((array) ($summary['movement_history'] ?? []) as $movement) {
            if (!is_array($movement)) { continue; }
            $type = (string) ($movement['type'] ?? 'movement');
            $from = trim((string) ($movement['from_club'] ?? ''));
            $to = trim((string) ($movement['to_club'] ?? ($movement['club']['name'] ?? '')));
            $headline = match ($type) {
                'transfer' => trim(($from === '' ? 'Club' : $from) . ' → ' . ($to === '' ? 'Club' : $to)),
                'loan' => trim(($from === '' ? 'Parent Club' : $from) . ' → ' . ($to === '' ? 'Loan Club' : $to)),
                'loan_return' => trim(($from === '' ? 'Loan Club' : $from) . ' → ' . ($to === '' ? 'Parent Club' : $to)),
                default => CareerLabels::value($type, 'Club movement'),
            };
            $recent[] = [
                'date' => $movement['date'] ?? 'Recorded',
                'type' => $type,
                'label' => match ($type) {
                    'transfer' => 'Transfer',
                    'loan' => 'Loan',
                    'loan_return' => 'Loan return',
                    default => CareerLabels::value($type, 'Club movement'),
                },
                'headline' => $headline,
            ];
        }
        foreach ((array) ($summary['decision_history'] ?? []) as $decision) {
            if (!is_array($decision) || trim((string) ($decision['story'] ?? '')) === '') { continue; }
            $recent[] = ['date' => $decision['date'] ?? 'Recorded', 'type' => 'decision', 'label' => 'Career decision', 'headline' => (string) $decision['story']];
        }
        usort($recent, static fn (array $left, array $right): int => strcmp((string) ($right['date'] ?? '') . (string) ($right['headline'] ?? ''), (string) ($left['date'] ?? '') . (string) ($left['headline'] ?? '')));

        return [
            'contract' => [
                'code' => $contractCode,
                'status' => $contractStatus === '' ? null : $contractStatus,
                'label' => $contractLabel,
                'explanation' => $contractExplanation,
                'club' => $contractProjection['club'] ?? ($club['name'] ?? null),
                'end_date' => $contractProjection['end_date'] ?? null,
                'remaining_days' => $contractProjection['remaining_days'] ?? null,
                'role' => $summary['current_role'] ?? $summary['squad_role'] ?? null,
                'renewal_state' => $hasContractDecision ? 'decision_available' : $contractOutlook,
            ],
            'transfer_request' => $requestProjection,
            'loan' => [
                'active' => $loan !== null,
                'label' => $loan === null ? null : 'On loan',
                'loan_club' => $loan['loan_club']['name'] ?? null,
                'parent_club' => $loan['parent_club']['name'] ?? ($summary['parent_club']['name'] ?? null),
                'return_date' => $loan['scheduled_end_date'] ?? null,
                'status' => $loan['status'] ?? null,
            ],
            'action_required' => $required,
            'action_required_count' => count($required),
            'outlook' => [
                'category' => $outlook['category'] ?? null,
                'label' => $outlookLabel,
                'explanation' => $outlookExplanation,
                'guidance' => $guidance,
                'action_type' => $firstGuidance['action_type'] ?? null,
                'evidence' => $evidence,
            ],
            'current' => [
                'club' => $club['name'] ?? null,
                'role' => $summary['current_role'] ?? $summary['squad_role'] ?? null,
                'career_state' => $summary['career_state'] ?? 'active',
            ],
            'recent_outcomes' => array_slice($recent, 0, 4),
        ];
    }

    /**
     * Project the latest completed controlled fixture for Career Home.
     * Selection, result, and Player statistics remain canonical Match facts;
     * this method only assembles the compact read model needed after review.
     * @param array<string, mixed> $summary
     * @return array<string, mixed>|null
     */
    public function recentMatch(DatabaseInterface $database, array $summary): ?array
    {
        $player = is_array($summary['player'] ?? null) ? $summary['player'] : [];
        $playerId = (string) ($player['id'] ?? '');
        if ($playerId === '') {
            return null;
        }
        $matches = new MatchRepository($database);
        $stats = new PlayerMatchStatRepository($database);
        $ratings = new PlayerMatchRatingService();
        foreach ((array) ($summary['recent_selection'] ?? []) as $selection) {
            if (!is_array($selection) || !is_string($selection['match_id'] ?? null)) {
                continue;
            }
            try {
                $match = $matches->get((string) $selection['match_id']);
            } catch (\Throwable) {
                continue;
            }
            if ($match->status() !== MatchStatus::Completed || $match->result() === null) {
                continue;
            }
            $clubId = (string) ($selection['club_id'] ?? '');
            $result = $match->result();
            $homeGoals = $result->homeGoals();
            $awayGoals = $result->awayGoals();
            $controlledGoals = $clubId === $match->homeClubId()->value() ? $homeGoals : $awayGoals;
            $opponentGoals = $clubId === $match->homeClubId()->value() ? $awayGoals : $homeGoals;
            $perspectiveResult = $controlledGoals === $opponentGoals ? 'DRAW' : ($controlledGoals > $opponentGoals ? 'WIN' : 'LOSS');
            $stat = null;
            foreach ($stats->byMatch($match->id()) as $candidate) {
                if ($candidate->playerId()->value() === $playerId) {
                    $stat = $candidate;
                    break;
                }
            }
            $selectionStatus = (string) ($selection['status'] ?? 'not_selected');
            $participationCode = match ($selectionStatus) {
                'starter' => 'starter',
                'bench' => $stat?->appeared() ? 'substitute' : 'unused_substitute',
                'unavailable' => 'unavailable',
                'suspended' => 'suspended',
                default => 'not_selected',
            };
            $participation = match ($participationCode) {
                'starter' => 'Started',
                'substitute' => 'Substitute appearance',
                'unused_substitute' => 'Unused substitute',
                'unavailable' => 'Unavailable',
                'suspended' => 'Suspended',
                default => 'Not selected',
            };
            $competition = $this->competitionRecord($database, $match->competitionId());

            return [
                'match_id' => $match->id()->value(),
                'date' => $match->scheduledDate()->toIsoString(),
                'competition' => $competition->name(),
                'home_club' => $this->teamName($database, $match->homeClubId()->value()),
                'away_club' => $this->teamName($database, $match->awayClubId()->value()),
                'home_goals' => $homeGoals,
                'away_goals' => $awayGoals,
                'result' => $perspectiveResult,
                'participation' => $participation,
                'participation_code' => $participationCode,
                'appeared' => $stat?->appeared() ?? false,
                'minutes' => $stat?->appeared() ? $stat->minutes() : null,
                'rating' => $stat?->appeared() ? $ratings->rate($stat, PlayerPosition::fromInput((string) ($player['primary_position'] ?? 'CM'))) : null,
                'goals' => $stat?->goals() ?? 0,
                'assists' => $stat?->assists() ?? 0,
            ];
        }

        return null;
    }

    /**
     * Project the bounded progression facts already retained by the Player
     * and Club owners. Attribute history contains deltas, not historical
     * attribute snapshots, so this boundary never invents a from-value.
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    public function progressionContext(array $summary): array
    {
        $player = is_array($summary['player'] ?? null) ? $summary['player'] : [];
        $attributes = is_array($player['attributes'] ?? null) ? $player['attributes'] : [];
        $attributeValues = [];
        foreach (['pace', 'shooting', 'passing', 'dribbling', 'defending', 'physicality'] as $attribute) {
            if (array_key_exists($attribute, $attributes)) {
                $attributeValues[$attribute] = (int) $attributes[$attribute];
            }
        }
        $history = array_values(array_filter((array) ($summary['development_history'] ?? []), 'is_array'));
        usort($history, static fn (array $left, array $right): int => strcmp((string) ($left['date'] ?? '') . (string) ($left['id'] ?? ''), (string) ($right['date'] ?? '') . (string) ($right['id'] ?? '')));
        $recent = [];
        $positive = 0;
        $negative = 0;
        foreach (array_reverse($history) as $entry) {
            $attributeChanges = [];
            foreach ((array) ($entry['attribute_deltas'] ?? []) as $attribute => $delta) {
                $delta = (int) $delta;
                if ($delta === 0) { continue; }
                $attributeChanges[] = ['attribute' => (string) $attribute, 'label' => CareerLabels::value($attribute, (string) $attribute), 'delta' => $delta];
                $delta > 0 ? ++$positive : ++$negative;
            }
            $hasOvrTransition = array_key_exists('before_ovr', $entry) && array_key_exists('after_ovr', $entry);
            $ovrDelta = $hasOvrTransition ? (int) $entry['after_ovr'] - (int) $entry['before_ovr'] : 0;
            if ($ovrDelta === 0 && $attributeChanges === []) { continue; }
            if ($ovrDelta > 0) { ++$positive; }
            if ($ovrDelta < 0) { ++$negative; }
            $recent[] = [
                'date' => (string) ($entry['date'] ?? $entry['occurred_date'] ?? ''),
                'source' => (string) ($entry['source'] ?? ''),
                'source_label' => $this->developmentSourceLabel((string) ($entry['source'] ?? '')),
                'before_ovr' => array_key_exists('before_ovr', $entry) ? (int) $entry['before_ovr'] : null,
                'after_ovr' => array_key_exists('after_ovr', $entry) ? (int) $entry['after_ovr'] : null,
                'ovr_delta' => $ovrDelta,
                'attribute_changes' => $attributeChanges,
            ];
            if (count($recent) >= 5) { break; }
        }
        $roleHistory = array_values(array_filter((array) ($summary['role_history'] ?? []), 'is_array'));
        $roleChanges = [];
        $previousRole = null;
        foreach ($roleHistory as $entry) {
            $role = (string) ($entry['role'] ?? '');
            if ($role === '') { continue; }
            if ($previousRole !== null && $previousRole !== $role) {
                $roleChanges[] = [
                    'from' => $previousRole,
                    'to' => $role,
                    'date' => (string) ($entry['occurred_date'] ?? ''),
                    'season_id' => (string) ($entry['season_id'] ?? ''),
                ];
            }
            $previousRole = $role;
        }
        $performance = is_array($summary['latest_season_performance'] ?? null)
            ? $summary['latest_season_performance']
            : (is_array($summary['season_performance'] ?? null) ? $summary['season_performance'] : []);
        $playingTime = is_array($summary['recent_playing_time'] ?? null) ? $summary['recent_playing_time'] : [];
        $feedback = match (true) {
            $recent === [] => ['code' => 'no_recorded_change', 'label' => 'No recent recorded change', 'explanation' => 'No attribute or OVR change is retained for this period.'],
            $positive > 0 && $negative === 0 => ['code' => 'improving', 'label' => 'Improving', 'explanation' => 'Recent recorded development is moving upward.'],
            $negative > 0 && $positive === 0 => ['code' => 'declining', 'label' => 'Declining', 'explanation' => 'Recent recorded development includes downward changes.'],
            default => ['code' => 'mixed', 'label' => 'Mixed recent change', 'explanation' => 'Recent records include both upward and downward changes.'],
        };

        return [
            'current' => [
                'ovr' => array_key_exists('current_ovr', $summary) ? (int) $summary['current_ovr'] : (int) ($player['overall_rating'] ?? 0),
                'attributes' => $attributeValues,
                'position' => $player['primary_position'] ?? null,
                'age' => $summary['age'] ?? null,
                'role' => $summary['current_role'] ?? $summary['squad_role'] ?? null,
            ],
            'training' => [
                'focus' => $summary['training_focus'] ?? null,
                'focus_label' => CareerLabels::value($summary['training_focus'] ?? null, 'No focus selected'),
                'position_focus' => is_array($summary['position_development'] ?? null) ? $summary['position_development'] : [],
            ],
            'recent_changes' => $recent,
            'history_count' => count($history),
            'feedback' => $feedback,
            'evidence' => [
                'playing_time' => $playingTime,
                'performance' => $performance,
                'form' => is_array($summary['recent_form'] ?? null) ? $summary['recent_form'] : [],
                'availability' => $summary['availability'] ?? null,
            ],
            'role_change' => $roleChanges === [] ? null : $roleChanges[array_key_last($roleChanges)],
            'role_changes' => $roleChanges,
            'career_stage' => $this->careerStage($summary),
            'outlook' => is_array($summary['career_outlook'] ?? null) ? $summary['career_outlook'] : [],
        ];
    }

    /** @param array<string, mixed> $summary @param array<string, mixed> $discipline @param array<string, mixed> $recovery @param array<string, mixed> $readiness @return array<string, mixed> */
    private function homeAvailability(array $summary, array $discipline, array $recovery, array $readiness, bool $retired): array
    {
        if ($retired) {
            return ['code' => 'retired', 'label' => 'Career complete', 'explanation' => 'Your playing Career is complete. Your record remains available to review.'];
        }
        if (($discipline['active'] ?? false) === true) {
            return ['code' => 'suspended', 'label' => 'Suspended', 'explanation' => 'A competition suspension is currently limiting Match eligibility.'];
        }
        if (($summary['active_injury'] ?? null) !== null) {
            return ['code' => 'injured', 'label' => 'Injured', 'explanation' => (string) ($recovery['message'] ?? 'Recovery is required before returning to Match play.')];
        }
        $availability = (string) ($summary['availability'] ?? 'available');
        if ($availability === 'limited' || in_array((string) ($readiness['label'] ?? ''), ['tired', 'fatigued'], true)) {
            return ['code' => 'limited', 'label' => 'Limited', 'explanation' => (string) ($readiness['description'] ?? 'Readiness is being managed between Matches.')];
        }

        return ['code' => 'available', 'label' => 'Available', 'explanation' => 'Available for the next football block.'];
    }

    /** @param array<string, mixed> $summary @param array<string, mixed> $season @param array<string, mixed> $manager @param array<string, mixed> $competition @param array<string, mixed> $status @return array<string, mixed> */
    private function homePlayingStatus(array $summary, array $season, array $manager, array $competition, array $status): array
    {
        $role = (string) ($summary['current_role'] ?? $summary['squad_role'] ?? '');
        $minutes = (int) ($season['minutes'] ?? 0);
        $appearances = (int) ($season['appearances'] ?? 0);
        $starts = (int) ($season['starts'] ?? 0);
        if (in_array($status['code'] ?? '', ['injured', 'suspended', 'limited'], true)) {
            $explanation = (string) ($status['explanation'] ?? 'Availability is affecting Match involvement.');
        } elseif (in_array((string) ($manager['playing_time_status'] ?? ''), ['below_expectation', 'severely_below_expectation'], true)) {
            $explanation = 'Competition for places is limiting your minutes.';
        } elseif ((int) ($competition['higher_ovr_count'] ?? 0) >= 2 && $minutes === 0) {
            $explanation = 'Strong competition in your positions is limiting your minutes.';
        } elseif ($appearances === 0 && in_array($role, ['prospect', 'rotation'], true)) {
            $explanation = 'You are building evidence for a larger role.';
        } else {
            $explanation = (string) ($manager['feedback'] ?? 'Your role is being assessed through availability, form and Match evidence.');
        }

        return [
            'role' => $role,
            'label' => $role === '' ? 'Squad role not set' : $role,
            'appearances' => $appearances,
            'starts' => $starts,
            'minutes' => $minutes,
            'explanation' => $explanation,
        ];
    }

    /** @param array<string, mixed> $summary @param array<string, mixed> $outlook @param array<string, mixed> $status @return list<array<string, mixed>> */
    private function homeAttention(array $summary, array $outlook, array $status, ?SimulationDate $date): array
    {
        $items = [];
        foreach ((array) ($summary['pending_decisions'] ?? []) as $decision) {
            if (!is_array($decision)) { continue; }
            $type = (string) ($decision['type'] ?? 'career');
            $items[] = [
                'type' => $type,
                'label' => match ($type) {
                    'retirement' => 'Retirement decision',
                    'contract_renewal' => 'Contract decision',
                    'transfer_interest' => 'Transfer opportunity',
                    'loan' => 'Loan decision',
                    default => 'Career decision',
                },
                'why' => match ($type) {
                    'retirement' => 'Your Season boundary requires a choice about continuing your playing Career.',
                    'contract_renewal' => 'A Contract choice is waiting before the next Career step.',
                    'transfer_interest' => 'A Club opportunity is waiting for your decision.',
                    'loan' => 'A loan decision is waiting for your response.',
                    default => 'A Career decision is waiting for your response.',
                },
                'destination' => 'decision',
                'priority' => 'REQUIRED_DECISION',
            ];
        }
        if (($summary['pending_career_event'] ?? null) !== null) {
            $items[] = ['type' => 'career_event', 'label' => 'Career event', 'why' => 'A Career moment is waiting for your response.', 'destination' => 'event', 'priority' => 'REQUIRED_DECISION'];
        }
        if (($status['code'] ?? '') === 'injured') {
            $items[] = ['type' => 'injury', 'label' => 'Recovery', 'why' => 'Your injury currently blocks normal Match involvement.', 'destination' => 'training', 'priority' => 'RECOVERY'];
        } elseif (($status['code'] ?? '') === 'suspended') {
            $items[] = ['type' => 'suspension', 'label' => 'Suspension', 'why' => 'Your current suspension limits applicable Match selection.', 'destination' => null, 'priority' => 'RECOVERY'];
        }
        if (($outlook['category'] ?? null) === 'contract_uncertainty' && ($outlook['contract_outlook'] ?? null) === 'approaching_decision') {
            $items[] = ['type' => 'contract_review', 'label' => 'Contract review', 'why' => 'Your current Contract is approaching a decision point.', 'destination' => 'market', 'priority' => 'CAREER_OPPORTUNITY'];
        }
        if (($summary['transfer_request']['status'] ?? 'none') === 'requested') {
            $items[] = ['type' => 'transfer_request', 'label' => 'Transfer request active', 'why' => 'Your transfer request remains active while the Career moves forward.', 'destination' => null, 'priority' => 'CAREER_OPPORTUNITY'];
        }

        return $items;
    }

    /** @param array<string, mixed> $summary @param list<array<string, mixed>> $attention @param array<string, mixed> $status @param array<string, mixed>|null $next @return array<string, mixed> */
    private function homePrimaryAction(array $summary, array $attention, array $status, ?array $next): array
    {
        if (($summary['career_state'] ?? 'active') === 'retired') {
            return ['kind' => 'legacy', 'priority' => 'INFORMATIONAL', 'label' => 'Open Career Legacy', 'why' => 'Review the completed playing Career.'];
        }
        foreach ($attention as $item) {
            if (($item['priority'] ?? '') === 'REQUIRED_DECISION') {
                return ['kind' => (string) ($item['destination'] ?? 'decision'), 'priority' => 'REQUIRED_DECISION', 'label' => 'Resolve ' . strtolower((string) ($item['label'] ?? 'Career decision')), 'why' => (string) ($item['why'] ?? '')];
            }
        }
        if (($status['code'] ?? '') === 'injured') {
            return ['kind' => 'continue', 'priority' => 'RECOVERY', 'label' => 'Continue recovery', 'why' => (string) ($status['explanation'] ?? '')];
        }
        if (($status['code'] ?? '') === 'suspended') {
            return ['kind' => 'continue', 'priority' => 'RECOVERY', 'label' => 'Continue Career', 'why' => (string) ($status['explanation'] ?? '')];
        }
        if (($summary['current_club'] ?? null) === null || ($summary['current_contract'] ?? null) === null) {
            return ['kind' => 'market', 'priority' => 'CAREER_OPPORTUNITY', 'label' => 'Review the transfer market', 'why' => 'You are a free agent; a new Club is the next Career step.'];
        }
        if ($next !== null) {
            return ['kind' => 'continue', 'priority' => 'MATCHDAY', 'label' => 'Continue to next fixture', 'why' => 'The next scheduled fixture is ready to progress.'];
        }

        return ['kind' => 'continue', 'priority' => 'INFORMATIONAL', 'label' => 'Continue Career', 'why' => 'Advance the Career to the next available football event.'];
    }

    /** @param array<string, mixed>|null $contract @return array<string, mixed>|null */
    private function homeContract(?array $contract, ?SimulationDate $date): ?array
    {
        if ($contract === null) { return null; }
        $remainingDays = null;
        if ($date !== null && is_string($contract['end_date'] ?? null)) {
            $remainingDays = $date->daysUntil(SimulationDate::fromIsoString((string) $contract['end_date']));
        }

        return ['status' => $contract['status'] ?? null, 'club' => $contract['club']['name'] ?? null, 'end_date' => $contract['end_date'] ?? null, 'remaining_days' => $remainingDays, 'wage' => $contract['wage'] ?? null];
    }

    /** @param array<string, mixed> $summary @return list<array<string, mixed>> */
    private function homeQuickLinks(array $summary, bool $retired): array
    {
        $player = is_array($summary['player'] ?? null) ? $summary['player'] : [];
        $links = [
            ['page' => 'profile', 'label' => 'Player Profile', 'player' => $player['id'] ?? null],
            ['page' => 'career', 'label' => 'Career History'],
            ['page' => 'trophies', 'label' => 'Trophy Room'],
        ];
        if ($retired) {
            $links[] = ['page' => 'legacy', 'label' => 'Career Legacy'];
        }
        if (!$retired) {
            $links[] = ['page' => 'training', 'label' => 'Training'];
            $links[] = ['page' => 'market', 'label' => 'Contract & Movement'];
        }
        foreach ((array) ($summary['season_history'] ?? []) as $season) {
            if (is_array($season) && ($season['season_status'] ?? null) === 'completed') {
                $links[] = ['page' => 'season-review', 'label' => 'Season Review'];
                break;
            }
        }
        $links[] = ['page' => 'finances', 'label' => 'Finances'];
        $links[] = ['page' => 'pulse', 'label' => 'Pulse'];

        return $links;
    }

    /**
     * Compose the read-only Trophy Room projection from the existing
     * presentation summary. The projection owns no achievement facts.
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    public function achievementSummary(array $summary): array
    {
        return (new CareerAchievementSummary())->project($summary);
    }

    /**
     * Read only the canonical facts required by Trophy Room. The full
     * Career Home snapshot deliberately remains broader; Trophy Room does
     * not need its readiness, market, social, Pulse, or fixture projections.
     * @return array<string, mixed>
     */
    public function trophyRoomSummary(DatabaseInterface $database, string $saveId): array
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
        $summary['legacy'] = $this->legacyService()->summary($database, $career->playerId()->value());
        $currentClubId = is_array($summary['current_club'] ?? null)
            ? (string) ($summary['current_club']['id'] ?? '')
            : '';
        $summary['captaincy'] = (new ClubCaptaincyService($this->services->clubModule()->service()))->contextForPlayer(
            $database,
            $career->playerId()->value(),
            $currentClubId === '' ? null : $currentClubId,
            $world->currentSeasonId(),
            $date,
        );

        return $summary;
    }

    /**
     * Read only the progression context needed to render a Competition page.
     * The page does not need the broader Career Home projections.
     * @return array{summary: array<string, mixed>, date: SimulationDate}
     */
    public function competitionContext(DatabaseInterface $database, string $saveId): array
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

        return ['summary' => $summary, 'date' => $date];
    }

    /** @return array<string, mixed> */
    public function competitionLeaderboards(DatabaseInterface $database, string $competitionId, SeasonId $seasonId, ?string $controlledPlayerId = null): array
    {
        $competition = $this->competitionRecord($database, $competitionId);
        $rows = (new CompetitionStatisticsQuery())->forCompetitionSeason($database, $competitionId, $seasonId);
        $playerIds = array_values(array_unique(array_map(static fn (array $row): string => (string) ($row['player_id'] ?? ''), $rows)));
        $playerNames = [];
        if ($playerIds !== []) {
            foreach ((new PlayerRepository($database))->byIds($playerIds) as $player) {
                $playerNames[$player->id()->value()] = $player->preferredName();
            }
        }
        $clubNames = [];
        foreach ($this->services->clubModule()->service()->repository($database)->all() as $club) {
            $clubNames[$club->id()->value()] = $club->canonicalName();
        }
        $season = (new SeasonRepository($database))->get($seasonId);

        return (new CompetitionLeaderboardProjection())->project(
            $rows,
            $playerNames,
            $clubNames,
            $competition->id()->value(),
            $competition->name(),
            $season->id()->value(),
            $season->label(),
            $controlledPlayerId,
        );
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
        $progression = $controlled ? $this->progressionContext($controlledSummary) : null;
        $recovery = $controlled ? $this->recoveryService()->context($database, $player->id(), $date) : null;
        $discipline = $controlled ? (new PlayerDisciplineService())->context($database, $player->id()) : null;
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
            $club = $this->clubRecord($database, $membership->clubId());
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
        $publicSummary = [];
        if ($controlled && is_array($controlledSummary['international'] ?? null)) {
            $internationalContext = $controlledSummary['international'];
        } else {
            $publicSummary = (new PlayerCareerProgressionQuery($this->services->clubModule()->service()))->summary($database, $playerId, $date, $seasonId);
            $internationalContext = $publicSummary['international'] ?? [];
        }
        $positionContext = $controlled
            ? (new PositionDevelopmentService())->context($database, $playerId, $date)
            : null;
        $onPitchRole = $controlled && is_array($controlledSummary['on_pitch_role'] ?? null)
            ? $controlledSummary['on_pitch_role']
            : (new OnPitchRoleService())->publicContext($database, $player);
        $traits = $controlled && is_array($controlledSummary['traits'] ?? null)
            ? $controlledSummary['traits']
            : (is_array($publicSummary['traits'] ?? null) ? $publicSummary['traits'] : (new PlayerTraitService())->derive($database, $player, $seasonId));
        $careerContext = $controlled && is_array($controlledSummary['career_context'] ?? null)
            ? $controlledSummary['career_context']
            : (is_array($publicSummary['career_context'] ?? null) ? $publicSummary['career_context'] : []);
        $captaincy = (new ClubCaptaincyService($this->services->clubModule()->service()))->contextForPlayer(
            $database,
            $playerId,
            $club?->id()->value(),
            $seasonId,
            $date,
        );
        $setPieceResponsibility = (new SetPieceResponsibilityService($this->services->clubModule()->service()))->contextForPlayer(
            $database,
            $playerId,
            $club?->id()->value(),
            $seasonId,
            $date,
        );
        $nextFixture = $controlled ? $this->nextMatch($database, $controlledSummary) : null;
        $social = $this->services->playerModule()->service()->socialService();
        $legacy = $controlled
            ? $this->legacyService()->summary($database, $playerId)
            : ['honours' => [], 'awards' => [], 'records' => [], 'milestones' => [], 'career_landmarks' => [], 'career_timeline' => [], 'personal_bests' => [], 'defining_seasons' => []];
        $market = $controlled ? $this->services->transferModule()->service()->careerMovement()->marketContext($database, $playerId, $seasonId, $date) : null;
        if ($club !== null) {
            $cups = new DomesticCupService($this->services->clubModule()->service());
            $europe = new EuropeanCompetitionService($this->services->clubModule()->service(), $cups);
            foreach (array_filter((new ClubMembershipRepository($database))->byClub($club->id()), static fn ($candidate): bool => $candidate->seasonId()->value() === $seasonId->value()) as $clubMembership) {
                $cup = $this->competitionRecord($database, $clubMembership->competitionId());
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
        $leaderboardContext = $controlled && $competition !== null
            ? $this->competitionLeaderboards($database, $competition->id()->value(), $seasonId, $playerId)
            : null;

        return [
            'player' => $player,
            'age' => $player->ageAt($date),
            'nationality' => $nation?->displayName() ?? $player->primaryNationId()->value(),
            'club' => $club,
            'competition' => $competition,
            'leaderboard_context' => $leaderboardContext,
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
            'pulse' => $controlled ? $this->services->playerModule()->service()->pulseService()->context($database, $playerId) : null,
            'pulse_feed' => $controlled ? $this->services->playerModule()->service()->pulseService()->feed($database, $playerId, 5) : [],
            'career_stats' => $careerStats,
            'recent_form' => $form,
            'match_history' => $this->playerMatchHistory($database, $playerId, $seasonId, $club?->id()->value()),
            'legacy' => $legacy,
            'controlled' => $controlled,
            'training_focus' => $controlled ? $controlledSummary['training_focus'] ?? null : null,
            'training_intensity' => $controlled ? $controlledSummary['training_intensity'] ?? null : null,
            'readiness' => $controlled ? $controlledSummary['readiness'] ?? null : null,
            'injury_recovery' => $recovery,
            'discipline' => $discipline,
            'manager_context' => $controlled ? $controlledSummary['manager_context'] ?? null : null,
            'club_season' => $controlled ? $controlledSummary['club_season'] ?? null : null,
            'position_competition' => $controlled ? $controlledSummary['position_competition'] ?? null : null,
            'position_development' => $positionContext,
            'on_pitch_role' => $onPitchRole,
            'traits' => $traits,
            'career_context' => $careerContext,
            'captaincy' => $captaincy,
            'set_piece_responsibility' => $setPieceResponsibility,
            'next_fixture_context' => $nextFixture['fixture_context'] ?? null,
            'club_journey' => $careerContext['club_journey'] ?? [],
            'club_attachment' => $careerContext['attachment'] ?? null,
            'career_direction' => $careerContext['direction'] ?? null,
            'position_history' => $controlled ? (new PositionDevelopmentService())->history($database, $playerId) : [],
            'priority' => $controlled ? $controlledSummary['priority'] ?? null : null,
            'contract' => $controlled ? $controlledSummary['current_contract'] ?? null : null,
            'active_loan' => $controlled ? $controlledSummary['active_loan'] ?? null : null,
            'decision_hub' => $controlled ? $this->careerDecisionHub($controlledSummary, $date) : null,
            'market' => $market,
            'career_state' => $controlled ? ($controlledSummary['career_state'] ?? $player->careerState()->value) : $player->careerState()->value,
            'career_phase' => $controlled ? ($controlledSummary['career_phase'] ?? null) : null,
            'retirement' => $controlled ? ($controlledSummary['retirement'] ?? null) : null,
            'progression' => $progression,
        ];
    }

    /** @return array<string, mixed> */
    public function competitionView(DatabaseInterface $database, string $competitionId, SeasonId $seasonId, SimulationDate $date, ?string $controlledClubId = null, ?string $controlledPlayerId = null): array
    {
        $competition = $this->competitionRecord($database, $competitionId);
        $matchService = $this->services->matchModule()->service();
        $standings = [];
        if ($competition->type() === CompetitionType::DomesticLeague) {
            foreach ($matchService->standings($database, $competition->id(), $seasonId) as $row) {
                $standings[] = $row + [
                    'club' => $this->clubRecord($database, (string) $row['club_id'])->canonicalName(),
                    'controlled' => $controlledClubId !== null && (string) $row['club_id'] === $controlledClubId,
                ];
            }
        }
        $recentMatches = [];
        $upcomingMatches = [];
        foreach ($matchService->repository($database)->byCompetition($competition->id(), $seasonId) as $match) {
            if ($match->status() === MatchStatus::Completed && !$match->scheduledDate()->isAfter($date)) {
                $recentMatches[] = $match;
            }
            if ($match->status() === MatchStatus::Scheduled && !$match->scheduledDate()->isBefore($date)) {
                $upcomingMatches[] = $match;
            }
        }
        $recent = array_map(
            fn (GameMatch $match): string => $this->fixtureText($database, $match, $controlledClubId ?? ''),
            array_slice(array_reverse($recentMatches), 0, 8),
        );
        $upcoming = array_map(
            fn (GameMatch $match): string => $this->fixtureText($database, $match, $controlledClubId ?? ''),
            array_slice($upcomingMatches, 0, 8),
        );

        $view = [
            'competition' => $competition,
            'standings' => $standings,
            'leaderboards' => in_array($competition->type(), [CompetitionType::DomesticLeague, CompetitionType::DomesticCup, CompetitionType::Continental], true)
                ? $this->competitionLeaderboards($database, $competitionId, $seasonId, $controlledPlayerId)
                : null,
            'recent_results' => $recent,
            'upcoming_fixtures' => $upcoming,
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
        $club = $this->clubRecord($database, $clubId);
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
        $objective = (new ClubSeasonObjectiveService($this->services->clubModule()->service()))->context($database, $club->id(), $seasonId, $date);

        return [
            'club' => $club,
            'competition' => $competition,
            'position' => $position,
            'club_season' => $objective,
            'recent_results' => array_slice(array_reverse($recent), 0, 5),
            'upcoming_fixtures' => array_slice($upcoming, 0, 5),
        ];
    }

    /** @return array<string, mixed> */
    private function controlledSummary(DatabaseInterface $database, string $saveId): array
    {
        // Player Profile builds its own legacy projection below. Avoid
        // materializing the same durable Career legacy twice on this read
        // path while retaining the shared canonical summary/context.
        return $this->snapshot($database, $saveId, false)['summary'];
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
        $playerStats = (new PlayerMatchStatRepository($database))->byPlayer($playerId);
        $matchesById = [];
        foreach ($matches->byIds(array_map(static fn ($stat): string => $stat->matchId()->value(), $playerStats)) as $match) {
            $matchesById[$match->id()->value()] = $match;
        }
        $competitionsById = [];
        foreach ($competitions->bySeason($seasonId) as $competition) {
            $competitionsById[$competition->id()->value()] = $competition;
        }
        foreach ($playerStats as $stat) {
            $match = $matchesById[$stat->matchId()->value()] ?? null;
            if ($match === null) {
                continue;
            }
            if ($match->seasonId()->value() !== $seasonId->value() || $match->status() !== MatchStatus::Completed) { continue; }
            $result = $match->result();
            $competition = $competitionsById[$match->competitionId()->value()] ?? $competitions->get($match->competitionId());
            $detailed[] = [
                'match_id' => $match->id()->value(),
                'date' => $match->scheduledDate()->toIsoString(),
                'competition' => $competition->name(),
                'home' => $this->teamName($database, $match->homeClubId()->value()),
                'away' => $this->teamName($database, $match->awayClubId()->value()),
                'home_goals' => $result?->homeGoals() ?? 0,
                'away_goals' => $result?->awayGoals() ?? 0,
                'minutes' => $stat->appeared() ? $stat->minutes() : null,
                'rating' => $stat->appeared() ? $ratings->rate($stat, $player->primaryPosition()) : null,
                'goals' => $stat->goals(),
                'assists' => $stat->assists(),
                'participation' => $stat->started() ? 'starter' : 'substitute',
                'detailed' => true,
            ];
        }
        if ($detailed !== []) {
            usort($detailed, static fn (array $left, array $right): int => strcmp((string) $right['date'], (string) $left['date']));
            return array_slice($detailed, 0, 5);
        }
        if ($clubId === null) { return []; }
        $compact = [];
        $compactMatches = $matches->byClub($clubId, $seasonId);
        $competitionsById = [];
        foreach ($competitions->bySeason($seasonId) as $competition) {
            $competitionsById[$competition->id()->value()] = $competition;
        }
        foreach ($compactMatches as $match) {
            if ($match->status() !== MatchStatus::Completed) { continue; }
            $result = $match->result();
            $competition = $competitionsById[$match->competitionId()->value()] ?? $competitions->get($match->competitionId());
            $compact[] = [
                'match_id' => $match->id()->value(),
                'date' => $match->scheduledDate()->toIsoString(),
                'competition' => $competition->name(),
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
        $competition = $this->competitionRecord($database, $match->competitionId());

        $formerClubIds = is_array($summary['career_context']['former_club_ids'] ?? null) ? $summary['career_context']['former_club_ids'] : [];
        $perspectiveClubId = (string) ($next['controlled_team_id'] ?? '');
        $fixtureContext = (new ClubFixtureContextService())->forMatch(
            $match,
            $competition,
            $this->seasonStakeForMatch($summary, $match->id()->value()),
            $formerClubIds,
            $perspectiveClubId,
        );

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
            'fixture_context' => $fixtureContext,
        ];
    }

    /**
     * Project the controlled Player's pre-Match context without selecting a
     * squad or consuming Match randomness. Selection is intentionally pending
     * until the canonical Match execution path confirms it at kickoff.
     *
     * @param array<string, mixed>|null $summary
     * @return array<string, mixed>
     */
    public function preMatch(DatabaseInterface $database, GameMatch $match, string $playerId, ?string $controlledClubId = null, ?array $summary = null): array
    {
        $players = new PlayerRepository($database);
        $player = $players->get($playerId);
        $competition = $this->competitionRecord($database, $match->competitionId());
        $controlledClubId ??= (string) ($summary['current_club']['id'] ?? '');
        if ($competition->type() === CompetitionType::International && !in_array($controlledClubId, [$match->homeClubId()->value(), $match->awayClubId()->value()], true)) {
            $controlledClubId = 'national-team-' . $player->primaryNationId()->value();
        }
        if ($controlledClubId === '') {
            $controlledClubId = $match->homeClubId()->value();
        }

        $assessment = (new \Goal\Legacy\Modules\Player\PlayerAvailabilityService())->assess($database, $player->id(), $match->scheduledDate());
        $discipline = (new PlayerDisciplineService())->eligibility($database, $match, $player->id());
        $availability = $this->preMatchAvailability($assessment, $discipline);
        $formerClubIds = is_array($summary['career_context']['former_club_ids'] ?? null) ? $summary['career_context']['former_club_ids'] : [];
        $fixtureContext = (new ClubFixtureContextService())->forMatch(
            $match,
            $competition,
            $this->seasonStakeForMatch((array) $summary, $match->id()->value()),
            $formerClubIds,
            $controlledClubId,
        );
        $clubIsHome = $controlledClubId === $match->homeClubId()->value();
        $clubIsAway = $controlledClubId === $match->awayClubId()->value();
        $role = (string) ($summary['current_role'] ?? $summary['squad_role'] ?? '');
        $form = is_array($summary['recent_form'] ?? null) ? $summary['recent_form'] : [];
        $readiness = $assessment->readiness();
        $onPitchRole = is_array($summary['on_pitch_role'] ?? null) ? $summary['on_pitch_role'] : [];
        $captaincy = is_array($summary['captaincy'] ?? null) ? $summary['captaincy'] : [];
        $setPiece = is_array($summary['set_piece_responsibility'] ?? null) ? $summary['set_piece_responsibility'] : [];

        return [
            'state' => 'pre_match',
            'match_id' => $match->id()->value(),
            'competition' => $competition->name(),
            'competition_type' => $competition->type()->value,
            'competition_stage' => $fixtureContext['competition']['stage'] ?? null,
            'date' => $match->scheduledDate()->toIsoString(),
            'home_club' => $this->teamName($database, $match->homeClubId()->value()),
            'away_club' => $this->teamName($database, $match->awayClubId()->value()),
            'controlled_club_id' => $controlledClubId,
            'home_away' => $clubIsHome ? 'home' : ($clubIsAway ? 'away' : 'neutral'),
            'fixture_context' => $fixtureContext,
            'player' => [
                'id' => $player->id()->value(),
                'name' => $player->preferredName(),
                'primary_position' => $player->primaryPosition()->value,
                'position_label' => CareerLabels::position($player->primaryPosition()->value),
                'role' => $role,
                'role_label' => CareerLabels::value($role, 'Squad role not set'),
                'form' => $form,
                'form_label' => $this->preMatchFormLabel($form),
                'readiness' => $readiness,
                'availability' => $availability,
                'selection' => [
                    'code' => $availability['code'] === 'available' || $availability['code'] === 'limited' ? 'pending' : $availability['code'],
                    'label' => $availability['code'] === 'available' || $availability['code'] === 'limited' ? 'Selection confirmed at kickoff' : $availability['label'],
                    'explanation' => $availability['code'] === 'available' || $availability['code'] === 'limited'
                        ? 'The canonical Match selection will confirm Starting XI, bench or not selected at kickoff.'
                        : $availability['explanation'],
                ],
                'on_pitch_role' => $onPitchRole['role_label'] ?? null,
                'captaincy' => in_array((string) ($captaincy['status'] ?? ''), ['captain', 'vice_captain'], true) ? ($captaincy['label'] ?? null) : null,
                'set_piece' => ($setPiece['status'] ?? 'none') === 'none' ? null : ($setPiece['label'] ?? null),
            ],
            'discipline' => $discipline,
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
        $competitionRecord = $this->competitionRecord($database, (string) $competition['id']);
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
                    'club_season' => $summary['club_season'] ?? null,
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
        $competition = $this->competitionRecord($database, $match->competitionId());
        $homeName = $this->teamName($database, $match->homeClubId()->value());
        $awayName = $this->teamName($database, $match->awayClubId()->value());
        $player = $players->get($playerId);
        $story = $matchService->playerStory($database, $match->id(), $playerId);
        $performance = $story;
        foreach ((array) ($story['stats'] ?? []) as $key => $value) {
            $performance[$key] = $value;
        }
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
        $landmarkCallouts = $this->legacyService()->matchLandmarks($database, $match, $playerId);
        $comeback = $this->recoveryService()->returnForMatch($database, $match, $playerId);
        $formerClubIds = is_array($postSummary['career_context']['former_club_ids'] ?? null) ? $postSummary['career_context']['former_club_ids'] : [];
        $fixtureContext = (new ClubFixtureContextService())->forMatch(
            $match,
            $competition,
            $this->seasonStakeForMatch($postSummary, $match->id()->value()),
            $formerClubIds,
            $controlledClubId,
        );

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
            'fixture_context' => $fixtureContext,
            'rival_context' => $this->services->playerModule()->service()->socialService()->matchContext($database, $match, $playerId),
            'performance' => $performance,
            'captain' => (bool) ($story['captain'] ?? false),
            'captain_label' => $story['captain_label'] ?? null,
            'set_piece_facts' => array_values(array_filter((array) ($story['player_highlight_facts'] ?? []), static fn (array $fact): bool => in_array($fact['kind'] ?? null, ['penalty_goal', 'penalty_missed'], true))),
            'timeline' => $story['timeline'],
            'highlights' => $this->storyLines($database, $match, $story, $playerId),
            'player_highlights' => $story['player_highlight_facts'],
            'team_highlights' => $story['timeline'],
            'rating_explanation' => $story['rating_explanation'],
            'decisive_contribution' => $story['decisive_contribution'],
            'player_of_match' => $story['player_of_match'],
            'landmark_callouts' => $landmarkCallouts,
            'comeback' => $comeback,
            'post_match' => [
                'recent_form' => $postSummary['recent_form'] ?? [],
                'season_stats' => $postSummary['season_stats'] ?? [],
                'season_performance' => $postSummary['season_performance'] ?? [],
                'readiness' => $postSummary['readiness'] ?? [],
                'manager_context' => $postSummary['manager_context'] ?? [],
                'club_position' => $postContext['position'] ?? null,
                'club_points' => $postContext['points'] ?? null,
                'career_impact' => $story['career_impact'],
                'comeback' => $comeback,
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
        $competitionRecord = $this->competitionRecord($database, (string) $competition['id']);
        $matchService = $this->services->matchModule()->service();
        $standings = [];
        if ($competitionRecord->type() === CompetitionType::DomesticLeague) {
            foreach ($matchService->standings($database, $competitionRecord->id(), $seasonId) as $row) {
                $standings[] = $row + [
                    'club' => $this->clubRecord($database, (string) $row['club_id'])->canonicalName(),
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
        if (($context['decision_kind'] ?? $decision['type'] ?? null) === 'retirement') {
            $legacy = $this->legacyService()->summary($database, (string) (($summary['player']['id'] ?? '')));
            $performance = is_array($context['performance'] ?? null) ? $context['performance'] : [];
            $finalClub = (string) ($context['final_club_id'] ?? '');
            $finalClubName = $finalClub === '' || $finalClub === 'free-agent' ? 'Free Agent' : $this->clubRecord($database, $finalClub)->canonicalName();
            $retirementOptions = array_values(array_map(static fn (array $option): array => ['id' => $option['id'] ?? null, 'label' => CareerLabels::value($option['id'] ?? null, 'Available choice'), 'club' => null, 'role' => null, 'wage' => null, 'current_wage' => null, 'term_seasons' => null, 'contract_end_date' => null, 'reasons' => [], 'club_level' => null, 'projected_role' => null, 'european_qualification' => false, 'market_path' => null, 'counter_available' => false], array_filter($options, 'is_array')));
            $choice = $this->decisionChoiceContext($summary, $context, 'retirement', $retirementOptions);
            return [
                'id' => $decision['id'] ?? null,
                'type' => $decision['type'] ?? null,
                'decision_kind' => 'retirement',
                'current_club' => $finalClubName,
                'current_competition' => null,
                'contract' => $this->contractText($summary['current_contract'] ?? null),
                'age' => $context['age'] ?? $summary['age'] ?? null,
                'career_phase' => $context['phase'] ?? $summary['career_phase'] ?? null,
                'role' => $context['role'] ?? $summary['current_role'] ?? null,
                'ovr' => $context['ovr'] ?? $summary['current_ovr'] ?? null,
                'performance' => $performance['classification'] ?? null,
                'career_stats' => $summary['career_stats'] ?? [],
                'honours' => count((array) ($legacy['honours'] ?? [])),
                'awards' => count((array) ($legacy['awards'] ?? [])),
                'counter_used' => false,
                'counter_response' => null,
                'options' => $choice['options'],
                'choice_context' => $choice['context'],
            ];
        }
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
                $club = $this->clubRecord($database, $clubId);
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
                'accept_loan' => 'Accept loan',
                'decline_loan' => 'Decline loan',
                'renew_current_club' => 'Renew with your current Club',
                'sign_with_club' => 'Join Club',
                'enter_free_agency' => 'Enter free agency',
                default => CareerLabels::value($kind, 'Available choice'),
            };
            $formatted[] = [
                'id' => $option['id'] ?? null,
                'kind' => $kind,
                'label' => $label,
                'club' => $clubView,
                'target_club_name' => $option['target_club_name'] ?? ($clubView['name'] ?? null),
                'role' => isset($option['role']) ? CareerLabels::value($option['role']) : null,
                'wage' => $option['wage'] ?? null,
                'current_wage' => $option['current_wage'] ?? null,
                'term_seasons' => $option['term_seasons'] ?? null,
                'contract_end_date' => $option['contract_end_date'] ?? null,
                'loan_end_date' => $option['loan_end_date'] ?? null,
                'parent_club_name' => $option['parent_club_name'] ?? null,
                'reasons' => array_values(array_map(static fn (mixed $reason): string => CareerLabels::value($reason), (array) ($option['reasons'] ?? []))),
                'club_level' => $option['target_club_level'] ?? null,
                'current_club_level' => $option['current_club_level'] ?? null,
                'current_competition' => $option['current_competition_name'] ?? null,
                'target_competition' => $option['target_competition_name'] ?? ($clubView['competition'] ?? null),
                'projected_role' => isset($option['projected_role']) ? CareerLabels::value($option['projected_role']) : (isset($option['role']) ? CareerLabels::value($option['role']) : null),
                'european_qualification' => $option['european_qualification'] ?? false,
                'current_european_qualification' => $option['current_european_qualification'] ?? false,
                'market_path' => isset($option['market_path']) ? CareerLabels::value($option['market_path']) : null,
                'journey_context' => $option['journey_context'] ?? null,
                'return_to_former_club' => $option['return_to_former_club'] ?? false,
                'attachment_label' => $option['attachment_label'] ?? null,
                'trade_offs' => array_values((array) ($option['trade_offs'] ?? [])),
                'counter_available' => in_array($kind, ['renew_current_club', 'sign_with_club'], true) && ($context['counter_used'] ?? false) !== true && (int) ($option['wage'] ?? 0) < 5000,
            ];
        }
        $currentClub = is_array($summary['current_club'] ?? null) ? ($summary['current_club']['name'] ?? null) : null;
        $currentCompetition = is_array($summary['current_competition'] ?? null) ? $summary['current_competition'] : null;
        $contextCurrentClubId = (string) ($context['current_club_id'] ?? $context['source_club_id'] ?? '');
        if ($currentClub === null && $contextCurrentClubId !== '' && $contextCurrentClubId !== 'free-agent') {
            $currentClubRecord = $this->clubRecord($database, $contextCurrentClubId);
            $currentClub = $currentClubRecord->canonicalName();
            if ($seasonId !== null) {
                $currentCompetitionRecord = $this->primaryCompetitionForClub($database, $contextCurrentClubId, $seasonId);
                if ($currentCompetitionRecord !== null) {
                    $currentCompetition = ['name' => $currentCompetitionRecord->name(), 'tier' => $currentCompetitionRecord->tier()];
                }
            }
        }

        $choice = $this->decisionChoiceContext($summary, $context, (string) ($context['decision_kind'] ?? $decision['type'] ?? ''), $formatted);

        return [
            'id' => $decision['id'] ?? null,
            'type' => $decision['type'] ?? null,
            'decision_kind' => $context['decision_kind'] ?? $decision['type'] ?? null,
            'current_club' => $currentClub,
            'current_competition' => $currentCompetition,
            'contract' => $this->contractText($summary['current_contract'] ?? null),
            'current_club_context' => is_array($context['current_club_context'] ?? null) ? $context['current_club_context'] : [],
            'career_context' => is_array($summary['career_context'] ?? null) ? $summary['career_context'] : [],
            'counter_used' => ($context['counter_used'] ?? false) === true,
            'counter_response' => $context['counter_response'] ?? null,
            'options' => $choice['options'],
            'choice_context' => $choice['context'],
        ];
    }

    /**
     * Project a decision into facts a Player can use before choosing. The
     * domain service still owns the option and its outcome; this projection
     * only labels known effects and keeps future selection/results explicit as
     * uncertain.
     *
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $context
     * @param list<array<string, mixed>> $options
     * @return array{context:array<string,mixed>,options:list<array<string,mixed>>}
     */
    public function decisionChoiceContext(array $summary, array $context, string $kind, array $options): array
    {
        $contract = is_array($summary['current_contract'] ?? null) ? $summary['current_contract'] : [];
        $currentClub = is_array($summary['current_club'] ?? null) ? $summary['current_club'] : [];
        $currentCompetition = is_array($summary['current_competition'] ?? null) ? $summary['current_competition'] : [];
        $current = [
            'club' => $currentClub['name'] ?? ($context['current_club_name'] ?? null),
            'competition' => $currentCompetition['name'] ?? ($context['current_competition_name'] ?? null),
            'role' => $context['current_role'] ?? $summary['current_role'] ?? null,
            'wage' => $context['current_wage'] ?? $contract['wage'] ?? null,
            'end_date' => $contract['end_date'] ?? null,
            'parent_club' => (($summary['active_loan']['parent_club']['name'] ?? null) ?: ($summary['parent_club']['name'] ?? null)),
        ];
        $known = [];
        $uncertain = [];
        $summaryText = 'The choice is based on the football situation recorded now.';
        if (in_array($kind, ['contract_boundary', 'contract_renewal', 'free_agent_contract'], true)) {
            $summaryText = ($current['club'] ?? null) === null
                ? 'You are choosing whether to take a Club offer or remain a free agent.'
                : 'Compare the available Contract terms with your current Club situation.';
            $known[] = 'The selected Contract terms, Club and recorded role are the facts shown on each offer.';
            $uncertain[] = 'Future selection, playing time and later offers are not guaranteed.';
        } elseif ($kind === 'controlled_transfer') {
            $summaryText = 'This choice changes your Club chapter only if you accept a destination.';
            $known[] = 'The destination, competition, proposed role and Contract terms come from the current offer.';
            $uncertain[] = 'A proposed role does not guarantee selection or minutes after the move.';
        } elseif ($kind === 'controlled_loan') {
            $summaryText = 'A loan changes your playing Club temporarily while keeping the parent Contract in place.';
            $known[] = 'The parent Contract remains with the parent Club; the loan Club becomes the playing context until the recorded return date.';
            $uncertain[] = 'The projected role is not a guarantee of selection or playing time.';
        } elseif ($kind === 'retirement') {
            $summaryText = 'This Season boundary asks whether your playing Career continues.';
            $known[] = 'Continue playing keeps the active Career open; retiring closes the playing Career and preserves its record.';
            $uncertain[] = 'Continuing does not guarantee a future Contract, role or playing time.';
        }

        $projected = [];
        foreach ($options as $option) {
            if (!is_array($option)) { continue; }
            $optionKnown = [];
            $optionUncertain = [];
            $optionKind = (string) ($option['kind'] ?? '');
            $club = (string) ($option['target_club_name'] ?? (($option['club']['name'] ?? null) ?: ''));
            if ($kind === 'retirement') {
                if (($option['id'] ?? null) === 'retire') {
                    $optionKnown[] = 'Your playing Career will close if this choice is confirmed.';
                } else {
                    $optionKnown[] = 'Your playing Career remains active if this choice is confirmed.';
                }
            } elseif ($optionKind === 'stay') {
                $optionKnown[] = 'You remain with the current Club and its recorded role and Contract context.';
            } elseif ($optionKind === 'enter_free_agency') {
                $optionKnown[] = 'You enter free agency and will not have an active Club Contract.';
                $optionUncertain[] = 'A new Club offer is not guaranteed.';
            } elseif ($optionKind === 'accept_loan') {
                $optionKnown[] = 'Your playing Club becomes ' . ($club === '' ? 'the loan Club' : $club) . ' until the recorded return date.';
                $optionKnown[] = 'The parent Contract and wage remain with the parent Club.';
                $optionUncertain[] = 'The projected role remains subject to selection.';
            } elseif ($optionKind === 'decline_loan') {
                $optionKnown[] = 'You remain with the parent Club under the existing Contract.';
            } elseif ($optionKind === 'accept_transfer') {
                $optionKnown[] = 'Your active Club and Contract would change to the destination shown if the move completes.';
                $optionUncertain[] = 'The proposed role is not a guarantee of selection or minutes.';
            } elseif (in_array($optionKind, ['renew_current_club', 'sign_with_club'], true)) {
                $optionKnown[] = 'The selected Club, wage, term and recorded role are shown in this offer.';
                $optionUncertain[] = 'Future selection and development are not guaranteed by the Contract.';
            }
            $option['known_effects'] = array_values(array_unique($optionKnown));
            $option['uncertain_effects'] = array_values(array_unique($optionUncertain));
            $projected[] = $option;
        }

        return [
            'context' => [
                'kind' => $kind,
                'summary' => $summaryText,
                'known_effects' => array_values(array_unique($known)),
                'uncertain_effects' => array_values(array_unique($uncertain)),
                'comparison' => ['current' => $current],
            ],
            'options' => $projected,
        ];
    }

    /**
     * Turn a resolved opportunity into a concise factual outcome. This reads
     * the domain result; it never resolves an option or rolls a second result.
     * @param array<string, mixed> $opportunity
     */
    public function decisionOutcome(array $opportunity): string
    {
        $context = is_array($opportunity['context'] ?? null) ? $opportunity['context'] : [];
        $kind = (string) ($context['decision_kind'] ?? $opportunity['type'] ?? 'career');
        $result = (string) ($context['decision_result'] ?? $context['offer_status'] ?? 'resolved');
        $selectedId = (string) ($context['selected_option'] ?? $result);
        $selected = null;
        foreach ((array) ($context['options'] ?? []) as $option) {
            if (is_array($option) && (string) ($option['id'] ?? '') === $selectedId) {
                $selected = $option;
                break;
            }
        }
        $selectedKind = (string) ($selected['kind'] ?? '');
        $target = (string) ($selected['target_club_name'] ?? ($selected['club_name'] ?? ($selected['club']['name'] ?? ($context['target_club_name'] ?? ''))));
        $current = (string) ($context['current_club_name'] ?? ($context['source_club_name'] ?? 'your current Club'));
        if ($kind === 'retirement') {
            return ($context['decision_result'] ?? '') === 'retire'
                ? 'You retired from playing football. Your Career record remains available.'
                : 'You chose to continue playing. Your Career remains active.';
        }
        if ($kind === 'controlled_loan' || (string) ($opportunity['type'] ?? '') === 'loan') {
            return $result === 'accepted'
                ? 'Loan accepted: you are playing for ' . ($target === '' ? 'the loan Club' : $target) . '; your parent Contract remains with ' . ((string) ($selected['parent_club_name'] ?? $context['parent_club_name'] ?? 'the parent Club')) . '.'
                : 'Loan declined. You remain with the parent Club under the existing Contract.';
        }
        if ($kind === 'controlled_transfer') {
            if ($selectedKind === 'stay' || $result === 'stayed') {
                return 'You chose to stay with ' . ($current === '' ? 'your current Club' : $current) . '.';
            }
            return $result === 'completed'
                ? 'Transfer completed: you moved from ' . $current . ' to ' . ($target === '' ? 'the destination Club' : $target) . '.'
                : 'Transfer opportunity declined. You remain with ' . $current . '.';
        }
        if ($kind === 'transfer_interest' || (string) ($opportunity['type'] ?? '') === 'transfer_interest') {
            return $result === 'completed'
                ? 'Transfer completed: you moved from ' . $current . ' to ' . ($target === '' ? 'the destination Club' : $target) . '.'
                : 'Transfer opportunity declined. You remain with ' . $current . '.';
        }
        if (in_array($kind, ['contract_boundary', 'contract_renewal', 'free_agent_contract'], true)) {
            if ($selectedKind === 'enter_free_agency') {
                return 'You entered free agency. No new Club offer is guaranteed.';
            }
            return 'Contract accepted with ' . ($target === '' ? 'the selected Club' : $target) . '.';
        }
        return 'Career decision resolved.';
    }

    /**
     * Project significant resolved opportunities as bounded factual memory.
     * The opportunity record is the existing durable source; no new history
     * ledger is created. @return list<array<string, mixed>>
     */
    public function decisionHistory(DatabaseInterface $database, string $playerId, int $limit = 12): array
    {
        $history = [];
        foreach ((new CareerOpportunityRepository($database))->recentForPlayer(new PlayerId($playerId), $limit) as $opportunity) {
            if ($opportunity->status()->value === 'open') { continue; }
            $context = $opportunity->context();
            $kind = (string) ($context['decision_kind'] ?? $opportunity->type()->value);
            if (!in_array($kind, ['contract_boundary', 'contract_renewal', 'free_agent_contract', 'controlled_transfer', 'controlled_loan', 'transfer_interest', 'retirement'], true)) { continue; }
            $record = $opportunity->toArray();
            $outcome = $this->decisionOutcome($record);
            $selectedKind = '';
            $selectedId = (string) ($context['selected_option'] ?? '');
            foreach ((array) ($context['options'] ?? []) as $option) {
                if (is_array($option) && (string) ($option['id'] ?? '') === $selectedId) { $selectedKind = (string) ($option['kind'] ?? ''); break; }
            }
            $story = $outcome;
            // Accepted moves and retirement already have canonical movement or
            // Career-event records; keep the Home story from saying the same
            // fact twice while retaining the decision in Career History.
            if ((in_array($kind, ['controlled_transfer', 'transfer_interest'], true) && ($context['offer_status'] ?? '') === 'completed')
                || ($kind === 'controlled_loan' && ($context['offer_status'] ?? '') === 'accepted')
                || ($kind === 'retirement' && ($context['decision_result'] ?? '') === 'retire')) {
                $story = '';
            }
            $history[] = [
                'date' => (string) ($context['resolved_date'] ?? $opportunity->createdDate()->toIsoString()),
                'offer_date' => $opportunity->createdDate()->toIsoString(),
                'kind' => $kind,
                'status' => $opportunity->status()->value,
                'selected_kind' => $selectedKind,
                'outcome' => $outcome,
                'story' => $story,
            ];
        }

        usort($history, static fn (array $left, array $right): int => strcmp((string) $right['date'] . (string) $right['kind'], (string) $left['date'] . (string) $left['kind']));

        return array_slice($history, 0, $limit);
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
                $competition = $this->competitionRecord($database, $match->competitionId());
                $prefix = $competition->type() === CompetitionType::DomesticCup ? 'DOMESTIC CUP — ' : ($competition->type() === CompetitionType::Continental ? 'EUROPE — ' : ($competition->type() === CompetitionType::International ? 'INTERNATIONAL — ' : 'RESULT — '));
                $fixtureContext = (new ClubFixtureContextService())->forMatch($match, $competition);
                $contextPrefix = ($fixtureContext['display_label'] ?? null) === null ? '' : strtoupper((string) $fixtureContext['display_label']) . ' — ';
                $items[] = ['date' => $match->scheduledDate()->toIsoString(), 'headline' => $contextPrefix . $prefix . $this->teamName($database, $match->homeClubId()->value()) . ' ' . $result->homeGoals() . '-' . $result->awayGoals() . ' ' . $this->teamName($database, $match->awayClubId()->value()) . ' (' . $competition->name() . ')'];
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
        $review = $this->seasonReview($database, $summary, $seasonId);
        $season = is_array($review['season'] ?? null) ? $review['season'] : [];
        $overview = is_array($review['statistics'] ?? null) ? ($review['statistics']['totals'] ?? []) : [];
        $competitions = is_array($review['statistics'] ?? null) ? ($review['statistics']['competitions'] ?? []) : [];
        $journey = is_array($review['club_journey'] ?? null) ? $review['club_journey'] : [];
        $firstClub = is_array($journey[0] ?? null) ? ($journey[0]['club_name'] ?? null) : null;
        $firstCompetition = is_array($competitions[0] ?? null) ? ($competitions[0]['competition'] ?? null) : null;
        $performance = is_array($review['performance'] ?? null) ? $review['performance'] : [];
        $progression = is_array($review['progression'] ?? null) ? $review['progression'] : [];
        $roleHistory = (array) ($progression['role_history'] ?? []);
        $roleChange = null;
        if (count($roleHistory) > 1) {
            $roleChange = CareerLabels::value($roleHistory[0]['role'] ?? null) . ' -> ' . CareerLabels::value($roleHistory[array_key_last($roleHistory)]['role'] ?? null);
        }
        $legacy = is_array($review['achievements'] ?? null) ? $review['achievements'] : [];

        return array_replace($review, [
            // These aliases keep the existing CLI season-boundary surface
            // compatible while the graphical route consumes the richer read model.
            'season' => $season['label'] ?? null,
            'club' => $firstClub,
            'competition' => $firstCompetition,
            'position' => null,
            'stats' => $overview,
            'performance' => $performance['classification'] ?? null,
            'ovr_before' => $progression['ovr_before'] ?? null,
            'ovr_after' => $progression['ovr_after'] ?? null,
            'role_change' => $roleChange,
            'competition_stats' => $competitions,
            'cup_results' => $this->seasonCompetitionOutcomes((array) ($summary['cup_history'] ?? []), (string) ($season['id'] ?? '')),
            'europe_results' => $this->seasonCompetitionOutcomes((array) ($summary['europe_history'] ?? []), (string) ($season['id'] ?? '')),
            'international_stats' => $review['statistics']['international'] ?? [],
            'legacy_awards' => $legacy['awards'] ?? [],
            'legacy_honours' => $legacy['honours'] ?? [],
            'club_season' => $review['events']['objectives'][0] ?? null,
        ]);
    }

    /**
     * Read-only, Season-scoped Career review. The returned structure is a
     * projection over existing statistics, history, legacy and movement
     * owners; it deliberately contains no Season score or persisted summary.
     * @return array<string, mixed>
     */
    public function seasonReview(DatabaseInterface $database, array $summary, ?string $seasonId = null): array
    {
        $player = is_array($summary['player'] ?? null) ? $summary['player'] : [];
        $playerId = (string) ($player['id'] ?? '');
        $seasons = (new SeasonRepository($database))->all();
        $seasonMap = [];
        foreach ($seasons as $season) { $seasonMap[$season->id()->value()] = $season; }
        $currentSeasonId = (string) ($summary['current_season_id'] ?? '');
        $seasonId ??= $this->latestReviewSeasonId($seasons, $summary);
        $availableSeasons = [];
        foreach ($seasons as $season) {
            $hasEvidence = in_array($season->id()->value(), $this->reviewSeasonIds($summary), true);
            if ($hasEvidence || $season->id()->value() === $currentSeasonId) {
                $availableSeasons[] = ['id' => $season->id()->value(), 'label' => $season->label(), 'status' => $season->status()->value];
            }
        }
        usort($availableSeasons, static fn (array $left, array $right): int => strcmp((string) $left['id'], (string) $right['id']));
        $seasonRecord = $seasonId === null ? null : ($seasonMap[$seasonId] ?? null);
        if ($seasonRecord === null || $playerId === '') {
            return ['available' => false, 'season' => null, 'available_seasons' => $availableSeasons, 'persistence' => false];
        }

        $competitionStats = $this->reviewCompetitionStats($database, $playerId, $seasonRecord->id());
        $totals = $this->reviewTotals($competitionStats);
        $canonicalTotals = (new PlayerCareerStatisticsService())->seasonDetailed($database, $playerId, $seasonRecord->id());
        $reconciles = $competitionStats !== [];
        foreach (['appearances', 'starts', 'minutes', 'goals', 'assists'] as $field) {
            if ((int) ($totals[$field] ?? 0) !== (int) ($canonicalTotals[$field] ?? 0)) { $reconciles = false; }
        }
        $international = $this->services->nationalTeams()->playerStats($database, $playerId, $seasonRecord->id());
        $movement = $this->reviewSeasonRows((array) ($summary['movement_history'] ?? []), $seasonRecord);
        $contracts = $this->reviewContracts((array) ($summary['contract_history'] ?? []), $seasonRecord);
        $roles = $this->reviewSeasonRows((array) ($summary['role_history'] ?? []), $seasonRecord);
        $objectives = array_values(array_filter((array) ($summary['club_season_history'] ?? []), static fn (array $row): bool => (string) ($row['season_id'] ?? '') === $seasonRecord->id()->value()));
        $development = $this->reviewDateRows((array) ($summary['development_history'] ?? []), $seasonRecord);
        $legacy = is_array($summary['legacy'] ?? null) ? $summary['legacy'] : [];
        $awards = $this->reviewSeasonRows((array) ($legacy['awards'] ?? []), $seasonRecord);
        $honours = $this->reviewSeasonRows((array) ($legacy['honours'] ?? []), $seasonRecord);
        $records = $this->reviewSeasonRows((array) ($legacy['records'] ?? []), $seasonRecord);
        $milestones = $this->reviewSeasonRows((array) ($legacy['milestones'] ?? []), $seasonRecord);
        $injuries = $this->reviewDateRows((array) ($legacy['injury_comebacks'] ?? []), $seasonRecord, ['injury.start_date', 'medical_end_date', 'first_match_back.date']);
        $captaincy = $this->reviewSeasonRows((array) (($summary['captaincy']['history'] ?? [])), $seasonRecord);
        $performance = (new PlayerSeasonPerformanceService())->assess($database, $playerId, $seasonRecord->id())->toArray();
        unset($performance['score']);
        $positionHistory = $this->reviewDateRows((array) ($summary['position_history'] ?? []), $seasonRecord, ['occurred_date']);
        $leaderboards = $this->reviewLeaderboards($database, $competitionStats, $playerId, $seasonRecord);
        $clubJourney = $this->reviewClubJourney($database, $playerId, $seasonRecord, $competitionStats, $movement);
        $ovrBefore = $development[0]['before_ovr'] ?? null;
        $ovrAfter = $development === [] ? null : ($development[array_key_last($development)]['after_ovr'] ?? null);
        $current = $seasonRecord->id()->value() === $currentSeasonId;
        $highlights = $this->reviewHighlights($honours, $awards, $records, $milestones, $movement, $leaderboards, $injuries);
        $discipline = [
            'yellow_cards' => (int) ($totals['yellow_cards'] ?? 0),
            'red_cards' => (int) ($totals['red_cards'] ?? 0),
            'suspensions' => [],
        ];
        $roleValues = [];
        foreach ($roles as $role) {
            $value = (string) ($role['role'] ?? '');
            if ($value !== '' && !in_array($value, $roleValues, true)) { $roleValues[] = $value; }
        }

        return [
            'available' => true,
            'persistence' => false,
            'season' => ['id' => $seasonRecord->id()->value(), 'label' => $seasonRecord->label(), 'status' => $seasonRecord->status()->value, 'completed' => $seasonRecord->status()->value === 'completed', 'current' => $current],
            'current_season_id' => $currentSeasonId,
            'available_seasons' => $availableSeasons,
            'player' => ['id' => $playerId, 'name' => (string) ($player['preferred_name'] ?? $player['name'] ?? 'Player')],
            'club_journey' => $clubJourney,
            'contract_context' => ['parent_club' => $summary['parent_club'] ?? null, 'contracts' => $contracts],
            'statistics' => ['competitions' => $competitionStats, 'totals' => $totals, 'international' => $international, 'reconciles' => $reconciles],
            'performance' => ['classification' => $performance['classification'] ?? 'insufficient_evidence', 'reason' => $performance['reason'] ?? null, 'statistics' => $performance['statistics'] ?? []],
            'progression' => ['development' => $development, 'ovr_before' => $ovrBefore, 'ovr_after' => $ovrAfter, 'role_history' => $roles, 'roles' => $roleValues, 'position_history' => $positionHistory, 'position_changes' => $positionHistory, 'position_summary' => null],
            'events' => ['movements' => $movement, 'loans' => array_values(array_filter($movement, static fn (array $row): bool => ($row['type'] ?? '') === 'loan')), 'injuries' => $injuries, 'discipline' => $discipline, 'captaincy' => $captaincy, 'objectives' => $objectives, 'set_pieces' => [], 'set_piece_review' => 'omitted'],
            'achievements' => ['honours' => $honours, 'awards' => $awards, 'records' => $records, 'personal_bests' => $records, 'milestones' => $milestones, 'leaderboards' => $leaderboards],
            'highlights' => $highlights,
            'outlook' => $current ? ($summary['career_outlook'] ?? null) : null,
            'source' => 'canonical Season aggregates, Career history, movement, legacy and competition leaderboards',
        ];
    }

    /** @param list<object> $seasons @param array<string,mixed> $summary */
    private function latestReviewSeasonId(array $seasons, array $summary): ?string
    {
        $historyIds = array_fill_keys($this->reviewSeasonIds($summary), true);
        $completed = array_values(array_filter($seasons, static fn ($season): bool => $season->status()->value === 'completed' && isset($historyIds[$season->id()->value()])));
        usort($completed, static fn ($left, $right): int => strcmp($right->endDate()->toIsoString() . $right->id()->value(), $left->endDate()->toIsoString() . $left->id()->value()));
        if ($completed !== []) { return $completed[0]->id()->value(); }
        return null;
    }

    /** @param array<string,mixed> $summary @return list<string> */
    private function reviewSeasonIds(array $summary): array
    {
        $ids = [];
        foreach ((array) ($summary['season_history'] ?? []) as $row) {
            if (is_array($row) && (string) ($row['season_id'] ?? '') !== '') { $ids[(string) $row['season_id']] = true; }
        }
        foreach ((array) ($summary['movement_history'] ?? []) as $row) {
            if (is_array($row) && (string) ($row['season_id'] ?? '') !== '') { $ids[(string) $row['season_id']] = true; }
        }
        foreach ((array) (($summary['legacy'] ?? [])['awards'] ?? []) as $row) {
            if (is_array($row) && (string) ($row['season_id'] ?? '') !== '') { $ids[(string) $row['season_id']] = true; }
        }

        return array_keys($ids);
    }

    /** @param array<string,mixed> $row */
    private function reviewRowDate(array $row, string $path = 'date'): string
    {
        $value = $row;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value)) { return ''; }
            $value = $value[$part] ?? null;
        }

        return is_string($value) ? $value : '';
    }

    /** @param list<mixed> $rows @param object $season @return list<array<string,mixed>> */
    private function reviewDateRows(array $rows, object $season, array $paths = ['date']): array
    {
        $result = [];
        $start = $season->startDate()->toIsoString();
        $end = $season->endDate()->toIsoString();
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $matches = false;
            foreach ($paths as $path) {
                $date = $this->reviewRowDate($row, $path);
                if ($date !== '' && strcmp($date, $start) >= 0 && strcmp($date, $end) <= 0) { $matches = true; break; }
            }
            if ($matches) { $result[] = $row; }
        }
        usort($result, fn (array $left, array $right): int => strcmp($this->reviewRowDate($left, 'date') . $this->reviewRowDate($left, 'occurred_date'), $this->reviewRowDate($right, 'date') . $this->reviewRowDate($right, 'occurred_date')) ?: strcmp((string) ($left['source_key'] ?? ''), (string) ($right['source_key'] ?? '')));

        return $result;
    }

    /** @param list<mixed> $rows @param object $season @return list<array<string,mixed>> */
    private function reviewSeasonRows(array $rows, object $season): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $rowSeason = (string) ($row['season_id'] ?? '');
            if ($rowSeason !== '') {
                if ($rowSeason === $season->id()->value()) { $result[] = $row; }
                continue;
            }
            $result = array_merge($result, $this->reviewDateRows([$row], $season));
        }
        usort($result, static fn (array $left, array $right): int => strcmp((string) ($left['date'] ?? $left['occurred_date'] ?? $left['award_date'] ?? ''), (string) ($right['date'] ?? $right['occurred_date'] ?? $right['award_date'] ?? '')) ?: strcmp((string) ($left['source_key'] ?? ''), (string) ($right['source_key'] ?? '')));

        return $result;
    }

    /** @param list<array<string,mixed>> $rows @param object $season @return list<array<string,mixed>> */
    private function reviewContracts(array $rows, object $season): array
    {
        $result = [];
        $start = $season->startDate()->toIsoString();
        $end = $season->endDate()->toIsoString();
        foreach ($rows as $row) {
            $contractStart = (string) ($row['start_date'] ?? '');
            $contractEnd = (string) ($row['end_date'] ?? '');
            if ($contractStart !== '' && $contractEnd !== '' && $contractStart <= $end && $contractEnd >= $start) { $result[] = $row; }
        }
        usort($result, static fn (array $left, array $right): int => strcmp((string) ($left['start_date'] ?? ''), (string) ($right['start_date'] ?? '')));

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function reviewCompetitionStats(DatabaseInterface $database, string $playerId, SeasonId $seasonId): array
    {
        $result = [];
        $query = new CompetitionStatisticsQuery();
        foreach ((new CompetitionRepository($database))->bySeason($seasonId) as $competition) {
            if ($competition->type() === CompetitionType::International) { continue; }
            $rows = array_values(array_filter($query->forCompetitionSeason($database, $competition->id()->value(), $seasonId), static fn (array $row): bool => (string) ($row['player_id'] ?? '') === $playerId));
            if ($rows === []) { continue; }
            $clubs = [];
            $stats = $this->reviewTotals($rows);
            foreach ($rows as $row) {
                $clubId = (string) ($row['club_id'] ?? '');
                if ($clubId === '') { continue; }
                $clubs[$clubId] = ['club_id' => $clubId, 'club_name' => $this->reviewClubName($database, $clubId), 'stats' => $this->reviewTotals([$row])];
            }
            ksort($clubs, SORT_STRING);
            $result[] = ['competition_id' => $competition->id()->value(), 'competition' => $competition->name(), 'type' => $competition->type()->value, 'clubs' => array_values($clubs), 'stats' => $stats];
        }
        usort($result, static fn (array $left, array $right): int => (($left['type'] <=> $right['type']) ?: strcmp((string) $left['competition_id'], (string) $right['competition_id'])));

        return $result;
    }

    /** @param list<array<string,mixed>> $rows @return array<string,int|float|null> */
    private function reviewTotals(array $rows): array
    {
        $fields = ['appearances', 'starts', 'minutes', 'goals', 'assists', 'shots', 'shots_on_target', 'saves', 'clean_sheets', 'tackles', 'interceptions', 'blocks', 'passes_attempted', 'passes_completed', 'fouls_committed', 'yellow_cards', 'red_cards', 'rated_appearances'];
        $result = array_fill_keys($fields, 0);
        $ratingTotal = 0.0;
        foreach ($rows as $row) {
            $source = is_array($row['stats'] ?? null) ? $row['stats'] : $row;
            foreach ($fields as $field) { $result[$field] += (int) ($source[$field] ?? 0); }
            $ratingTotal += (float) ($source['rating_total'] ?? 0);
        }
        $result['average_match_rating'] = (int) $result['rated_appearances'] === 0 ? null : round($ratingTotal / (int) $result['rated_appearances'], 2);

        return $result;
    }

    private function reviewClubName(DatabaseInterface $database, string $clubId): string
    {
        $repository = $this->services->clubModule()->service()->repository($database);

        return $repository->exists($clubId) ? $repository->get($clubId)->canonicalName() : $clubId;
    }

    /** @param list<array<string,mixed>> $competitionStats @return list<array<string,mixed>> */
    private function reviewLeaderboards(DatabaseInterface $database, array $competitionStats, string $playerId, object $season): array
    {
        $result = [];
        foreach ($competitionStats as $competition) {
            $projection = $this->competitionLeaderboards($database, (string) $competition['competition_id'], new SeasonId($season->id()->value()), $playerId);
            foreach ((array) ($projection['categories'] ?? []) as $category) {
                if (!is_array($category) || !is_array($category['controlled'] ?? null)) { continue; }
                $controlled = $category['controlled'];
                $result[] = ['competition_id' => $competition['competition_id'], 'competition' => $competition['competition'], 'category' => $category['key'] ?? null, 'position' => $controlled['position'] ?? null, 'value' => $controlled['value'] ?? 0, 'leader_value' => $controlled['leader_value'] ?? null, 'gap_to_leader' => $controlled['gap_to_leader'] ?? null, 'final' => $season->status()->value === 'completed'];
            }
        }
        usort($result, static fn (array $left, array $right): int => strcmp((string) $left['competition_id'] . ':' . (string) $left['category'], (string) $right['competition_id'] . ':' . (string) $right['category']));

        return $result;
    }

    /** @param list<array<string,mixed>> $competitionStats @param list<array<string,mixed>> $movement @return list<array<string,mixed>> */
    private function reviewClubJourney(DatabaseInterface $database, string $playerId, object $season, array $competitionStats, array $movement): array
    {
        $clubs = [];
        $add = static function (array &$clubs, string $id, string $name, string $kind): void {
            if ($id === '') { return; }
            $clubs[$id] ??= ['club_id' => $id, 'club_name' => $name, 'kind' => $kind];
            if (($clubs[$id]['kind'] ?? 'club') === 'club' && $kind !== 'club') { $clubs[$id]['kind'] = $kind; }
        };
        usort($movement, static fn (array $left, array $right): int => strcmp((string) ($left['date'] ?? ''), (string) ($right['date'] ?? '')) ?: strcmp((string) ($left['type'] ?? ''), (string) ($right['type'] ?? '')));
        foreach ($movement as $event) {
            $type = (string) ($event['type'] ?? '');
            $from = (string) ($event['from_club_id'] ?? '');
            $to = (string) ($event['to_club_id'] ?? '');
            if ($from !== '') { $add($clubs, $from, (string) ($event['from_club'] ?? $from), $type === 'loan' ? 'parent' : 'club'); }
            if ($to !== '') { $add($clubs, $to, (string) ($event['to_club'] ?? $to), $type === 'loan' ? 'loan' : 'club'); }
        }
        foreach ((new ClubSquadRepository($database, false))->byPlayer($playerId, new SeasonId($season->id()->value())) as $membership) {
            $id = $membership->clubId()->value();
            $add($clubs, $id, $this->reviewClubName($database, $id), 'club');
        }
        foreach ($competitionStats as $competition) {
            foreach ((array) ($competition['clubs'] ?? []) as $club) {
                if (is_array($club) && (string) ($club['club_id'] ?? '') !== '') { $add($clubs, (string) $club['club_id'], (string) ($club['club_name'] ?? $club['club_id']), 'club'); }
            }
        }

        return array_values($clubs);
    }

    /** @param list<array<string,mixed>> ...$groups @return list<array<string,mixed>> */
    private function reviewHighlights(array ...$groups): array
    {
        $highlights = [];
        foreach ($groups as $group) {
            foreach ($group as $row) {
                if (!is_array($row)) { continue; }
                $label = (string) ($row['label'] ?? '');
                if ($label === '' && ($row['type'] ?? '') === 'loan') { $label = 'Loan spell: ' . (string) ($row['from_club'] ?? 'Club') . ' to ' . (string) ($row['to_club'] ?? 'Club'); }
                if ($label === '' && ($row['type'] ?? '') === 'transfer') { $label = 'Transfer: ' . (string) ($row['from_club'] ?? 'Club') . ' to ' . (string) ($row['to_club'] ?? 'Club'); }
                if ($label === '' && isset($row['category'], $row['position'])) { $label = (string) $row['category'] . ' position ' . (string) $row['position'] . ' in ' . (string) ($row['competition'] ?? 'competition'); }
                if ($label === '') { continue; }
                $key = (string) ($row['source_key'] ?? $row['loan_id'] ?? $row['category'] ?? $label);
                $highlights[$key] = ['source_key' => $key, 'label' => $label, 'date' => (string) ($row['date'] ?? $row['occurred_date'] ?? $row['award_date'] ?? '')];
                if (count($highlights) >= 8) { break 2; }
            }
        }

        return array_values($highlights);
    }

    /** @return list<array{competition_id:string,competition:string,type:string,stats:array<string,int|float|null>}> */
    private function seasonCompetitionStats(DatabaseInterface $database, string $playerId, SeasonId $seasonId): array
    {
        $matches = new MatchRepository($database);
        $stats = new PlayerMatchStatRepository($database);
        $competitions = new CompetitionRepository($database);
        $ids = [];
        $playerStats = $stats->byPlayer(new PlayerId($playerId));
        $matchesById = [];
        foreach ($matches->byIds(array_map(static fn ($stat): string => $stat->matchId()->value(), $playerStats)) as $match) {
            $matchesById[$match->id()->value()] = $match;
        }
        $competitionsById = [];
        foreach ($competitions->bySeason($seasonId) as $competition) {
            $competitionsById[$competition->id()->value()] = $competition;
        }
        foreach ($playerStats as $stat) {
            if (!$stat->appeared()) { continue; }
            $match = $matchesById[$stat->matchId()->value()] ?? null;
            if ($match === null) { continue; }
            if ($match->seasonId()->value() !== $seasonId->value()) { continue; }
            $competition = $competitionsById[$match->competitionId()->value()] ?? $competitions->get($match->competitionId());
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
        $objectiveOutcome = null;
        foreach ((array) ($summary['club_season_history'] ?? []) as $objectiveRow) {
            if (is_array($objectiveRow) && ($objectiveRow['season_id'] ?? null) === $previousSeasonId) {
                $objectiveOutcome = $objectiveRow;
                break;
            }
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
            'club_objective_outcome' => $objectiveOutcome,
            'next_club_season' => $summary['club_season'] ?? null,
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

    private function clubRecord(DatabaseInterface $database, string|ClubId $clubId): Club
    {
        $id = $clubId instanceof ClubId ? $clubId->value() : $clubId;
        $connection = $database->connection();
        $records = $this->clubReadCache[$connection] ?? [];
        if (!isset($records[$id])) {
            $records[$id] = $this->services->clubModule()->service()->repository($database)->get($id);
            $this->clubReadCache[$connection] = $records;
        }

        return $records[$id];
    }

    private function competitionRecord(DatabaseInterface $database, string|CompetitionId $competitionId): Competition
    {
        $id = $competitionId instanceof CompetitionId ? $competitionId->value() : $competitionId;
        $connection = $database->connection();
        $records = $this->competitionReadCache[$connection] ?? [];
        if (!isset($records[$id])) {
            $records[$id] = (new CompetitionRepository($database))->get($id);
            $this->competitionReadCache[$connection] = $records;
        }

        return $records[$id];
    }

    private function recoveryService(): CareerRecoveryService
    {
        return new CareerRecoveryService();
    }

    /** @param array<string, mixed> $legacy @param list<array<string, mixed>> $movement @return array<string, mixed> */
    private function appendMovementLandmarks(array $legacy, array $movement): array
    {
        $timeline = array_values(array_filter((array) ($legacy['career_timeline'] ?? []), 'is_array'));
        foreach ($movement as $event) {
            if (!is_array($event) || !in_array((string) ($event['type'] ?? ''), ['club_promoted', 'club_relegated'], true)) { continue; }
            $promoted = (string) $event['type'] === 'club_promoted';
            $club = is_array($event['club'] ?? null) ? (string) ($event['club']['name'] ?? 'Club') : 'Club';
            $timeline[] = ['source_key' => 'movement|' . (string) ($event['type'] ?? '') . '|' . (string) ($event['season_id'] ?? '') . '|' . $club, 'date' => (string) ($event['date'] ?? ''), 'title' => ($promoted ? 'Promotion with ' : 'Relegation with ') . $club, 'description' => 'Club trajectory recorded from canonical Season history.', 'kind' => 'club', 'importance' => 'major', 'season_id' => (string) ($event['season_id'] ?? '')];
        }
        $importance = ['routine' => 0, 'notable' => 1, 'major' => 2, 'landmark' => 3];
        $unique = [];
        foreach ($timeline as $item) { $key = (string) ($item['source_key'] ?? ''); if ($key !== '') { $unique[$key] = $item; } }
        $timeline = array_values($unique);
        usort($timeline, static fn (array $left, array $right): int => strcmp((string) ($left['date'] ?? ''), (string) ($right['date'] ?? '')) ?: (($importance[(string) ($right['importance'] ?? 'routine')] ?? 0) <=> ($importance[(string) ($left['importance'] ?? 'routine')] ?? 0)) ?: strcmp((string) ($left['source_key'] ?? ''), (string) ($right['source_key'] ?? '')));
        $legacy['career_timeline'] = array_slice($timeline, 0, 30);
        $legacy['career_landmarks'] = array_slice(array_values(array_filter($legacy['career_timeline'], static fn (array $item): bool => (string) ($item['importance'] ?? 'routine') !== 'routine')), 0, 20);

        return $legacy;
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

        $text = $end === '' ? $status : $status . ' through ' . $end;
        if (array_key_exists('wage', $contract) && $contract['wage'] !== null) {
            $text .= ' · GC ' . number_format((int) $contract['wage']) . '/week';
        }

        return $text;
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
                'penalty_missed' => 'PENALTY MISSED',
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
            $lines[] = $highlight->minute() . "' " . (($highlight->type() === 'goal' && ($data['set_piece'] ?? null) === 'penalty') ? 'PENALTY GOAL' : $type) . ' — ' . $actor . $suffix;
        }

        return $lines;
    }

    /** @param array<string, mixed> $story @return list<string> */
    private function storyLines(DatabaseInterface $database, GameMatch $match, array $story, string $playerId): array
    {
        $players = new PlayerRepository($database);
        $lines = [];
        $timeline = is_array($story['timeline'] ?? null) ? $story['timeline'] : [];
        $playerIds = [];
        foreach ($timeline as $event) {
            foreach ([$event['player_id'] ?? null, $event['assist_player_id'] ?? null] as $candidate) {
                if (is_string($candidate) && $candidate !== '') { $playerIds[] = $candidate; }
            }
            foreach (['incoming_player_id', 'outgoing_player_id'] as $key) {
                $candidate = is_array($event['data'] ?? null) ? ($event['data'][$key] ?? null) : null;
                if (is_string($candidate) && $candidate !== '') { $playerIds[] = $candidate; }
            }
        }
        $playersById = [];
        foreach ($players->byIds(array_values(array_unique($playerIds))) as $record) { $playersById[$record->id()->value()] = $record->preferredName(); }
        $clubNames = [
            $match->homeClubId()->value() => $this->teamName($database, $match->homeClubId()->value()),
            $match->awayClubId()->value() => $this->teamName($database, $match->awayClubId()->value()),
        ];
        foreach ($timeline as $event) {
            $type = (string) ($event['type'] ?? '');
            $minute = (int) ($event['minute'] ?? 0);
            $clubId = (string) ($event['club_id'] ?? '');
            $clubName = $clubNames[$clubId] ?? 'Club';
            $playerIdForEvent = is_string($event['player_id'] ?? null) ? $event['player_id'] : null;
            $playerName = $playerIdForEvent === null ? null : ($playersById[$playerIdForEvent] ?? 'Player');
            if ($type === 'goal') {
                $assistId = is_string($event['assist_player_id'] ?? null) ? $event['assist_player_id'] : null;
                $assist = $assistId === null ? null : ($playersById[$assistId] ?? 'Player');
                $score = (array) ($event['score_after'] ?? []);
                $scoreText = (int) ($score['home'] ?? 0) . '-' . (int) ($score['away'] ?? 0);
                $lead = ((string) ($event['importance'] ?? '') === 'decisive') ? ' decisively' : '';
                $setPiece = ($event['data']['set_piece'] ?? null) === 'penalty' ? ' PENALTY' : '';
                $variants = [
                    $minute . "' " . ($playerName ?? $clubName) . ' scores' . $setPiece . ' for ' . $clubName . $lead . ' — ' . $scoreText,
                    $minute . "'" . $setPiece . ' GOAL — ' . ($playerName ?? $clubName) . ' (' . $clubName . ')' . ($assist === null ? '' : ' assisted by ' . $assist) . ' — ' . $scoreText,
                ];
                $line = $variants[hexdec(substr(hash('sha256', 'match-commentary:v1|' . $match->id()->value() . '|' . (string) ($event['sequence'] ?? 0)), 0, 2)) % count($variants)];
                if ($assist !== null && !str_contains($line, 'assisted by')) { $line .= ' — assisted by ' . $assist; }
                $lines[] = $line;
            } elseif ($type === 'substitution') {
                $data = (array) ($event['data'] ?? []);
                $incomingId = (string) ($data['incoming_player_id'] ?? $playerIdForEvent ?? '');
                $outgoingId = (string) ($data['outgoing_player_id'] ?? '');
                $incoming = $incomingId === '' ? 'Player' : ($playersById[$incomingId] ?? 'Player');
                $outgoing = $outgoingId === '' ? 'Player' : ($playersById[$outgoingId] ?? 'Player');
                $lines[] = $minute . "' " . ($incomingId === $playerId ? 'YOU ENTER THE MATCH' : ($outgoingId === $playerId ? 'YOU LEAVE THE MATCH' : 'SUBSTITUTION')) . ' — ' . $incoming . ' on for ' . $outgoing . ' (' . $clubName . ')';
            } elseif ($type === 'yellow_card' || $type === 'red_card') {
                $label = $type === 'red_card' ? 'RED CARD — SENT OFF' : 'YELLOW CARD';
                $lines[] = $minute . "' " . $label . ' — ' . ($playerName ?? $clubName) . ' (' . $clubName . ')';
            } elseif ($type === 'penalty_missed') {
                $lines[] = $minute . "' PENALTY MISSED — " . ($playerName ?? $clubName) . ' (' . $clubName . ')';
            }
        }
        foreach ((array) ($story['player_highlight_facts'] ?? []) as $fact) {
            if (!is_array($fact)) { continue; }
            $kind = (string) ($fact['kind'] ?? '');
            $text = match ($kind) {
                'saves' => 'YOU make ' . (int) ($fact['count'] ?? 0) . ' saves',
                'shots_on_target' => 'YOU record ' . (int) ($fact['count'] ?? 0) . ' shot' . ((int) ($fact['count'] ?? 0) === 1 ? '' : 's') . ' on target',
                'defending' => 'YOU contribute ' . (int) ($fact['tackles'] ?? 0) . ' tackles, ' . (int) ($fact['interceptions'] ?? 0) . ' interceptions and ' . (int) ($fact['blocks'] ?? 0) . ' blocks',
                'passing' => 'YOU complete ' . (int) ($fact['completed'] ?? 0) . '/' . (int) ($fact['attempted'] ?? 0) . ' passes',
                'clean_sheet' => 'YOU help keep a clean sheet',
                'assist' => 'YOU assist the goal at ' . (int) ($fact['minute'] ?? 0) . "'",
                default => null,
            };
            if ($text !== null && !in_array($text, $lines, true)) { $lines[] = $text; }
        }

        return array_slice($lines, 0, 8);
    }

    private function fixtureText(DatabaseInterface $database, GameMatch $match, string $controlledClubId): string
    {
        $competition = $this->competitionRecord($database, $match->competitionId());
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
        $fixtureContext = (new ClubFixtureContextService())->forMatch($match, $competition);
        if (($fixtureContext['display_label'] ?? null) !== null) {
            $round .= ' · ' . (string) $fixtureContext['display_label'];
        }

        return $mark . $match->scheduledDate()->toIsoString() . ': ' . $fixture . ' (' . $competition->name() . $round . ')';
    }

    private function teamName(DatabaseInterface $database, string $teamId): string
    {
        if ($this->services->nationalTeams()->isNationalTeam($teamId)) {
            return $this->services->nationalTeams()->displayName($database, $teamId);
        }

        return $this->clubRecord($database, $teamId)->canonicalName();
    }

    /** @param array<string, mixed> $view */
    private function fixtureTextFromView(array $view): string
    {
        $round = ($view['round'] ?? null) === null ? '' : ' · ' . (string) $view['round'];
        if (is_array($view['fixture_context'] ?? null) && ($view['fixture_context']['display_label'] ?? null) !== null) {
            $round .= ' · ' . (string) $view['fixture_context']['display_label'];
        }
        return (string) ($view['date'] ?? 'Date unavailable') . ': ' . (string) ($view['home_club'] ?? 'Home') . ' vs ' . (string) ($view['away_club'] ?? 'Away') . ' (' . (string) ($view['competition'] ?? 'Competition') . $round . ')';
    }

    /** @return array<string,mixed>|null */
    private function seasonStakeForMatch(array $summary, string $matchId): ?array
    {
        foreach ((array) (($summary['club_season']['important_fixtures'] ?? [])) as $fixture) {
            if (is_array($fixture) && (string) ($fixture['match_id'] ?? '') === $matchId) {
                return $fixture;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $discipline @return array{code:string,label:string,explanation:string} */
    private function preMatchAvailability(\Goal\Legacy\Modules\Player\Domain\AvailabilityAssessment $assessment, array $discipline): array
    {
        if (($discipline['eligible'] ?? true) !== true) {
            return [
                'code' => 'suspended',
                'label' => 'Suspended',
                'explanation' => (string) ($discipline['reason_label'] ?? 'A competition suspension blocks selection for this fixture.'),
            ];
        }
        if ($assessment->injury() !== null) {
            return ['code' => 'injured', 'label' => 'Injured', 'explanation' => $assessment->readinessDescription()];
        }
        if ($assessment->isUnavailable()) {
            return ['code' => 'unavailable', 'label' => 'Unavailable', 'explanation' => $assessment->readinessDescription()];
        }
        if ($assessment->isLimited()) {
            return ['code' => 'limited', 'label' => 'Limited', 'explanation' => $assessment->readinessDescription()];
        }

        return ['code' => 'available', 'label' => 'Available', 'explanation' => $assessment->readinessDescription()];
    }

    /** @param array<string, mixed> $form */
    private function preMatchFormLabel(array $form): string
    {
        if ((int) ($form['rated_appearances'] ?? 0) < 2 || ($form['classification'] ?? 'insufficient_evidence') === 'insufficient_evidence') {
            return 'Not enough matches yet';
        }

        return CareerLabels::value($form['classification'] ?? null, 'Not available');
    }

    private function developmentSourceLabel(string $source): string
    {
        return match ($source) {
            'training' => 'Training block',
            'match' => 'Match evidence',
            'season_lifecycle' => 'Season transition',
            default => 'Recorded development',
        };
    }

    /** @param array<string, mixed> $summary @return array{code:string,label:string,description:string,phase:string} */
    private function careerStage(array $summary): array
    {
        $phase = (string) ($summary['career_phase'] ?? '');

        return match ($phase) {
            'retired' => ['code' => 'career_complete', 'label' => 'Career Complete', 'description' => 'The playing Career is complete; the final record remains available.', 'phase' => $phase],
            'youth' => ['code' => 'early_career', 'label' => 'Early Career', 'description' => 'Building the first senior evidence of the Career.', 'phase' => $phase],
            'development' => ['code' => 'establishing', 'label' => 'Establishing', 'description' => 'Building a dependable senior role through football evidence.', 'phase' => $phase],
            'prime', 'experienced' => ['code' => 'established', 'label' => 'Established', 'description' => 'An established Career stage; current role and evidence describe the present chapter.', 'phase' => $phase],
            'veteran', 'decline' => ['code' => 'veteran', 'label' => 'Veteran', 'description' => 'A veteran Career stage; current form and role determine whether the chapter is holding or changing.', 'phase' => $phase],
            default => ['code' => 'active_career', 'label' => 'Active Career', 'description' => 'The current Career stage is derived from the recorded lifecycle state.', 'phase' => $phase],
        };
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
