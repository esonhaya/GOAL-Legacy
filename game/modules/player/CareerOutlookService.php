<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Modules\World\Domain\SimulationDate;

/** Derives read-only, deterministic guidance from the Career Hub snapshot. */
final class CareerOutlookService
{
    /** @param array<string, mixed> $summary @return array<string, mixed> */
    public function derive(array $summary, SimulationDate $date): array
    {
        $contract = is_array($summary['current_contract'] ?? null) ? $summary['current_contract'] : null;
        $pending = is_array($summary['pending_decisions'] ?? null) ? $summary['pending_decisions'] : [];
        $role = (string) ($summary['current_role'] ?? $summary['squad_role'] ?? '');
        $performance = is_array($summary['latest_season_performance'] ?? null) ? $summary['latest_season_performance'] : null;
        $statistics = is_array($performance['statistics'] ?? null) ? $performance['statistics'] : [];
        $classification = (string) ($performance['classification'] ?? 'insufficient_evidence');
        $minutesShare = (float) ($statistics['minutes_share'] ?? 0.0);
        $startsShare = (float) ($statistics['start_share'] ?? 0.0);
        $position = is_array($summary['position_competition'] ?? null) ? $summary['position_competition'] : null;
        $higherOvrCount = (int) ($position['higher_ovr_count'] ?? 0);
        $opportunity = $this->opportunityLevel($role, $minutesShare, $startsShare, $summary);
        $contractOutlook = $this->contractOutlook($contract, $pending, $date);
        $transferOutlook = $this->transferOutlook($summary, $pending);
        $evidence = [
            'role' => $role === '' ? null : $role,
            'performance' => $classification,
            'minutes_share' => round($minutesShare, 4),
            'starts_share' => round($startsShare, 4),
            'same_position_count' => $position === null ? 0 : (int) ($position['same_position_count'] ?? 0),
            'higher_ovr_count' => $higherOvrCount,
        ];

        if ($contract === null || ($summary['current_club'] ?? null) === null) {
            return $this->result('free_agent', 'Free agent', 'none', 'no_active_contract', 'none', $evidence, [
                $this->guidance('free_agent', 'You are currently a free agent.', null),
            ]);
        }
        if ($contractOutlook === 'pending_decision') {
            return $this->result('contract_uncertainty', 'Contract decision pending', $opportunity, $contractOutlook, $transferOutlook, $evidence, [
                $this->guidance('resolve_contract_decision', 'Your Contract decision is pending.', 'resolve_opportunity'),
            ]);
        }
        if ($transferOutlook === 'interest_pending') {
            return $this->result('transfer_opportunity', 'Transfer interest requires a decision', $opportunity, $contractOutlook, $transferOutlook, $evidence, [
                $this->guidance('resolve_transfer_interest', 'Transfer interest requires a decision.', 'resolve_opportunity'),
            ]);
        }
        if (($summary['transfer_request']['status'] ?? 'none') === 'requested') {
            return $this->result('transfer_requested', 'Transfer request active', $opportunity, $contractOutlook, 'requested', $evidence, [
                $this->guidance('withdraw_transfer_request', 'Your transfer request is active; you can withdraw it.', 'withdraw_transfer_request'),
            ]);
        }
        if ($contractOutlook === 'approaching_decision') {
            return $this->result('contract_uncertainty', 'Contract decision approaching', $opportunity, $contractOutlook, $transferOutlook, $evidence, [
                $this->guidance('contract_review', 'Your Contract decision is approaching.', null),
            ]);
        }

        if ($this->isBreakingThrough($classification, $minutesShare, $role, $summary)) {
            return $this->result('breaking_through', 'Breaking through', $opportunity, $contractOutlook, $transferOutlook, $evidence, [
                $this->guidance('compete_for_role', 'Your recent performance is improving your path to a larger role.', null),
            ]);
        }
        if ($this->isBlocked($role, $opportunity, $classification, $higherOvrCount, $minutesShare)) {
            $actions = $this->hasAction($summary, 'request_transfer')
                ? [$this->guidance('request_transfer', 'More playing time would improve your development opportunity.', 'request_transfer')]
                : [$this->guidance('needs_minutes', 'More playing time would improve your development opportunity.', null)];

            return $this->result('blocked_path', 'Blocked path', $opportunity, $contractOutlook, $transferOutlook, $evidence, $actions);
        }
        if ($opportunity === 'low') {
            $actions = $this->hasAction($summary, 'request_transfer')
                ? [$this->guidance('request_transfer', 'More playing time would improve your development opportunity.', 'request_transfer')]
                : [$this->guidance('needs_minutes', 'More playing time would improve your development opportunity.', null)];

            return $this->result('needs_minutes', 'Needs minutes', $opportunity, $contractOutlook, $transferOutlook, $evidence, $actions);
        }
        if (in_array($role, ['prospect', 'rotation'], true)) {
            return $this->result('competing_for_role', 'Competing for a role', $opportunity, $contractOutlook, $transferOutlook, $evidence, [
                $this->guidance('compete_for_role', 'You are competing for a larger role.', null),
            ]);
        }

        return $this->result('good_situation', 'Good situation', $opportunity, $contractOutlook, $transferOutlook, $evidence, [
            $this->guidance('maintain_progress', 'Your current role and opportunity are stable.', null),
        ]);
    }

    /** @param array<string, mixed> $summary */
    private function opportunityLevel(string $role, float $minutesShare, float $startsShare, array $summary): string
    {
        $recent = is_array($summary['recent_form'] ?? null) ? $summary['recent_form'] : [];
        $recentAppearances = (int) ($recent['appearances'] ?? 0);
        if ($role === 'key_player') {
            return 'high';
        }
        if ($role === 'regular') {
            return $minutesShare >= 0.25 || $startsShare >= 0.15 || $recentAppearances >= 2 ? 'high' : 'moderate';
        }
        if ($role === 'rotation') {
            return $minutesShare >= 0.15 || $startsShare >= 0.10 || $recentAppearances >= 1 ? 'moderate' : 'low';
        }
        if ($role === 'prospect') {
            return $minutesShare >= 0.10 || $startsShare >= 0.05 || $recentAppearances >= 1 ? 'moderate' : 'low';
        }

        return $minutesShare >= 0.20 || $startsShare >= 0.10 ? 'moderate' : 'low';
    }

    /** @param list<array<string, mixed>> $pending */
    private function contractOutlook(?array $contract, array $pending, SimulationDate $date): string
    {
        foreach ($pending as $decision) {
            if (($decision['type'] ?? null) === 'contract_renewal') {
                return 'pending_decision';
            }
        }
        if ($contract === null || ($contract['status'] ?? null) !== 'active' || !is_string($contract['end_date'] ?? null)) {
            return 'no_active_contract';
        }
        $days = $date->daysUntil(SimulationDate::fromIsoString($contract['end_date']));

        return $days >= 0 && $days <= 180 ? 'approaching_decision' : 'secure';
    }

    /** @param list<array<string, mixed>> $pending @param array<string, mixed> $summary */
    private function transferOutlook(array $summary, array $pending): string
    {
        foreach ($pending as $decision) {
            if (($decision['type'] ?? null) === 'transfer_interest') {
                return 'interest_pending';
            }
        }

        return (($summary['transfer_request']['status'] ?? 'none') === 'requested') ? 'requested' : 'none';
    }

    /** @param array<string, mixed> $summary */
    private function isBreakingThrough(string $classification, float $minutesShare, string $role, array $summary): bool
    {
        if (!in_array($classification, ['breakout', 'strong'], true)) {
            return false;
        }
        if ($classification === 'breakout' && $minutesShare >= 0.65) {
            return true;
        }
        $history = is_array($summary['role_history'] ?? null) ? $summary['role_history'] : [];
        $weights = ['prospect' => 80, 'rotation' => 180, 'regular' => 300, 'key_player' => 400];
        $currentWeight = $weights[$role] ?? 0;
        foreach ($history as $entry) {
            if (($weights[$entry['role'] ?? ''] ?? 0) < $currentWeight) {
                return true;
            }
        }

        return $classification === 'strong' && $minutesShare >= 0.45 && in_array($role, ['regular', 'key_player'], true);
    }

    private function isBlocked(string $role, string $opportunity, string $classification, int $higherOvrCount, float $minutesShare): bool
    {
        return in_array($role, ['prospect', 'rotation'], true)
            && $opportunity === 'low'
            && $higherOvrCount >= 2
            && $minutesShare < 0.10
            && !in_array($classification, ['breakout', 'strong'], true);
    }

    /** @param array<string, mixed> $summary */
    private function hasAction(array $summary, string $type): bool
    {
        foreach (($summary['available_actions'] ?? []) as $action) {
            if (($action['type'] ?? null) === $type) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $evidence @param list<array<string, string|null>> $guidance */
    private function result(string $category, string $label, string $opportunity, string $contract, string $transfer, array $evidence, array $guidance): array
    {
        return ['category' => $category, 'label' => $label, 'opportunity_level' => $opportunity, 'contract_outlook' => $contract, 'transfer_outlook' => $transfer, 'evidence' => $evidence, 'guidance' => $guidance];
    }

    /** @return array{code:string,message:string,action_type:string|null} */
    private function guidance(string $code, string $message, ?string $action): array
    {
        return ['code' => $code, 'message' => $message, 'action_type' => $action];
    }
}
