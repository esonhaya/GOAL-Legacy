<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Simulation;

use RuntimeException;

/** Single discoverable catalogue shared by tests, CLI, Doctor, and web Lab. */
final class GoalScenarioCatalog
{
    /** @return list<GoalScenarioDefinition> */
    public function all(): array
    {
        return [
            new GoalScenarioDefinition('HIGH_OVR_STRONG_COMPETITION', 'High OVR / strong competition', 'An OVR 82 central midfielder with two stronger deployable competitors.', 'INTEGRATION', ['goal.player.inspect', 'goal.diagnostics.playing_time'], ['ovr' => 82, 'competitor_ovrs' => [86, 78], 'role' => 'rotation']),
            new GoalScenarioDefinition('HIGH_OVR_WEAK_COMPETITION', 'High OVR / weak competition', 'An OVR 82 central midfielder with weaker deployable competitors.', 'INTEGRATION', ['goal.player.inspect', 'goal.diagnostics.playing_time'], ['ovr' => 82, 'competitor_ovrs' => [74, 70], 'role' => 'rotation']),
            new GoalScenarioDefinition('HEALTHY_LOW_MINUTES', 'Healthy / low minutes', 'A healthy Player available for selection with a rotation role.', 'MICRO', ['goal.player.inspect', 'goal.diagnostics.playing_time'], ['ovr' => 70, 'role' => 'rotation']),
            new GoalScenarioDefinition('INJURY_LOW_MINUTES', 'Injury / low minutes', 'A Player with a canonical active injury.', 'MICRO', ['goal.player.inspect', 'goal.diagnostics.playing_time'], ['ovr' => 70, 'role' => 'rotation', 'availability' => 'injured']),
            new GoalScenarioDefinition('KEY_PLAYER_HEALTHY', 'Key Player / healthy', 'A high-rated healthy Key Player.', 'MICRO', ['goal.player.inspect', 'goal.diagnostics.playing_time'], ['ovr' => 88, 'potential' => 92, 'role' => 'key_player']),
            new GoalScenarioDefinition('PRODIGY_CONTROL', 'Prodigy control', 'A canonical Prodigy development profile for control comparisons.', 'INTEGRATION', ['goal.player.inspect', 'goal.simulation.multi_period'], ['ovr' => 68, 'potential' => 92, 'archetype' => 'prodigy', 'role' => 'prospect']),
            new GoalScenarioDefinition('PRIMARY_POSITION', 'Primary position', 'A Player deployed in their canonical primary position.', 'MICRO', ['goal.player.inspect', 'goal.diagnostics.squad_competition'], ['position' => 'CM', 'role' => 'regular']),
            new GoalScenarioDefinition('SECONDARY_POSITION', 'Secondary position', 'A Player scenario reserved for position-competition inspection.', 'MICRO', ['goal.player.inspect', 'goal.diagnostics.squad_competition'], ['position' => 'CM', 'role' => 'rotation', 'secondary_position' => 'DM']),
            new GoalScenarioDefinition('SUSPENDED_PLAYER', 'Suspended Player', 'A Player with a canonical domestic suspension state.', 'MICRO', ['goal.player.inspect', 'goal.diagnostics.playing_time'], ['ovr' => 70, 'role' => 'rotation', 'availability' => 'suspended']),
            new GoalScenarioDefinition('FATIGUED_PLAYER', 'Fatigued Player', 'A Player with bounded canonical fatigue.', 'MICRO', ['goal.player.inspect', 'goal.diagnostics.playing_time'], ['ovr' => 70, 'role' => 'rotation', 'availability' => 'fatigued']),
            new GoalScenarioDefinition('CONTRACT_EXPIRING', 'Expiring Contract', 'An active Contract at the renewal boundary.', 'MICRO', ['goal.player.inspect', 'goal.diagnostics.mobility'], ['contract' => 'expiring', 'role' => 'rotation']),
            new GoalScenarioDefinition('CONTRACT_LONG_TERM', 'Long-term Contract', 'An active Contract with a longer remaining term.', 'MICRO', ['goal.player.inspect', 'goal.diagnostics.mobility'], ['contract' => 'long_term', 'role' => 'regular']),
            new GoalScenarioDefinition('FREE_AGENT', 'Free Agent', 'A controlled Player with no active Contract or squad membership.', 'MICRO', ['goal.player.inspect', 'goal.diagnostics.mobility'], ['contract' => false, 'role' => 'rotation']),
            new GoalScenarioDefinition('TRANSFER_REQUESTED', 'Transfer requested', 'A canonical controlled-player transfer request in the current window.', 'MICRO', ['goal.player.inspect', 'goal.diagnostics.mobility'], ['movement' => 'transfer_requested', 'role' => 'regular']),
            new GoalScenarioDefinition('LOAN_ACTIVE', 'Active loan', 'An active loan with the parent Contract retained.', 'INTEGRATION', ['goal.player.inspect', 'goal.diagnostics.mobility'], ['movement' => 'loan_active', 'role' => 'prospect']),
            new GoalScenarioDefinition('POST_LOAN_RETURN', 'Post-loan return', 'A completed canonical loan returned to the parent Club.', 'INTEGRATION', ['goal.player.inspect', 'goal.diagnostics.mobility'], ['movement' => 'post_loan_return', 'role' => 'prospect']),
            new GoalScenarioDefinition('PERMANENT_TRANSFER', 'Permanent transfer', 'A completed permanent transfer using canonical membership and Contract owners.', 'INTEGRATION', ['goal.player.inspect', 'goal.diagnostics.mobility'], ['movement' => 'permanent_transfer', 'role' => 'regular']),
        ];
    }

    public function get(string $id): GoalScenarioDefinition
    {
        foreach ($this->all() as $definition) {
            if ($definition->id() === strtoupper(trim($id))) {
                return $definition;
            }
        }

        throw new RuntimeException(sprintf('Unknown GOAL scenario "%s".', $id));
    }

    /** @return list<array<string,mixed>> */
    public function descriptors(): array
    {
        return array_map(static fn (GoalScenarioDefinition $definition): array => $definition->toArray(), $this->all());
    }
}
