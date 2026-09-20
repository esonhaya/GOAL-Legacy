<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Player;

use Goal\Legacy\Modules\Player\CareerClubContextService;
use PHPUnit\Framework\TestCase;

final class CareerClubContextTest extends TestCase
{
    public function testJourneyDerivesAttachmentFormerClubBreakthroughAndReturn(): void
    {
        $context = (new CareerClubContextService())->derive($this->summary([
            $this->season('season-01', '2024/25', 'arsenal', 'Arsenal', 15, 900, 3),
            $this->season('season-02', '2025/26', 'arsenal', 'Arsenal', 30, 2400, 7),
            $this->season('season-03', '2026/27', 'milan', 'Milan', 24, 1900, 5),
            $this->season('season-04', '2027/28', 'arsenal', 'Arsenal', 28, 2100, 6),
        ], 'arsenal', 26, 'key_player'));

        self::assertSame('club_figure', $context['attachment']['state']);
        self::assertSame('arsenal', $context['breakthrough_club']['club_id']);
        self::assertSame(['milan'], $context['former_club_ids']);
        self::assertSame('arsenal', $context['returns'][0]['club_id']);
        self::assertFalse($context['one_club_career']);
        self::assertSame('Arsenal', $context['longest_club_spell']['club_name']);
        self::assertSame(2, count($context['club_journey']));
    }

    public function testSampleSizeSuppressesAttachmentAndBreakthrough(): void
    {
        $context = (new CareerClubContextService())->derive($this->summary([
            $this->season('season-01', '2024/25', 'arsenal', 'Arsenal', 1, 12, 0),
        ], 'arsenal', 19, 'prospect'));

        self::assertSame('new_arrival', $context['attachment']['state']);
        self::assertNull($context['breakthrough_club']);
        self::assertSame('break_through', $context['direction']['key']);
    }

    public function testDirectionUsesPlayingTimeAndContractContextWithoutScores(): void
    {
        $summary = $this->summary([
            $this->season('season-01', '2024/25', 'arsenal', 'Arsenal', 30, 2200, 4),
        ], 'arsenal', 24, 'rotation');
        $summary['manager_context'] = ['playing_time_status' => 'below_expectation'];
        $summary['recent_playing_time'] = ['minutes' => 180];
        $context = (new CareerClubContextService())->derive($summary);

        self::assertSame('seek_regular_football', $context['direction']['key']);
        self::assertTrue($context['direction']['actionable']);
        self::assertArrayNotHasKey('score', $context['direction']);
        self::assertArrayNotHasKey('loyalty', $context['attachment']);
    }

    public function testOneClubCareerIsStableAndDeterministic(): void
    {
        $summary = $this->summary([
            $this->season('season-02', '2025/26', 'chelsea', 'Chelsea', 20, 1600, 2),
            $this->season('season-01', '2024/25', 'chelsea', 'Chelsea', 18, 1400, 1),
        ], 'chelsea', 25, 'regular');
        $service = new CareerClubContextService();

        self::assertSame($service->derive($summary), $service->derive($summary));
        self::assertTrue($service->derive($summary)['one_club_career']);
        self::assertSame('strong_connection', $service->derive($summary)['attachment']['state']);
        self::assertSame('Chelsea', $service->derive($summary)['club_journey'][0]['club_name']);
    }

    /** @param list<array<string, mixed>> $history @return array<string, mixed> */
    private function summary(array $history, string $clubId, int $age, string $role): array
    {
        return [
            'season_history' => $history,
            'current_club' => ['id' => $clubId, 'name' => ucfirst($clubId)],
            'current_role' => $role,
            'age' => $age,
            'career_phase' => $age >= 31 ? 'decline' : ($age <= 23 ? 'development' : 'prime'),
            'career_state' => 'active',
            'manager_context' => ['playing_time_status' => 'meeting_expectation'],
            'recent_playing_time' => ['minutes' => 1200],
            'career_outlook' => ['contract_outlook' => 'secure'],
            'transfer_request' => ['status' => 'none'],
            'current_competition' => ['tier' => 1],
        ];
    }

    /** @return array<string, mixed> */
    private function season(string $id, string $label, string $clubId, string $clubName, int $appearances, int $minutes, int $goals): array
    {
        return [
            'season_id' => $id,
            'season' => $label,
            'club' => ['id' => $clubId, 'name' => $clubName],
            'appearances' => $appearances,
            'minutes' => $minutes,
            'goals' => $goals,
            'assists' => 2,
            'role' => 'regular',
        ];
    }
}
