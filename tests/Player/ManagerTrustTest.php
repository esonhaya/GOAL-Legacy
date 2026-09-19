<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Player;

use Goal\Legacy\Modules\Player\CareerOutlookService;
use Goal\Legacy\Modules\Player\ManagerTrustService;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PHPUnit\Framework\TestCase;

final class ManagerTrustTest extends TestCase
{
    public function testFootballTrustUsesExistingRelationshipWithBoundedFootballEvidence(): void
    {
        $context = (new ManagerTrustService())->derive([
            'current_role' => 'regular',
            'availability' => 'available',
            'readiness' => ['status' => 'available'],
            'social' => ['manager_score' => 72, 'manager_relationship' => 'Trusted'],
            'recent_form' => ['classification' => 'good'],
            'position_competition' => ['same_position_count' => 1, 'higher_ovr_count' => 0],
            'recent_playing_time' => ['window' => 5, 'appearances' => 4, 'starts' => 4, 'minutes' => 330, 'minutes_share' => 0.7333, 'red_cards' => 0],
        ]);

        self::assertSame('important', $context['trust_label']);
        self::assertSame('first_choice', $context['competition_status']);
        self::assertSame('on_track', $context['playing_time_status']);
        self::assertFalse($context['conversation_eligible']);
        self::assertStringContainsString('reliable', $context['feedback']);
    }

    public function testSustainedLowMinutesCreatesRecoverableContextWithoutASecondScore(): void
    {
        $context = (new ManagerTrustService())->derive([
            'current_role' => 'rotation',
            'availability' => 'available',
            'readiness' => ['status' => 'available'],
            'social' => ['manager_score' => 50, 'manager_relationship' => 'Professional'],
            'recent_form' => ['classification' => 'neutral'],
            'position_competition' => ['same_position_count' => 3, 'higher_ovr_count' => 2],
            'recent_playing_time' => ['window' => 5, 'appearances' => 0, 'starts' => 0, 'minutes' => 0, 'minutes_share' => 0.0, 'red_cards' => 0],
        ]);

        self::assertSame('developing', $context['trust_label']);
        self::assertSame('rotation_battle', $context['competition_status']);
        self::assertSame('severely_below_expectation', $context['playing_time_status']);
        self::assertTrue($context['conversation_eligible']);
        self::assertSame([], array_filter(array_keys($context), static fn (string $key): bool => str_contains($key, 'score') && $key !== 'manager_relationship'));
        self::assertStringContainsString('more football', $context['feedback']);
    }

    public function testOutlookUsesSustainedManagerMinutesContext(): void
    {
        $summary = [
            'career_state' => 'active',
            'current_club' => ['id' => 'arsenal'],
            'current_contract' => ['status' => 'active', 'end_date' => '2027-06-30'],
            'current_role' => 'regular',
            'squad_role' => 'regular',
            'career_phase' => 'prime',
            'latest_season_performance' => ['classification' => 'neutral', 'statistics' => ['minutes_share' => 0.2, 'start_share' => 0.1]],
            'recent_form' => ['appearances' => 4],
            'position_competition' => ['same_position_count' => 2, 'higher_ovr_count' => 1],
            'manager_context' => ['trust_label' => 'developing', 'competition_status' => 'competing_for_start', 'playing_time_status' => 'below_expectation'],
            'pending_decisions' => [],
            'transfer_request' => ['status' => 'none'],
            'available_actions' => [],
        ];

        $outlook = (new CareerOutlookService())->derive($summary, SimulationDate::fromIsoString('2025-01-01'));

        self::assertSame('needs_minutes', $outlook['category']);
        self::assertSame('below_expectation', $outlook['evidence']['playing_time_status']);
    }
}
