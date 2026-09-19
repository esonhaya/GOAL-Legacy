<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Player\Domain\AvailabilityStatus;
use Goal\Legacy\Modules\Player\Domain\Player;

/**
 * Derives the controlled Player's selection-facing football context.
 *
 * FootballSocialService remains the owner of the interpersonal manager
 * relationship. This class deliberately stores nothing: its trust label and
 * playing-time assessment are read-model interpretations of existing facts.
 */
final class ManagerTrustService
{
    /** @param array<string, mixed> $summary @return array<string, mixed> */
    public function derive(array $summary): array
    {
        $role = (string) ($summary['current_role'] ?? $summary['squad_role'] ?? '');
        $social = is_array($summary['social'] ?? null) ? $summary['social'] : [];
        $competition = is_array($summary['position_competition'] ?? null) ? $summary['position_competition'] : [];
        $form = is_array($summary['recent_form'] ?? null) ? $summary['recent_form'] : [];
        $playing = is_array($summary['recent_playing_time'] ?? null) ? $summary['recent_playing_time'] : [];
        $readiness = is_array($summary['readiness'] ?? null) ? $summary['readiness'] : [];
        $relationship = (string) ($social['manager_relationship'] ?? 'professional');
        $relationshipScore = (int) ($social['manager_score'] ?? 50);
        $formClass = (string) ($form['classification'] ?? 'insufficient_evidence');
        $higher = (int) ($competition['higher_ovr_count'] ?? 0);
        $samePosition = (int) ($competition['same_position_count'] ?? 0);
        $window = (int) ($playing['window'] ?? 0);
        $minutesShare = (float) ($playing['minutes_share'] ?? 0.0);
        $playingStatus = $this->playingTimeStatus($role, $minutesShare, $window);
        $trustSignal = $this->trustSignal($relationshipScore, $formClass, $playingStatus, $role, $playing);
        $trustLabel = $this->trustLabel($trustSignal, $role, $playingStatus, $window);
        $competitionStatus = $this->competitionStatus($role, $samePosition, $higher, $playing);
        $reasons = $this->reasons($summary, $playingStatus, $competitionStatus, $formClass, $playing);
        $feedback = $this->feedback($summary, $playingStatus, $competitionStatus, $formClass, $window);

        return [
            'trust_label' => $trustLabel,
            'manager_relationship' => $relationship,
            'competition_status' => $competitionStatus,
            'playing_time_status' => $playingStatus,
            'expected_usage' => $this->expectedUsage($role),
            'recent_playing_time' => $playing,
            'reasons' => $reasons,
            'feedback' => $feedback,
            'selection_context' => $this->selectionContext($summary, $competitionStatus, $playingStatus, $formClass),
            'conversation_eligible' => $window >= 3 && in_array($playingStatus, ['below_expectation', 'severely_below_expectation'], true)
                && ($readiness['status'] ?? 'available') !== AvailabilityStatus::Unavailable->value,
        ];
    }

    /**
     * A very small tie-break influence for the controlled Player only. Role,
     * OVR, form and availability remain the selection model's authority.
     */
    public function selectionInfluence(DatabaseInterface $database, Player $player, string $clubId, SquadRole $role, int $fatigue, bool $controlled): int
    {
        if (!$controlled) {
            return 0;
        }
        $social = (new FootballSocialService())->context($database, $player->id());
        if (($social['manager_club_id'] ?? null) !== $clubId) {
            return 0;
        }
        $score = (int) ($social['manager_score'] ?? 50);
        $influence = match (true) {
            $score >= 85 => 8,
            $score >= 65 => 4,
            $score >= 40 => 0,
            $score >= 20 => -4,
            default => -7,
        };
        if ($fatigue >= 85) {
            $influence -= 2;
        }

        return max(-8, min(8, $influence));
    }

    public function labelForScore(int $score): string
    {
        return match (true) {
            $score >= 85 => 'key_player',
            $score >= 70 => 'important',
            $score >= 55 => 'trusted',
            $score >= 35 => 'developing',
            default => 'unproven',
        };
    }

    private function trustSignal(int $relationshipScore, string $form, string $playingStatus, string $role, array $playing): int
    {
        $signal = $relationshipScore;
        if (in_array($form, ['excellent', 'good', 'strong', 'breakout'], true)) {
            $signal += 4;
        } elseif (in_array($form, ['poor', 'very_poor'], true)) {
            $signal -= 4;
        }
        if ($playingStatus === 'above_expectation') {
            $signal += 3;
        } elseif ($playingStatus === 'severely_below_expectation') {
            $signal -= 4;
        }
        if ((int) ($playing['red_cards'] ?? 0) > 0) {
            $signal -= 3;
        }

        return max(0, min(100, $signal));
    }

    private function trustLabel(int $signal, string $role, string $playingStatus, int $window): string
    {
        if ($window < 2 && in_array($role, ['prospect', 'rotation'], true)) {
            return 'unproven';
        }

        return $this->labelForScore($signal);
    }

    private function expectedUsage(string $role): string
    {
        return match ($role) {
            'key_player' => 'Strong starting expectation',
            'regular' => 'Frequent starts and meaningful minutes',
            'rotation' => 'Regular rotation opportunities',
            'prospect' => 'Limited developmental minutes',
            default => 'No established expectation',
        };
    }

    private function expectedShare(string $role): float
    {
        return match ($role) {
            'key_player' => 0.78,
            'regular' => 0.60,
            'rotation' => 0.35,
            'prospect' => 0.15,
            default => 0.25,
        };
    }

    private function playingTimeStatus(string $role, float $minutesShare, int $window): string
    {
        if ($window < 3) {
            return 'insufficient_evidence';
        }
        $expected = $this->expectedShare($role);
        if ($minutesShare >= min(1.0, $expected + 0.15)) {
            return 'above_expectation';
        }
        if ($minutesShare >= max(0.0, $expected - 0.10)) {
            return 'on_track';
        }
        if ($minutesShare >= $expected * 0.35) {
            return 'below_expectation';
        }

        return 'severely_below_expectation';
    }

    private function competitionStatus(string $role, int $samePosition, int $higher, array $playing): string
    {
        if ($samePosition === 0) {
            return 'position_open';
        }
        if ($role === 'prospect') {
            return ((int) ($playing['appearances'] ?? 0) > 0 || (int) ($playing['starts'] ?? 0) > 0)
                ? 'breaking_through'
                : 'backup';
        }
        if ($higher > 0) {
            return $role === 'rotation' ? 'rotation_battle' : 'competing_for_start';
        }
        if ($role === 'key_player' || $role === 'regular') {
            return 'first_choice';
        }

        return 'rotation_battle';
    }

    /** @return list<string> */
    private function reasons(array $summary, string $playingStatus, string $competitionStatus, string $form, array $playing): array
    {
        $reasons = [];
        if (in_array($form, ['excellent', 'good', 'strong', 'breakout'], true)) {
            $reasons[] = 'Strong recent performances';
        } elseif (in_array($form, ['poor', 'very_poor'], true)) {
            $reasons[] = 'Recent performances need more consistency';
        }
        if ($playingStatus === 'above_expectation') {
            $reasons[] = 'Reliable recent involvement';
        } elseif (in_array($playingStatus, ['below_expectation', 'severely_below_expectation'], true)) {
            $reasons[] = 'Limited recent playing time';
        }
        if (in_array($competitionStatus, ['competing_for_start', 'rotation_battle'], true)) {
            $reasons[] = 'Close competition for your position';
        } elseif ($competitionStatus === 'breaking_through') {
            $reasons[] = 'Progressing toward regular first-team minutes';
        }
        if (($summary['availability'] ?? 'available') === AvailabilityStatus::Unavailable->value) {
            $reasons[] = 'Returning from injury or unavailable';
        }
        if ((int) ($playing['red_cards'] ?? 0) > 0) {
            $reasons[] = 'Recent disciplinary issue';
        }

        return array_values(array_unique(array_slice($reasons, 0, 3)));
    }

    private function feedback(array $summary, string $playingStatus, string $competitionStatus, string $form, int $window): string
    {
        if (($summary['availability'] ?? 'available') === AvailabilityStatus::Unavailable->value) {
            return 'We will manage your minutes while you recover.';
        }
        if ($playingStatus === 'severely_below_expectation' && $window >= 3) {
            return 'You need more consistent performances and more football.';
        }
        if ($playingStatus === 'below_expectation' && in_array($competitionStatus, ['competing_for_start', 'rotation_battle'], true)) {
            return 'Competition for your position is close; keep making your case.';
        }
        if (in_array($form, ['excellent', 'good', 'strong', 'breakout'], true) && $competitionStatus === 'breaking_through') {
            return "You're making a strong case to start.";
        }
        if ($playingStatus === 'on_track' || $playingStatus === 'above_expectation') {
            return 'Your recent performances have been reliable.';
        }

        return 'Keep earning your place through the next football block.';
    }

    private function selectionContext(array $summary, string $competitionStatus, string $playingStatus, string $form): string
    {
        if (($summary['availability'] ?? 'available') === AvailabilityStatus::Unavailable->value) {
            return 'Unavailable — recovery required';
        }
        if ($playingStatus === 'below_expectation' || $playingStatus === 'severely_below_expectation') {
            return 'Selection context — limited recent minutes';
        }
        if (in_array($competitionStatus, ['competing_for_start', 'rotation_battle'], true)) {
            return 'Selection context — close positional competition';
        }
        if (in_array($form, ['excellent', 'good', 'strong', 'breakout'], true)) {
            return 'Selection context — recent form supports involvement';
        }

        return 'Selection context — role and availability apply';
    }
}
