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
