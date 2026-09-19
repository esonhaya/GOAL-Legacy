<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Presentation;

/** Human-facing labels for persisted career values. */
final class CareerLabels
{
    /** @var array<string, string> */
    private const LABELS = [
        'active' => 'Active',
        'retired' => 'Retired',
        'career_complete' => 'Career Complete',
        'retirement_decision' => 'Retirement decision',
        'late_career' => 'Late Career',
        'youth' => 'Youth',
        'prime' => 'Prime',
        'experienced' => 'Experienced',
        'veteran' => 'Veteran',
        'decline' => 'Decline',
        'continue-playing' => 'Continue playing',
        'retire' => 'Retire',
        'approaching_decision' => 'Decision approaching',
        'balanced' => 'Balanced',
        'fresh' => 'Fresh',
        'ready' => 'Ready',
        'managed' => 'Managed',
        'tired' => 'Tired',
        'fatigued' => 'Fatigued',
        'injured' => 'Injured',
        'light' => 'Light / recovery',
        'normal' => 'Normal',
        'intense' => 'Intense',
        'breakout' => 'Breakout',
        'breaking_through' => 'Breaking Through',
        'blocked_path' => 'Blocked Path',
        'cancelled' => 'Cancelled',
        'club_promoted' => 'Promoted',
        'club_relegated' => 'Relegated',
        'competing_for_role' => 'Competing for a Role',
        'contract_uncertainty' => 'Contract Uncertain',
        'contract_renewal' => 'Contract renewal',
        'contract_boundary' => 'Contract decision',
        'development' => 'Development',
        'defending' => 'Defending',
        'dribbling' => 'Dribbling',
        'free_agent_contract' => 'Free-agent decision',
        'family' => 'Family',
        'financial' => 'Financial',
        'draw' => 'Draw',
        'excellent' => 'Excellent',
        'expired' => 'Expired',
        'free_agent' => 'Free Agent',
        'good' => 'Good',
        'good_situation' => 'Good Situation',
        'insufficient_evidence' => 'Not enough evidence',
        'key_player' => 'Key Player',
        'late_bloomer' => 'Late Bloomer',
        'lifestyle' => 'Lifestyle',
        'limited' => 'Limited',
        'loss' => 'Loss',
        'needs_minutes' => 'Needs More Minutes',
        'neutral' => 'Neutral',
        'none' => 'None',
        'not_selected' => 'Not selected',
        'pace' => 'Pace',
        'passing' => 'Passing',
        'physicality' => 'Physicality',
        'poor' => 'Poor',
        'professional' => 'Professional',
        'prospect' => 'Prospect',
        'regular' => 'Regular',
        'released' => 'Released',
        'recovery' => 'Recovery',
        'request_transfer' => 'Request transfer',
        'accept_transfer' => 'Accept transfer',
        'renew_current_club' => 'Renew current Club',
        'sign_with_club' => 'Join Club',
        'enter_free_agency' => 'Enter free agency',
        'stay' => 'Stay',
        'requested' => 'Transfer requested',
        'rotation' => 'Rotation',
        'stagnant' => 'Stagnant',
        'steady' => 'Steady',
        'strong' => 'Strong',
        'shooting' => 'Shooting',
        'social' => 'Social',
        'substitute_used' => 'Substitute',
        'terminated' => 'Terminated',
        'transfer' => 'Transferred',
        'transfer_opportunity' => 'Transfer Interest',
        'transfer_requested' => 'Transfer requested',
        'transfer_request_created' => 'Transfer request submitted',
        'transfer_request_withdrawn' => 'Transfer request withdrawn',
        'club_player_role_changed' => 'Role changed',
        'player_developed' => 'Development',
        'season_completed' => 'Season completed',
        'season_created' => 'New Season',
        'unavailable' => 'Unavailable',
        'very_poor' => 'Very Poor',
        'win' => 'Win',
        'player_of_the_season' => 'Player of the Season',
        'young_player_of_the_season' => 'Young Player of the Season',
        'top_scorer' => 'Top Scorer',
        'top_assist_provider' => 'Top Assist Provider',
        'league_champion' => 'League Champion',
        'domestic_cup_winner' => 'Domestic Cup Winner',
        'europe_tier_1_winner' => 'Europe Tier 1 Winner',
        'europe_tier_2_winner' => 'Europe Tier 2 Winner',
        'world_championship_winner' => 'World Championship Winner',
        'club_appearances' => 'Career Club appearances',
        'club_goals' => 'Career Club goals',
        'club_assists' => 'Career Club assists',
        'international_caps' => 'International caps',
        'international_goals' => 'International goals',
        'best_season_goals' => 'Best scoring Season',
        'best_season_assists' => 'Best assist Season',
        'best_season_rating' => 'Best average-rated Season',
        'most_season_appearances' => 'Most appearances in a Season',
    ];

    public static function value(mixed $value, string $fallback = 'Not available'): string
    {
        if (!is_string($value) || trim($value) === '') {
            return $fallback;
        }

        $key = strtolower(trim($value));
        if (isset(self::LABELS[$key])) {
            return self::LABELS[$key];
        }

        return ucwords(str_replace(['_', '-'], ' ', $key));
    }

    public static function nationality(mixed $value): string
    {
        return self::value($value, 'Not available');
    }

    public static function position(mixed $value): string
    {
        if (is_string($value) && preg_match('/^[A-Za-z]{2}$/', trim($value)) === 1) {
            return strtoupper(trim($value));
        }

        return self::value($value, 'Not available');
    }
}
