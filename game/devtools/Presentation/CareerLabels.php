<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Presentation;

/** Human-facing labels for persisted career values. */
final class CareerLabels
{
    /** @var array<string, string> */
    private const LABELS = [
        'active' => 'Active',
        'approaching_decision' => 'Decision approaching',
        'balanced' => 'Balanced',
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
