<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Devtools;

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Devtools\Presentation\CareerFormatter;
use Goal\Legacy\Devtools\Presentation\CareerPresentationService;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PHPUnit\Framework\TestCase;

final class CareerPresentationTest extends TestCase
{
    public function testCareerHomeUsesOneDeterministicPriorityAndExplainsLimitedPlay(): void
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $presentation = new CareerPresentationService($services);
        $model = $presentation->homeContext([
            'player' => ['id' => 'player-1', 'preferred_name' => 'Alex Rivera', 'primary_position' => 'CM'],
            'career_state' => 'active', 'current_role' => 'rotation', 'current_ovr' => 82,
            'current_club' => ['name' => 'Arsenal'], 'current_competition' => ['name' => 'Premier League'],
            'availability' => 'available', 'discipline' => [],
            'season_stats' => ['appearances' => 0, 'starts' => 0, 'minutes' => 0],
            'position_competition' => ['higher_ovr_count' => 2],
            'pending_decisions' => [['type' => 'contract_renewal']],
            'transfer_request' => ['status' => 'none'],
            'current_contract' => ['status' => 'active', 'club' => ['name' => 'Arsenal'], 'end_date' => '2025-12-31', 'wage' => 1200],
        ], ['match_id' => 'fixture-1', 'home_club' => 'Arsenal', 'away_club' => 'Chelsea'], SimulationDate::fromIsoString('2025-01-01'));

        self::assertSame('REQUIRED_DECISION', $model['next_up']['action']['priority']);
        self::assertSame('decision', $model['next_up']['action']['kind']);
        self::assertSame('Contract decision', $model['needs_attention'][0]['label']);
        self::assertStringContainsString('competition', strtolower($model['playing_status']['explanation']));
        self::assertSame(364, $model['contract']['remaining_days']);
    }

    public function testCareerHomeKeepsAvailabilityStatesAndMovementContextDistinct(): void
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $presentation = new CareerPresentationService($services);
        $base = [
            'player' => ['id' => 'player-1', 'preferred_name' => 'Alex Rivera', 'primary_position' => 'CM'],
            'career_state' => 'active', 'current_role' => 'regular', 'season_stats' => [],
            'current_club' => ['name' => 'Loan Club'], 'parent_club' => ['name' => 'Parent Club'],
            'current_contract' => ['status' => 'active', 'club' => ['name' => 'Parent Club'], 'end_date' => '2026-06-30', 'wage' => 1600],
            'active_loan' => ['loan_club' => ['name' => 'Loan Club'], 'parent_club' => ['name' => 'Parent Club']],
            'transfer_request' => ['status' => 'none'], 'pending_decisions' => [],
        ];
        $injured = $base + ['availability' => 'unavailable', 'active_injury' => ['recovery_date' => '2025-02-01'], 'injury_recovery' => ['message' => 'Rehabilitation continues.']];
        $suspended = $base + ['availability' => 'available', 'active_injury' => null, 'discipline' => ['active' => true]];
        $loan = $presentation->homeContext($base);
        $injury = $presentation->homeContext($injured);
        $ban = $presentation->homeContext($suspended);

        self::assertTrue($loan['movement']['active_loan']);
        self::assertSame('Loan Club', $loan['movement']['current_club']);
        self::assertSame('Parent Club', $loan['movement']['parent_club']);
        self::assertSame('injured', $injury['current_status']['code']);
        self::assertSame('suspended', $ban['current_status']['code']);
        self::assertNotSame($injury['current_status']['label'], $ban['current_status']['label']);
    }

    public function testCareerHomeRetiredStateRemovesActiveCareerPriority(): void
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $model = (new CareerPresentationService($services))->homeContext([
            'player' => ['id' => 'player-1', 'preferred_name' => 'Alex Rivera'],
            'career_state' => 'retired', 'career_phase' => 'retired', 'current_club' => null,
            'current_contract' => null, 'pending_decisions' => [], 'season_stats' => [],
        ], ['match_id' => 'fixture-1']);

        self::assertSame('legacy', $model['next_up']['action']['kind']);
        self::assertSame('retired', $model['current_status']['code']);
        self::assertTrue($model['header']['free_agent']);
        self::assertFalse(array_search('training', array_column($model['quick_links'], 'page'), true) !== false);
    }

    public function testCareerHomeFreeAgentSurfacesTheNextCareerStep(): void
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $model = (new CareerPresentationService($services))->homeContext([
            'player' => ['id' => 'player-1', 'preferred_name' => 'Alex Rivera'],
            'career_state' => 'active', 'current_club' => null, 'current_contract' => null,
            'pending_decisions' => [], 'season_stats' => [], 'availability' => 'available',
        ]);

        self::assertSame('market', $model['next_up']['action']['kind']);
        self::assertTrue($model['header']['free_agent']);
        self::assertStringContainsString('free agent', strtolower($model['next_up']['action']['why']));
    }

    public function testProgressionContextSeparatesCareerStageFromOutlookAndUsesRecordedDeltas(): void
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $model = (new CareerPresentationService($services))->progressionContext([
            'player' => ['attributes' => ['pace' => 72, 'shooting' => 68, 'passing' => 70, 'dribbling' => 71, 'defending' => 50, 'physicality' => 65], 'primary_position' => 'CM'],
            'current_ovr' => 66, 'current_role' => 'regular', 'career_phase' => 'prime', 'age' => 25,
            'career_outlook' => ['category' => 'steady_role'], 'training_focus' => 'passing',
            'development_history' => [
                ['id' => 'dev-1', 'date' => '2025-01-01', 'source' => 'training', 'before_ovr' => 65, 'after_ovr' => 66, 'attribute_deltas' => ['passing' => 1]],
                ['id' => 'dev-2', 'date' => '2025-02-01', 'source' => 'season_lifecycle', 'before_ovr' => 66, 'after_ovr' => 65, 'attribute_deltas' => ['physicality' => -1]],
            ],
            'role_history' => [
                ['role' => 'prospect', 'occurred_date' => '2024-08-01', 'season_id' => 'season-2024-25'],
                ['role' => 'rotation', 'occurred_date' => '2024-12-01', 'season_id' => 'season-2024-25'],
                ['role' => 'regular', 'occurred_date' => '2025-02-01', 'season_id' => 'season-2024-25'],
            ],
            'recent_playing_time' => ['window' => 5, 'appearances' => 4, 'starts' => 3, 'minutes' => 280],
            'latest_season_performance' => ['classification' => 'strong'],
            'recent_form' => ['classification' => 'good'],
        ]);

        self::assertSame('established', $model['career_stage']['code']);
        self::assertSame('steady_role', $model['outlook']['category']);
        self::assertSame(66, $model['current']['ovr']);
        self::assertSame(70, $model['current']['attributes']['passing']);
        self::assertSame('mixed', $model['feedback']['code']);
        self::assertSame('passing', $model['training']['focus']);
        self::assertSame('rotation', $model['role_change']['from']);
        self::assertSame('regular', $model['role_change']['to']);
        $deltas = [];
        foreach ($model['recent_changes'] as $change) {
            foreach ($change['attribute_changes'] as $attributeChange) {
                $deltas[] = $attributeChange['delta'];
            }
        }
        self::assertContains(1, $deltas);
        self::assertContains(-1, $deltas);
    }

    public function testProgressionContextUsesAnHonestEmptyState(): void
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $model = (new CareerPresentationService($services))->progressionContext([
            'player' => ['attributes' => ['pace' => 60, 'shooting' => 60, 'passing' => 60, 'dribbling' => 60, 'defending' => 60, 'physicality' => 60]],
            'current_ovr' => 60, 'career_phase' => 'development', 'current_role' => 'prospect',
            'development_history' => [], 'training_focus' => null,
        ]);

        self::assertSame('establishing', $model['career_stage']['code']);
        self::assertSame('no_recorded_change', $model['feedback']['code']);
        self::assertSame([], $model['recent_changes']);
        self::assertSame('No focus selected', $model['training']['focus_label']);
    }

    public function testCareerHomeStateMatrixKeepsActiveLimitedAndTransferContextReadable(): void
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $presentation = new CareerPresentationService($services);
        $base = [
            'player' => ['id' => 'player-1', 'preferred_name' => 'Alex Rivera'],
            'career_state' => 'active', 'current_club' => ['name' => 'Arsenal'],
            'current_contract' => ['status' => 'active', 'club' => ['name' => 'Arsenal'], 'end_date' => '2026-06-30'],
            'current_role' => 'regular', 'season_stats' => ['appearances' => 3, 'starts' => 2, 'minutes' => 190],
            'pending_decisions' => [], 'availability' => 'available', 'transfer_request' => ['status' => 'none'],
        ];
        $active = $presentation->homeContext($base, ['match_id' => 'fixture']);
        $limited = $presentation->homeContext(array_merge($base, ['availability' => 'limited', 'readiness' => ['label' => 'fatigued', 'description' => 'Managed recovery.']]), ['match_id' => 'fixture']);
        $requested = $presentation->homeContext(array_merge($base, ['transfer_request' => ['status' => 'requested']]), ['match_id' => 'fixture']);
        $retirement = $presentation->homeContext(array_merge($base, ['pending_decisions' => [['type' => 'retirement']]]), ['match_id' => 'fixture']);

        self::assertSame('available', $active['current_status']['code']);
        self::assertSame('MATCHDAY', $active['next_up']['action']['priority']);
        self::assertSame('limited', $limited['current_status']['code']);
        self::assertSame('requested', $requested['movement']['transfer_request']);
        self::assertSame('transfer_request', $requested['needs_attention'][0]['type']);
        self::assertSame('decision', $retirement['next_up']['action']['kind']);
        self::assertSame('REQUIRED_DECISION', $retirement['next_up']['action']['priority']);
    }

    public function testCareerDecisionHubKeepsContractRequestOutlookAndLoanStatesDistinct(): void
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $presentation = new CareerPresentationService($services);
        $stable = [
            'player' => ['id' => 'player-1', 'preferred_name' => 'Alex Rivera'],
            'career_state' => 'active', 'current_role' => 'regular',
            'current_club' => ['name' => 'Arsenal'],
            'current_contract' => ['status' => 'active', 'club' => ['name' => 'Arsenal'], 'end_date' => '2026-06-30'],
            'transfer_request' => ['status' => 'none'],
            'available_actions' => [['type' => 'request_transfer']],
            'career_outlook' => [
                'category' => 'good_situation', 'label' => 'Good situation',
                'guidance' => [['message' => 'Your current role and opportunity are stable.', 'action_type' => null]],
                'evidence' => ['role' => 'regular', 'performance' => 'strong'],
            ],
        ];
        $model = $presentation->careerDecisionHub($stable, SimulationDate::fromIsoString('2025-01-01'));

        self::assertSame('Under contract', $model['contract']['label']);
        self::assertSame('Arsenal', $model['contract']['club']);
        self::assertTrue($model['transfer_request']['can_request']);
        self::assertFalse($model['transfer_request']['active']);
        self::assertSame([], $model['action_required']);
        self::assertSame('Good situation', $model['outlook']['label']);
        self::assertSame('Regular', $model['outlook']['evidence'][0]['value']);

        $pending = $stable;
        $pending['pending_decisions'] = [[
            'id' => 'opportunity-1', 'type' => 'contract_renewal', 'status' => 'open',
            'expiry_date' => '2025-06-30', 'options' => [['kind' => 'renew_current_club']],
        ]];
        $pending['open_opportunities'] = [[
            'id' => 'opportunity-1', 'context' => ['decision_kind' => 'contract_boundary'],
        ]];
        $pendingModel = $presentation->careerDecisionHub($pending);
        self::assertSame('Renewal decision available', $pendingModel['contract']['label']);
        self::assertSame('Contract decision', $pendingModel['action_required'][0]['label']);
        self::assertSame(1, $pendingModel['action_required'][0]['options_count']);

        $requested = $stable;
        $requested['transfer_request'] = ['status' => 'requested', 'season_id' => 'season-1'];
        $requested['available_actions'] = [['type' => 'withdraw_transfer_request']];
        $requested['active_loan'] = ['loan_club' => ['name' => 'Loan Club'], 'parent_club' => ['name' => 'Arsenal'], 'scheduled_end_date' => '2025-06-30'];
        $requestedModel = $presentation->careerDecisionHub($requested);
        self::assertTrue($requestedModel['transfer_request']['active']);
        self::assertSame('withdraw_transfer', $requestedModel['transfer_request']['action']);
        self::assertTrue($requestedModel['loan']['active']);
        self::assertSame('Loan Club', $requestedModel['loan']['loan_club']);
    }

    public function testDecisionChoiceContextSeparatesKnownTermsFromUncertainFootballOutcomes(): void
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $presentation = new CareerPresentationService($services);
        $choice = $presentation->decisionChoiceContext(
            [
                'current_club' => ['name' => 'Arsenal'],
                'current_competition' => ['name' => 'Premier League'],
                'current_role' => 'rotation',
                'current_contract' => ['wage' => 1200, 'end_date' => '2026-06-30'],
            ],
            ['decision_kind' => 'contract_boundary', 'current_wage' => 1200],
            'contract_boundary',
            [[
                'id' => 'renew-current-club', 'kind' => 'renew_current_club',
                'target_club_name' => 'Arsenal', 'wage' => 1500, 'term_seasons' => 2,
            ]],
        );

        self::assertSame('Arsenal', $choice['context']['comparison']['current']['club']);
        self::assertContains('The selected Contract terms, Club and recorded role are the facts shown on each offer.', $choice['context']['known_effects']);
        self::assertContains('Future selection, playing time and later offers are not guaranteed.', $choice['context']['uncertain_effects']);
        self::assertContains('The selected Club, wage, term and recorded role are shown in this offer.', $choice['options'][0]['known_effects']);
        self::assertContains('Future selection and development are not guaranteed by the Contract.', $choice['options'][0]['uncertain_effects']);
    }

    public function testDecisionOutcomesUseTheResolvedChoiceWithoutInventingFutureResults(): void
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $presentation = new CareerPresentationService($services);
        $loan = $presentation->decisionOutcome([
            'type' => 'loan',
            'context' => [
                'decision_kind' => 'controlled_loan', 'offer_status' => 'accepted', 'selected_option' => 'accept-loan-arsenal',
                'parent_club_name' => 'Chelsea',
                'options' => [['id' => 'accept-loan-arsenal', 'kind' => 'accept_loan', 'target_club_name' => 'Arsenal', 'parent_club_name' => 'Chelsea']],
            ],
        ]);
        $retirement = $presentation->decisionOutcome(['type' => 'retirement', 'context' => ['decision_kind' => 'retirement', 'decision_result' => 'continue-playing']]);

        self::assertSame('Loan accepted: you are playing for Arsenal; your parent Contract remains with Chelsea.', $loan);
        self::assertSame('You chose to continue playing. Your Career remains active.', $retirement);
        self::assertStringNotContainsString('starter', strtolower($loan));
    }

    public function testCareerHomeMakesZeroEvidenceReadable(): void
    {
        $text = implode("\n", (new CareerFormatter())->home([
            'player' => ['preferred_name' => 'Alex Rivera', 'primary_nation_id' => 'england', 'primary_position' => 'CM'],
            'age' => 18,
            'current_ovr' => 60,
            'potential' => 81,
            'development_profile' => 'late_bloomer',
            'current_role' => 'key_player',
            'on_pitch_role' => ['role_label' => 'Central Midfielder'],
            'current_club' => ['name' => 'Arsenal'],
            'current_competition' => ['name' => 'Premier League', 'tier' => 1],
            'season_stats' => ['appearances' => 0, 'starts' => 0, 'minutes' => 0, 'goals' => 0, 'assists' => 0, 'average_match_rating' => null],
            'recent_form' => ['classification' => 'insufficient_evidence', 'rated_appearances' => 0, 'average_match_rating' => null],
            'season_performance' => ['classification' => 'insufficient_evidence'],
            'career_outlook' => ['category' => 'breaking_through'],
            'current_contract' => ['status' => 'active', 'end_date' => '2026-06-30'],
            'transfer_request' => ['status' => 'none'],
            'available_actions' => [],
        ], '2024-07-31', ['date' => '2024-08-01', 'home_club' => 'Arsenal', 'away_club' => 'Chelsea', 'competition' => 'Premier League'], ['position' => 1, 'played' => 0, 'points' => 0]));

        self::assertStringContainsString('Not enough matches yet', $text);
        self::assertStringContainsString('Not enough evidence', $text);
        self::assertStringContainsString('Key Player', $text);
        self::assertStringContainsString('On-Pitch Role: Central Midfielder', $text);
        self::assertStringContainsString('Late Bloomer', $text);
        self::assertStringNotContainsString('insufficient_evidence', $text);
        self::assertStringNotContainsString('undefined', strtolower($text));
    }

    public function testSeasonSummaryKeepsCrossCompetitionCareerVisible(): void
    {
        $text = implode("\n", (new CareerFormatter())->seasonSummary([
            'season' => '2025/26',
            'club' => 'Arsenal',
            'competition' => 'Premier League',
            'position' => 2,
            'stats' => ['appearances' => 30, 'starts' => 24, 'minutes' => 2180, 'goals' => 8, 'assists' => 6, 'average_match_rating' => 7.2],
            'performance' => 'strong',
            'competition_stats' => [['competition' => 'Premier League', 'stats' => ['appearances' => 24, 'starts' => 20, 'minutes' => 1800, 'goals' => 6, 'assists' => 5]]],
            'cup_results' => [['competition' => 'English Domestic Cup', 'result' => 'Won']],
            'europe_results' => [['competition' => 'European Tier 1', 'result' => 'Runner-up']],
            'international_stats' => ['caps' => 4, 'goals' => 1],
        ]));

        self::assertStringContainsString('English Domestic Cup — Won', $text);
        self::assertStringContainsString('European Tier 1 — Runner-up', $text);
        self::assertStringContainsString('International: 4 caps, 1 goals', $text);
    }

    public function testMatchdayUsesReadableParticipationRatingAndPositionEvidence(): void
    {
        $text = implode("\n", (new CareerFormatter())->matchday([
            'competition' => 'Premier League',
            'date' => '2024-08-01',
            'home_club' => 'Arsenal',
            'away_club' => 'Chelsea',
            'perspective_result' => 'win',
            'result' => ['home_goals' => 2, 'away_goals' => 1],
            'performance' => [
                'selection_status' => 'starter', 'appeared' => true, 'started' => true, 'minutes' => 90,
                'rating' => 7.4, 'position' => 'CB', 'on_pitch_role_label' => 'Central Defender', 'goals' => 1, 'assists' => 0,
                'tackles' => 4, 'interceptions' => 2, 'blocks' => 1, 'passes_attempted' => 35,
                'passes_completed' => 30, 'clean_sheets' => 0, 'yellow_cards' => 0, 'red_cards' => 0,
            ],
            'highlights' => ["78' GOAL — YOU — Alex Rivera (Arsenal)"],
            'post_match' => [
                'recent_form' => ['classification' => 'good', 'rated_appearances' => 2, 'average_match_rating' => 6.8],
                'season_stats' => ['appearances' => 1, 'starts' => 1, 'minutes' => 90, 'goals' => 1, 'assists' => 0, 'average_match_rating' => 7.4],
                'club_position' => 1, 'club_points' => 3,
            ],
        ]));

        self::assertStringContainsString('RESULT: Win', $text);
        self::assertStringContainsString('Started', $text);
        self::assertStringContainsString('Rating: 7.4', $text);
        self::assertStringContainsString('Goals 1', $text);
        self::assertStringContainsString('Tackles 4', $text);
        self::assertStringContainsString('Central Defender', $text);
        self::assertStringContainsString('Interceptions 2', $text);
        self::assertStringContainsString('Recent Form: Good', $text);
        self::assertStringContainsString("78' GOAL", $text);
    }

    public function testParticipationStatesDoNotInventRatings(): void
    {
        $formatter = new CareerFormatter();
        $substitute = implode("\n", $formatter->matchday([
            'competition' => 'League', 'date' => '2024-08-01', 'home_club' => 'A', 'away_club' => 'B',
            'perspective_result' => 'loss', 'result' => ['home_goals' => 0, 'away_goals' => 1],
            'performance' => [
                'selection_status' => 'bench', 'appeared' => true, 'started' => false, 'minutes' => 29,
                'substitution_minute' => 61, 'rating' => 6.5, 'position' => 'ST', 'goals' => 0,
                'assists' => 1, 'shots' => 2, 'shots_on_target' => 1, 'passes_attempted' => 8,
                'passes_completed' => 6, 'yellow_cards' => 0, 'red_cards' => 0,
            ],
            'highlights' => [], 'post_match' => ['recent_form' => [], 'season_stats' => []],
        ]));
        self::assertStringContainsString("Came on in 61'", $substitute);
        self::assertStringContainsString('Rating: 6.5', $substitute);
        self::assertStringContainsString('Assists 1', $substitute);

        foreach ([
            ['selection_status' => 'bench', 'appeared' => false, 'expected' => 'Unused substitute'],
            ['selection_status' => 'not_selected', 'appeared' => false, 'expected' => 'Not selected'],
        ] as $case) {
            $text = implode("\n", $formatter->matchday([
                'competition' => 'Cup', 'date' => '2024-08-01', 'home_club' => 'A', 'away_club' => 'B',
                'perspective_result' => 'draw', 'result' => ['home_goals' => 0, 'away_goals' => 0],
                'performance' => $case + ['position' => 'ST', 'rating' => null],
                'highlights' => [], 'post_match' => ['recent_form' => [], 'season_stats' => []],
            ]));
            self::assertStringContainsString($case['expected'], $text);
            self::assertStringNotContainsString('Rating:', $text);
        }
    }

    public function testPostMatchStatsStayPositionAware(): void
    {
        $formatter = new CareerFormatter();
        $base = [
            'competition' => 'League', 'date' => '2024-08-01', 'home_club' => 'A', 'away_club' => 'B',
            'perspective_result' => 'win', 'result' => ['home_goals' => 1, 'away_goals' => 0],
            'highlights' => [], 'post_match' => ['recent_form' => [], 'season_stats' => []],
        ];
        $cases = [
            ['position' => 'GK', 'stats' => ['saves' => 4, 'shots' => 2], 'present' => 'Saves 4', 'absent' => 'Shots 2'],
            ['position' => 'CB', 'stats' => ['tackles' => 3, 'shots' => 2], 'present' => 'Tackles 3', 'absent' => 'Shots 2'],
            ['position' => 'CM', 'stats' => ['passes_attempted' => 20, 'passes_completed' => 17, 'shots' => 2], 'present' => 'Passing 17/20', 'absent' => 'Shots 2'],
            ['position' => 'ST', 'stats' => ['shots' => 3, 'shots_on_target' => 2, 'tackles' => 2], 'present' => 'Shots 3', 'absent' => 'Tackles 2'],
        ];
        foreach ($cases as $case) {
            $text = implode("\n", $formatter->matchday($base + [
                'performance' => ['selection_status' => 'starter', 'appeared' => true, 'started' => true, 'minutes' => 90, 'rating' => 7.0] + $case['stats'] + ['position' => $case['position']],
            ]));
            self::assertStringContainsString($case['present'], $text);
            self::assertStringNotContainsString($case['absent'], $text);
        }
    }

    public function testDecisionAndNewsStayPlayerFacing(): void
    {
        $formatter = new CareerFormatter();
        $decision = implode("\n", $formatter->decision([
            'decision_kind' => 'contract_boundary',
            'current_club' => 'FC Example',
            'current_competition' => ['name' => 'Premier League', 'tier' => 1],
            'contract' => 'Active through 2025-06-30',
            'options' => [
                ['label' => 'Renew with your current Club', 'club' => ['name' => 'FC Example', 'country' => 'England', 'competition' => 'Premier League', 'tier' => 1], 'role' => 'Regular'],
                ['label' => 'Enter free agency', 'club' => null],
            ],
        ]));
        self::assertStringContainsString('CAREER DECISION', $decision);
        self::assertStringContainsString('Renew with your current Club', $decision);
        self::assertStringContainsString('England', $decision);
        self::assertStringNotContainsString('contract_boundary', $decision);
        self::assertStringNotContainsString('club-id-', $decision);

        $news = implode("\n", $formatter->news([
            ['date' => '2024-08-01', 'headline' => 'RESULT — FC Example 2-1 United'],
            ['date' => '2024-08-02', 'headline' => 'DEVELOPMENT — OVR 62 -> 63'],
        ]));
        self::assertStringContainsString('NEWS', $news);
        self::assertStringContainsString('RESULT — FC Example 2-1 United', $news);
        self::assertStringContainsString('OVR 62 -> 63', $news);
    }

    public function testWorldShowsBoundedResultsAndUpcomingFixtures(): void
    {
        $text = implode("\n", (new CareerFormatter())->world([
            'competition' => 'Premier League',
            'standings' => [],
            'recent_results' => ['* 2024-08-01: FC Example 2-1 United'],
            'recent_result' => '* 2024-08-01: FC Example 2-1 United',
            'upcoming_fixtures' => ['* 2024-08-08: FC Example vs United'],
            'next_fixture' => '2024-08-08: FC Example vs United',
        ]));
        self::assertStringContainsString('RECENT RESULTS', $text);
        self::assertStringContainsString('UPCOMING FIXTURES', $text);
        self::assertStringContainsString('* 2024-08-08: FC Example vs United', $text);
    }

    public function testCareerLegacyFormatterKeepsAchievementSectionsDescriptive(): void
    {
        $text = implode("\n", (new CareerFormatter())->legacy([
            'career_span' => ['start' => '2024/25', 'latest' => '2030/31'],
            'clubs' => [['name' => 'Arsenal'], ['name' => 'Milan']],
            'club_stats' => ['appearances' => 126, 'goals' => 42, 'assists' => 19],
            'international_stats' => ['caps' => 28, 'goals' => 7],
            'honours' => [['label' => 'League Champion — Premier League']],
            'awards' => [['award_type' => 'PLAYER_OF_THE_SEASON']],
            'records' => [['metric' => 'best_season_goals', 'value' => 14]],
            'milestones' => [['label' => '50 Club appearances']],
            'career_timeline' => [['date' => '2024-08-02', 'title' => 'Senior debut']],
            'personal_bests' => [['label' => 'Most goals in a Season', 'value' => 14]],
        ]));

        self::assertStringContainsString('CAREER LEGACY', $text);
        self::assertStringContainsString('Arsenal · Milan', $text);
        self::assertStringContainsString('League Champion — Premier League', $text);
        self::assertStringContainsString('Player of the Season', $text);
        self::assertStringContainsString('Legacy score: descriptive only', $text);
        self::assertStringContainsString('CAREER TIMELINE', $text);
        self::assertStringContainsString('Most goals in a Season', $text);
        self::assertStringNotContainsString('Legacy Points', $text);
    }
}
