<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Devtools;

use Goal\Legacy\Devtools\Presentation\CareerFormatter;
use PHPUnit\Framework\TestCase;

final class CareerPresentationTest extends TestCase
{
    public function testCareerHomeMakesZeroEvidenceReadable(): void
    {
        $text = implode("\n", (new CareerFormatter())->home([
            'player' => ['preferred_name' => 'Alex Rivera', 'primary_nation_id' => 'england', 'primary_position' => 'CM'],
            'age' => 18,
            'current_ovr' => 60,
            'potential' => 81,
            'development_profile' => 'late_bloomer',
            'current_role' => 'key_player',
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
                'rating' => 7.4, 'position' => 'CB', 'goals' => 1, 'assists' => 0,
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
}
