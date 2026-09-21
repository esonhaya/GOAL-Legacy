<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Devtools;

use Goal\Legacy\Devtools\Presentation\CareerAchievementSummary;
use PHPUnit\Framework\TestCase;

final class CareerAchievementSummaryTest extends TestCase
{
    public function testProjectionComposesCanonicalFactsOnceWithClubAndSeasonContext(): void
    {
        $summary = $this->summary();
        $projection = (new CareerAchievementSummary())->project($summary);

        self::assertSame(['club_honours' => 1, 'international_honours' => 1, 'individual_awards' => 1, 'personal_bests' => 1, 'milestones' => 1], $projection['counts']);
        self::assertSame('2024/25', $projection['club_honours'][0]['season']);
        self::assertSame('Arsenal', $projection['club_honours'][0]['club']);
        self::assertSame('Premier League', $projection['club_honours'][0]['competition']);
        self::assertSame('World Championship Winner', $projection['international_honours'][0]['label']);
        self::assertSame('Player of the Season', $projection['individual_awards'][0]['label']);
        self::assertSame('Chelsea', $projection['individual_awards'][0]['club']);
        self::assertSame('2024/25', $projection['personal_bests'][0]['season']);
        self::assertSame('Chelsea', $projection['personal_bests'][0]['club']);
        self::assertSame('2024/25', $projection['milestones'][0]['season']);
        self::assertSame('Career Club goals', $projection['career_records'][1]['label']);
        self::assertSame(42, $projection['career_records'][1]['value']);
        self::assertCount(1, $projection['club_honours']);
        self::assertCount(1, $projection['international_honours']);
        self::assertCount(1, $projection['individual_awards']);
        self::assertSame('Chelsea', $projection['best_seasons'][0]['club']);
        self::assertSame('Highest goals', $projection['best_seasons'][0]['label']);
        self::assertSame(14, $projection['best_seasons'][0]['value']);
        self::assertCount(1, $projection['club_journey']);
        self::assertCount(1, $projection['captaincy']['history']);
        self::assertSame($projection, (new CareerAchievementSummary())->project($summary));
    }

    public function testProjectionKeepsCareerRecordsDistinctFromPersonalBestsAndRivalries(): void
    {
        $projection = (new CareerAchievementSummary())->project($this->summary());

        self::assertSame('career_total', $projection['career_records'][0]['kind']);
        self::assertSame('best_season_goals', $projection['personal_bests'][0]['metric']);
        self::assertArrayNotHasKey('rivalry', $projection);
        self::assertArrayNotHasKey('rivalry', $projection['club_honours'][0]);
        self::assertSame('canonical career honours, awards, records, milestones and statistics', $projection['source']);
    }

    public function testYoungAndRetiredCareersUseHonestEmptyStatesAndNoNumericLegacy(): void
    {
        $summary = ['career_state' => 'active', 'career_stats' => [], 'legacy' => ['honours' => [], 'awards' => [], 'records' => [], 'milestones' => [], 'international_stats' => [], 'legacy_score' => null]];
        $projection = (new CareerAchievementSummary())->project($summary);

        self::assertSame(0, $projection['counts']['club_honours']);
        self::assertSame([], $projection['club_honours']);
        self::assertSame([], $projection['individual_awards']);
        self::assertSame([], $projection['personal_bests']);
        self::assertSame([], $projection['best_seasons']);
        self::assertSame('active', $projection['career_state']);
        self::assertNull($projection['retirement']);
        self::assertArrayNotHasKey('legacy_score', $projection);

        $summary['career_state'] = 'retired';
        $retired = (new CareerAchievementSummary())->project($summary);
        self::assertSame('retired', $retired['career_state']);
        self::assertSame([], $retired['milestones']);
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        return [
            'career_state' => 'active',
            'career_stats' => ['appearances' => 120, 'goals' => 42, 'assists' => 19],
            'season_history' => [
                ['season_id' => 'season-2024-25', 'season' => '2024/25', 'club' => ['id' => 'chelsea', 'name' => 'Chelsea'], 'appearances' => 30, 'goals' => 14, 'assists' => 8, 'rated_appearances' => 30, 'average_match_rating' => 7.8],
            ],
            'career_context' => ['club_journey' => [['club_id' => 'chelsea', 'club_name' => 'Chelsea', 'seasons' => ['2024/25'], 'appearances' => 30]]],
            'international' => ['stats' => ['caps' => 12, 'goals' => 3]],
            'captaincy' => ['history' => [['captain_player_id' => 'player-1', 'appointed_date' => '2025-01-01']]],
            'legacy' => [
                'club_stats' => ['appearances' => 120, 'goals' => 42, 'assists' => 19],
                'international_stats' => ['caps' => 12, 'goals' => 3],
                'career_span' => ['start' => '2024/25', 'latest' => '2024/25'],
                'clubs' => [['id' => 'chelsea', 'name' => 'Chelsea'], ['id' => 'arsenal', 'name' => 'Arsenal']],
                'honours' => [
                    ['source_key' => 'honour-1', 'honour_type' => 'league_champion', 'label' => 'League Champion — Premier League', 'season_id' => 'season-2024-25', 'competition_id' => 'premier-league', 'holder_id' => 'arsenal', 'evidence' => ['appearances' => 10]],
                    ['source_key' => 'honour-2', 'honour_type' => 'world_championship_winner', 'label' => 'World Championship Winner', 'season_id' => 'season-2024-25', 'competition_id' => 'world-championship', 'holder_id' => 'national-team-england', 'evidence' => ['caps' => 3]],
                ],
                'awards' => [['source_key' => 'award-1', 'award_type' => 'PLAYER_OF_THE_SEASON', 'season_id' => 'season-2024-25', 'competition_id' => 'premier-league', 'club_id' => 'chelsea', 'evidence' => ['competition_name' => 'Premier League']]],
                'records' => [['metric' => 'best_season_goals', 'value' => 14, 'season_id' => 'season-2024-25', 'club_id' => 'chelsea', 'evidence' => ['value' => 14]]],
                'milestones' => [['source_key' => 'milestone-1', 'metric' => 'club_goals', 'threshold' => 25, 'label' => '25 Club goals', 'season_id' => 'season-2024-25', 'occurred_date' => '2025-05-31']],
            ],
        ];
    }
}
