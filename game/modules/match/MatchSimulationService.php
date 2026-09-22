<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Club\ClubCaptaincyService;
use Goal\Legacy\Modules\Club\SetPieceResponsibilityService;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchHighlight;
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\Domain\MatchSimulation;
use Goal\Legacy\Modules\Match\Domain\MatchSubstitution;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\Domain\TeamStrength;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\OnPitchRole;
use Goal\Legacy\Modules\Player\Domain\AvailabilityStatus;
use Goal\Legacy\Modules\Player\CareerRecoveryService;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\OnPitchRoleService;
use Goal\Legacy\Modules\Player\PositionDevelopmentService;
use Goal\Legacy\Modules\Player\PlayerFootService;
use Goal\Legacy\Modules\Player\PlayerAvailabilityService;
use Goal\Legacy\Modules\Match\Domain\SelectionStatus;
use Goal\Legacy\Modules\Match\Domain\SimulationFidelity;

final class MatchSimulationService
{
    private ?OnPitchRoleService $roles = null;

    public function __construct(private readonly ClubService $clubService, private readonly MatchSelectionService $selectionService, private readonly ?PositionDevelopmentService $positions = null, private readonly ?ClubCaptaincyService $captaincy = null, private readonly ?SetPieceResponsibilityService $setPieces = null)
    {
        $this->roles = new OnPitchRoleService();
    }

    public function simulate(DatabaseInterface $database, GameMatch $match, SimulationFidelity $fidelity = SimulationFidelity::Player): MatchSimulation
    {
        $playerRepository = new PlayerRepository($database);
        $controlledPlayers = $fidelity === SimulationFidelity::Player
            ? array_fill_keys((new CareerPlayerRepository($database))->playerIds(), true)
            : [];
        $controlledRoles = $fidelity === SimulationFidelity::Player
            ? $this->roleService()->controlledRoles($database)
            : [];
        $selections = $this->selectionService->select($database, $match, $controlledPlayers);
        $selections = $this->withMatchCaptains($database, $match, $selections, $fidelity);
        $participantIds = [];
        foreach ($selections as $selection) {
            if ($selection->status() === SelectionStatus::Starter || $selection->status() === SelectionStatus::Bench) { $participantIds[] = $selection->playerId(); }
        }
        $playersById = [];
        foreach ($playerRepository->byIds($participantIds) as $player) { $playersById[$player->id()->value()] = $player; }
        $homeStarters = $this->selectedPlayers($selections, $match->homeClubId()->value(), SelectionStatus::Starter, $playersById);
        $awayStarters = $this->selectedPlayers($selections, $match->awayClubId()->value(), SelectionStatus::Starter, $playersById);
        $homeBench = $this->selectedPlayers($selections, $match->homeClubId()->value(), SelectionStatus::Bench, $playersById);
        $awayBench = $this->selectedPlayers($selections, $match->awayClubId()->value(), SelectionStatus::Bench, $playersById);
        $homeSubstitutions = $this->substitutions($database, $match, $match->homeClubId(), $homeStarters, $homeBench, $controlledPlayers, $fidelity);
        $awaySubstitutions = $this->substitutions($database, $match, $match->awayClubId(), $awayStarters, $awayBench, $controlledPlayers, $fidelity);
        $substitutions = array_merge($homeSubstitutions, $awaySubstitutions);
        usort($substitutions, static fn (MatchSubstitution $left, MatchSubstitution $right): int => ($left->minute() <=> $right->minute()) ?: (($left->clubId()->value() <=> $right->clubId()->value()) ?: ($left->sequence() <=> $right->sequence())));
        $homeStrength = $this->strength($database, $match->homeClubId()->value(), $homeStarters);
        $awayStrength = $this->strength($database, $match->awayClubId()->value(), $awayStarters);
        $homeLambda = max(0.2, min(3.2, 1.10 + (($homeStrength->value() - $awayStrength->value()) / 100 * 0.75) + 0.18));
        $awayLambda = max(0.2, min(3.2, 1.00 + (($awayStrength->value() - $homeStrength->value()) / 100 * 0.75)));
        $homeGoals = $this->poisson($homeLambda, $match->id()->value() . '|home');
        $awayGoals = $this->poisson($awayLambda, $match->id()->value() . '|away');
        $foulCounts = []; $yellowCounts = []; $redCounts = []; $dismissals = [];
        $disciplineEvents = array_merge(
            $this->disciplineActions($match->id()->value(), $match->homeClubId()->value(), $homeStarters, $homeSubstitutions, $playersById, $foulCounts, $yellowCounts, $redCounts, $dismissals),
            $this->disciplineActions($match->id()->value(), $match->awayClubId()->value(), $awayStarters, $awaySubstitutions, $playersById, $foulCounts, $yellowCounts, $redCounts, $dismissals),
        );
        $homeSubstitutions = $this->effectiveSubstitutions($homeSubstitutions, $dismissals);
        $awaySubstitutions = $this->effectiveSubstitutions($awaySubstitutions, $dismissals);
        $substitutions = array_merge($homeSubstitutions, $awaySubstitutions);
        usort($substitutions, static fn (MatchSubstitution $left, MatchSubstitution $right): int => ($left->minute() <=> $right->minute()) ?: (($left->clubId()->value() <=> $right->clubId()->value()) ?: ($left->sequence() <=> $right->sequence())));
        $penaltyResolution = $this->penaltyEvents($database, $match, $homeStarters, $homeSubstitutions, $awayStarters, $awaySubstitutions, $playersById, $dismissals, $controlledPlayers, $fidelity);
        $openPlayHomeGoals = $homeGoals;
        $openPlayAwayGoals = $awayGoals;
        $homeGoals += $penaltyResolution['home_goals'];
        $awayGoals += $penaltyResolution['away_goals'];
        $result = new MatchResult($homeGoals, $awayGoals);
        $goalEvents = $penaltyResolution['events'];
        $footService = new PlayerFootService();
        for ($i = 0; $i < $openPlayHomeGoals; $i++) {
            $minute = 1 + (int) floor($this->unit($match->id()->value() . '|home-goal|' . $i) * 89);
            $active = $this->activePlayersAtMinute($homeStarters, $homeSubstitutions, $minute, $playersById, $dismissals);
            $actionKey = 'goal|home|' . $minute . '|' . $i;
            $scorer = $this->scorerAtMinute($match->id()->value(), $active, $minute, $i, $controlledPlayers, $controlledRoles, $actionKey);
            $assist = $this->assistAtMinute($match->id()->value(), $active, $scorer, $minute, $i, $controlledPlayers, $controlledRoles, $actionKey);
            $goalEvents[] = ['club' => $match->homeClubId()->value(), 'player' => $scorer, 'assist' => $assist, 'action_foot' => $scorer !== null && isset($controlledPlayers[$scorer]) ? $footService->actionFoot($playersById[$scorer], $actionKey)->value : null, 'assist_foot' => $assist !== null && isset($controlledPlayers[$assist]) ? $footService->actionFoot($playersById[$assist], $actionKey . '|assist')->value : null, 'minute' => $minute, 'side' => 'home', 'type' => 'goal'];
        }
        for ($i = 0; $i < $openPlayAwayGoals; $i++) {
            $minute = 1 + (int) floor($this->unit($match->id()->value() . '|away-goal|' . $i) * 89);
            $active = $this->activePlayersAtMinute($awayStarters, $awaySubstitutions, $minute, $playersById, $dismissals);
            $actionKey = 'goal|away|' . $minute . '|' . $i;
            $scorer = $this->scorerAtMinute($match->id()->value(), $active, $minute, $i, $controlledPlayers, $controlledRoles, $actionKey);
            $assist = $this->assistAtMinute($match->id()->value(), $active, $scorer, $minute, $i, $controlledPlayers, $controlledRoles, $actionKey);
            $goalEvents[] = ['club' => $match->awayClubId()->value(), 'player' => $scorer, 'assist' => $assist, 'action_foot' => $scorer !== null && isset($controlledPlayers[$scorer]) ? $footService->actionFoot($playersById[$scorer], $actionKey)->value : null, 'assist_foot' => $assist !== null && isset($controlledPlayers[$assist]) ? $footService->actionFoot($playersById[$assist], $actionKey . '|assist')->value : null, 'minute' => $minute, 'side' => 'away', 'type' => 'goal'];
        }
        $shotCounts = [];
        $shotsOnTargetCounts = [];
        $saveCounts = [];
        $cleanSheetCounts = [];
        $tackleCounts = [];
        $interceptionCounts = [];
        $blockCounts = [];
        $fullDetail = $fidelity === SimulationFidelity::Player;
        if ($fullDetail) {
            $this->additionalAttempts($match->id()->value(), 'home', $homeStarters, $homeSubstitutions, $awayStarters, $awaySubstitutions, $playersById, $shotCounts, $shotsOnTargetCounts, $saveCounts, $dismissals, $controlledPlayers, $controlledRoles);
            $this->additionalAttempts($match->id()->value(), 'away', $awayStarters, $awaySubstitutions, $homeStarters, $homeSubstitutions, $playersById, $shotCounts, $shotsOnTargetCounts, $saveCounts, $dismissals, $controlledPlayers, $controlledRoles);
            $this->defensiveActions($match->id()->value(), 'home', $homeStarters, $homeSubstitutions, $playersById, $tackleCounts, $interceptionCounts, $blockCounts, $dismissals, $controlledRoles);
            $this->defensiveActions($match->id()->value(), 'away', $awayStarters, $awaySubstitutions, $playersById, $tackleCounts, $interceptionCounts, $blockCounts, $dismissals, $controlledRoles);
        }
        // Clean-sheet participation is a compact world fact used by the
        // season aggregate path and is cheap enough to retain in both modes.
        $this->applyCleanSheet($awayGoals === 0, $homeStarters, $homeSubstitutions, $playersById, $cleanSheetCounts);
        $this->applyCleanSheet($homeGoals === 0, $awayStarters, $awaySubstitutions, $playersById, $cleanSheetCounts);
        foreach ($disciplineEvents as $event) { $goalEvents[] = $event; }
        foreach ($substitutions as $substitution) {
            $goalEvents[] = ['club' => $substitution->clubId()->value(), 'player' => $substitution->incomingPlayerId()->value(), 'minute' => $substitution->minute(), 'side' => $substitution->clubId()->value(), 'type' => 'substitution', 'substitution' => $substitution];
        }
        usort($goalEvents, static fn (array $a, array $b): int => ($a['minute'] <=> $b['minute']) ?: (($a['type'] === $b['type']) ? strcmp((string) $a['side'], (string) $b['side']) : strcmp((string) $a['type'], (string) $b['type'])));
        $goalCounts = [];
        $assistCounts = [];
        $highlights = [];
        foreach ($goalEvents as $index => $event) {
            if ($event['type'] === 'goal' && $event['player'] !== null) {
                $goalCounts[$event['player']] = ($goalCounts[$event['player']] ?? 0) + 1;
                $shotCounts[$event['player']] = ($shotCounts[$event['player']] ?? 0) + 1;
                $shotsOnTargetCounts[$event['player']] = ($shotsOnTargetCounts[$event['player']] ?? 0) + 1;
                if ($event['assist'] !== null) {
                    $assistCounts[$event['assist']] = ($assistCounts[$event['assist']] ?? 0) + 1;
                }
            } elseif ($event['type'] === 'penalty_missed' && $event['player'] !== null) {
                // A missed/saved penalty is still a canonical shot attempt;
                // it simply contributes no goal or assist.
                $shotCounts[$event['player']] = ($shotCounts[$event['player']] ?? 0) + 1;
            }
            if ($event['type'] === 'substitution') {
                /** @var MatchSubstitution $substitution */
                $substitution = $event['substitution'];
                $highlights[] = new MatchHighlight($match->id(), $index + 1, (int) $event['minute'], 'substitution', new \Goal\Legacy\Modules\Club\Domain\ClubId((string) $event['club']), new \Goal\Legacy\Modules\Player\Domain\PlayerId((string) $event['player']), ['outgoing_player_id' => $substitution->outgoingPlayerId()->value(), 'incoming_player_id' => $substitution->incomingPlayerId()->value(), 'sequence' => $substitution->sequence(), 'reason' => $substitution->reason()]);
            } elseif ($event['type'] === 'yellow_card' || $event['type'] === 'red_card') {
                $highlights[] = new MatchHighlight($match->id(), $index + 1, (int) $event['minute'], $event['type'], new \Goal\Legacy\Modules\Club\Domain\ClubId((string) $event['club']), new \Goal\Legacy\Modules\Player\Domain\PlayerId((string) $event['player']), ['dismissal' => (int) $event['dismissal']]);
            } elseif ($event['type'] === 'penalty_missed') {
                $highlights[] = new MatchHighlight($match->id(), $index + 1, (int) $event['minute'], 'penalty_missed', new \Goal\Legacy\Modules\Club\Domain\ClubId((string) $event['club']), new \Goal\Legacy\Modules\Player\Domain\PlayerId((string) $event['player']), ['set_piece' => 'penalty', 'penalty_awarded' => 1, 'penalty_outcome' => 'missed']);
            } else {
                $highlights[] = new MatchHighlight($match->id(), $index + 1, (int) $event['minute'], 'goal', new \Goal\Legacy\Modules\Club\Domain\ClubId((string) $event['club']), $event['player'] === null ? null : new \Goal\Legacy\Modules\Player\Domain\PlayerId((string) $event['player']), ['side' => $event['side'], 'home_goals' => $homeGoals, 'away_goals' => $awayGoals, 'assist_player_id' => $event['assist'], 'action_foot' => $event['action_foot'] ?? null, 'assist_foot' => $event['assist_foot'] ?? null, 'set_piece' => $event['set_piece'] ?? null, 'penalty_awarded' => $event['penalty_awarded'] ?? null, 'penalty_outcome' => $event['penalty_outcome'] ?? null]);
            }
        }
        $stats = [];
        foreach ($this->participantStats($match, $match->homeClubId(), $homeStarters, $homeSubstitutions, $goalCounts, $assistCounts, $shotCounts, $shotsOnTargetCounts, $saveCounts, $cleanSheetCounts, $tackleCounts, $interceptionCounts, $blockCounts, $foulCounts, $yellowCounts, $redCounts, $dismissals, $playersById, $controlledRoles, $fullDetail) as $stat) { $stats[] = $stat; }
        foreach ($this->participantStats($match, $match->awayClubId(), $awayStarters, $awaySubstitutions, $goalCounts, $assistCounts, $shotCounts, $shotsOnTargetCounts, $saveCounts, $cleanSheetCounts, $tackleCounts, $interceptionCounts, $blockCounts, $foulCounts, $yellowCounts, $redCounts, $dismissals, $playersById, $controlledRoles, $fullDetail) as $stat) { $stats[] = $stat; }
        return new MatchSimulation($result, $stats, $highlights, $selections, $substitutions);
    }

    /**
     * Penalty opportunities are a separate deterministic namespace from open
     * play. Responsibility selects the taker; it never controls occurrence.
     * @return array{events:list<array<string,mixed>>,home_goals:int,away_goals:int}
     */
    private function penaltyEvents(DatabaseInterface $database, GameMatch $match, array $homeStarters, array $homeSubstitutions, array $awayStarters, array $awaySubstitutions, array $playersById, array $dismissals, array $controlledPlayers, SimulationFidelity $fidelity): array
    {
        if ($this->setPieces === null || $fidelity !== SimulationFidelity::Player || !$this->hasControlledParticipant($controlledPlayers, $homeStarters, $homeSubstitutions, $awayStarters, $awaySubstitutions)) {
            return ['events' => [], 'home_goals' => 0, 'away_goals' => 0];
        }
        $events = [];
        $goals = ['home' => 0, 'away' => 0];
        foreach ([
            ['side' => 'home', 'club_id' => $match->homeClubId()->value(), 'starters' => $homeStarters, 'substitutions' => $homeSubstitutions],
            ['side' => 'away', 'club_id' => $match->awayClubId()->value(), 'starters' => $awayStarters, 'substitutions' => $awaySubstitutions],
        ] as $team) {
            $clubId = (string) $team['club_id'];
            $side = (string) $team['side'];
            // A foul can create a penalty opportunity, but this occurrence
            // key is independent from all assignment and execution keys.
            if ($this->unit($match->id()->value() . '|' . $clubId . '|penalty-opportunity') >= 0.12) {
                continue;
            }
            $minute = 1 + (int) floor($this->unit($match->id()->value() . '|' . $clubId . '|penalty-minute') * 89);
            $active = $this->activePlayersAtMinute($team['starters'], $team['substitutions'], $minute, $playersById, $dismissals);
            $taker = $this->setPieces->matchTaker($database, $clubId, $match->seasonId(), array_map(static fn (Player $player): string => $player->id()->value(), $active));
            if ($taker === null) {
                continue;
            }
            $player = $playersById[$taker] ?? null;
            if ($player === null) {
                continue;
            }
            $conversion = min(0.82, max(0.35, 0.45 + ($player->attributes()->shooting() / 250)));
            $scored = $this->unit($match->id()->value() . '|' . $clubId . '|penalty-outcome') < $conversion;
            $event = [
                'club' => $clubId,
                'player' => $taker,
                'assist' => null,
                'action_foot' => null,
                'assist_foot' => null,
                'minute' => $minute,
                'side' => $side,
                'type' => $scored ? 'goal' : 'penalty_missed',
                'set_piece' => 'penalty',
                'penalty_awarded' => 1,
                'penalty_outcome' => $scored ? 'goal' : 'missed',
            ];
            $events[] = $event;
            if ($scored) {
                ++$goals[$side];
            }
        }

        return ['events' => $events, 'home_goals' => $goals['home'], 'away_goals' => $goals['away']];
    }

    private function hasControlledParticipant(array $controlledPlayers, array ...$groups): bool
    {
        foreach ($groups as $players) {
            foreach ($players as $value) {
                $playerId = $value instanceof Player
                    ? $value->id()->value()
                    : ($value instanceof MatchSubstitution ? $value->incomingPlayerId()->value() : null);
                if ($playerId !== null && isset($controlledPlayers[$playerId])) {
                    return true;
                }
                if ($value instanceof MatchSubstitution && isset($controlledPlayers[$value->outgoingPlayerId()->value()])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<\Goal\Legacy\Modules\Match\Domain\PlayerSelection> $selections @return list<\Goal\Legacy\Modules\Match\Domain\PlayerSelection> */
    private function withMatchCaptains(DatabaseInterface $database, GameMatch $match, array $selections, SimulationFidelity $fidelity): array
    {
        if ($this->captaincy === null || $fidelity !== SimulationFidelity::Player) {
            return $selections;
        }
        $result = $selections;
        foreach ([$match->homeClubId()->value(), $match->awayClubId()->value()] as $clubId) {
            $starters = array_values(array_filter($selections, static fn ($selection): bool => $selection->clubId()->value() === $clubId && $selection->status() === SelectionStatus::Starter));
            $captainId = $this->captaincy->matchCaptain($database, $clubId, $match->seasonId(), array_map(static fn ($selection): string => $selection->playerId()->value(), $starters));
            if ($captainId === null) {
                continue;
            }
            foreach ($result as $index => $selection) {
                if ($selection->playerId()->value() === $captainId && $selection->clubId()->value() === $clubId && $selection->status() === SelectionStatus::Starter) {
                    $result[$index] = $selection->asCaptain();
                    break;
                }
            }
        }

        return $result;
    }

    /** @param list<\Goal\Legacy\Modules\Match\Domain\PlayerSelection> $selections @return list<Player> */
    private function selectedPlayers(array $selections, string $clubId, SelectionStatus $status, array $playersById): array
    {
        $result = [];
        foreach ($selections as $selection) {
            if ($selection->clubId()->value() === $clubId && $selection->status() === $status) {
                $player = $playersById[$selection->playerId()->value()] ?? null;
                if ($player !== null) { $result[] = $player; }
            }
        }
        return $result;
    }

    /** @param list<Player> $starters @param list<Player> $bench @param array<string, bool> $controlledPlayers @return list<MatchSubstitution> */
    private function substitutions(DatabaseInterface $database, GameMatch $match, \Goal\Legacy\Modules\Club\Domain\ClubId $clubId, array $starters, array $bench, array $controlledPlayers, SimulationFidelity $fidelity): array
    {
        $count = min(count($starters), count($bench), 1 + (int) floor($this->unit($match->id()->value() . '|' . $clubId->value() . '|substitution-count') * 3));
        if ($count === 0) {
            return [];
        }
        usort($starters, fn (Player $left, Player $right): int => strcmp($this->unitKey($match->id()->value() . '|out|' . $left->id()->value()), $this->unitKey($match->id()->value() . '|out|' . $right->id()->value())));
        usort($bench, fn (Player $left, Player $right): int => strcmp($this->unitKey($match->id()->value() . '|in|' . $left->id()->value()), $this->unitKey($match->id()->value() . '|in|' . $right->id()->value())));

        // Only the detailed controlled-Player path consumes readiness/workload
        // context. World-fidelity Matches retain their compact substitution
        // shape and do not perform an NPC workload scan.
        if ($fidelity === SimulationFidelity::Player) {
            $managed = [];
            foreach ($starters as $starter) {
                $reason = $this->substitutionReason($database, $match, $starter, $controlledPlayers);
                if ($reason !== null) {
                    $managed[] = ['player' => $starter, 'reason' => $reason];
                }
            }
            if ($managed !== [] && $this->unit($match->id()->value() . '|' . $clubId->value() . '|substitution-context-selection') < 0.70) {
                $selected = $managed[0]['player'];
                $starters = array_values(array_filter($starters, static fn (Player $starter): bool => $starter->id()->value() !== $selected->id()->value()));
                array_unshift($starters, $selected);
            }
        }

        $result = [];
        $usedIncoming = [];
        for ($index = 0; $index < $count; ++$index) {
            $outgoing = $starters[$index];
            $incoming = null;
            foreach ($bench as $candidate) {
                if (isset($usedIncoming[$candidate->id()->value()])) {
                    continue;
                }
                if ($this->selectionService->isPositionCompatible($database, $candidate, $outgoing)) {
                    $incoming = $candidate;
                    break;
                }
            }
            if ($incoming === null) {
                foreach ($bench as $candidate) {
                    if (!isset($usedIncoming[$candidate->id()->value()])) {
                        $incoming = $candidate;
                        break;
                    }
                }
            }
            if ($incoming === null) {
                continue;
            }
            $usedIncoming[$incoming->id()->value()] = true;
            $minute = 55 + (int) floor($this->unit($match->id()->value() . '|' . $clubId->value() . '|substitution-minute|' . $index) * 28) + $index;
            $reason = $this->substitutionReason($database, $match, $outgoing, $controlledPlayers) ?? MatchSubstitution::REASON_TACTICAL;
            if (in_array($reason, [MatchSubstitution::REASON_WORKLOAD, MatchSubstitution::REASON_READINESS], true) && $this->unit($match->id()->value() . '|' . $clubId->value() . '|substitution-managed-minute|' . $outgoing->id()->value()) < 0.85) {
                $minute -= 6 + (int) floor($this->unit($match->id()->value() . '|' . $clubId->value() . '|substitution-managed-offset|' . $outgoing->id()->value()) * 6);
            }
            $result[] = new MatchSubstitution($match->id(), $clubId, $index + 1, $outgoing->id(), $incoming->id(), max(45, min(89, $minute)), $reason);
        }

        usort($result, static fn (MatchSubstitution $left, MatchSubstitution $right): int => ($left->minute() <=> $right->minute()) ?: ($left->sequence() <=> $right->sequence()));

        return $result;
    }

    private function substitutionReason(DatabaseInterface $database, GameMatch $match, Player $player, array $controlledPlayers): ?string
    {
        if (!isset($controlledPlayers[$player->id()->value()])) {
            return null;
        }
        $assessment = (new PlayerAvailabilityService())->assess($database, $player->id(), $match->scheduledDate());
        if ($assessment->status() === AvailabilityStatus::Limited) {
            return MatchSubstitution::REASON_WORKLOAD;
        }
        if ($assessment->status() !== AvailabilityStatus::Available) {
            return null;
        }
        $recovery = (new CareerRecoveryService())->context($database, $player->id(), $match->scheduledDate());
        if (in_array((string) ($recovery['phase'] ?? ''), ['RETURNING_TO_TRAINING', 'AVAILABLE_NOT_READY'], true)) {
            return MatchSubstitution::REASON_READINESS;
        }

        return null;
    }

    private function unitKey(string $key): string
    {
        return hash('sha256', $key);
    }

    /** @param list<Player> $starters @param list<MatchSubstitution> $substitutions @param array<string, Player> $playersById @return list<Player> */
    private function activePlayersAtMinute(array $starters, array $substitutions, int $minute, array $playersById, array $dismissals = []): array
    {
        $players = [];
        foreach ($starters as $starter) {
            $active = true;
            foreach ($substitutions as $substitution) {
                if ($substitution->outgoingPlayerId()->value() === $starter->id()->value() && $minute >= $substitution->minute()) {
                    $active = false;
                }
            }
            if (($dismissals[$starter->id()->value()] ?? 91) <= $minute) { $active = false; }
            if ($active) {
                $players[] = $starter;
            }
        }
        foreach ($substitutions as $substitution) {
            $outgoingDismissed = ($dismissals[$substitution->outgoingPlayerId()->value()] ?? 91) <= $substitution->minute();
            if ($minute >= $substitution->minute() && !$outgoingDismissed) {
                $incoming = $playersById[$substitution->incomingPlayerId()->value()] ?? null;
                if ($incoming !== null && ($dismissals[$incoming->id()->value()] ?? 91) > $minute) { $players[] = $incoming; }
            }
        }

        return $players;
    }

    /** @param list<Player> $players */
    private function scorerAtMinute(string $matchId, array $players, int $minute, int $goalIndex, array $controlledPlayers = [], array $controlledRoles = [], string $actionKey = 'goal'): ?string
    {
        if ($players === []) {
            return null;
        }

        return $this->weightedPlayer($players, $matchId . '|scorer|' . $minute . '|' . $goalIndex, function (Player $player) use ($controlledPlayers, $controlledRoles, $actionKey): int {
            $base = match ($player->primaryPosition()->value) {
                'GK' => 1,
                'CB', 'LB', 'RB' => 4,
                'DM' => 7,
                'CM' => 9,
                'AM' => 14,
                'LW', 'RW' => 16,
                'ST' => 20,
            } + intdiv($player->attributes()->shooting(), 5) + intdiv($player->attributes()->dribbling(), 10);
            $base += $this->roleService()->actionTendency($controlledRoles[$player->id()->value()] ?? null, 'shoot');
            if (!isset($controlledPlayers[$player->id()->value()])) { return max(1, $base); }
            $foot = new PlayerFootService();

            return max(1, $base + $foot->executionModifier($player, $foot->actionFoot($player, $actionKey)));
        });
    }

    /** @param list<Player> $players */
    private function assistAtMinute(string $matchId, array $players, ?string $scorer, int $minute, int $goalIndex, array $controlledPlayers = [], array $controlledRoles = [], string $actionKey = 'assist'): ?string
    {
        if ($scorer === null || count($players) < 2 || $this->unit($matchId . '|assist-chance|' . $minute . '|' . $goalIndex) >= 0.65) {
            return null;
        }

        $candidates = array_values(array_filter($players, static fn (Player $player): bool => $player->id()->value() !== $scorer));
        if ($candidates === []) {
            return null;
        }

        return $this->weightedPlayer($candidates, $matchId . '|assist|' . $minute . '|' . $goalIndex, function (Player $player) use ($controlledPlayers, $controlledRoles, $actionKey): int {
            $base = match ($player->primaryPosition()->value) {
                'GK' => 1,
                'CB', 'LB', 'RB' => 5,
                'DM' => 8,
                'CM' => 12,
                'AM' => 16,
                'LW', 'RW' => 14,
                'ST' => 8,
            } + intdiv($player->attributes()->passing(), 5) + intdiv($player->attributes()->dribbling(), 8);
            $base += $this->roleService()->actionTendency($controlledRoles[$player->id()->value()] ?? null, 'assist');
            if (!isset($controlledPlayers[$player->id()->value()])) { return max(1, $base); }
            $foot = new PlayerFootService();

            return max(1, $base + $foot->executionModifier($player, $foot->actionFoot($player, $actionKey . '|assist')));
        });
    }

    /** @param list<Player> $attackers @param list<MatchSubstitution> $attackerSubs @param list<Player> $defenders @param list<MatchSubstitution> $defenderSubs @param array<string, Player> $playersById @param array<string, int> $shotCounts @param array<string, int> $shotsOnTargetCounts @param array<string, int> $saveCounts */
    private function additionalAttempts(string $matchId, string $side, array $attackers, array $attackerSubs, array $defenders, array $defenderSubs, array $playersById, array &$shotCounts, array &$shotsOnTargetCounts, array &$saveCounts, array $dismissals = [], array $controlledPlayers = [], array $controlledRoles = []): void
    {
        $attempts = 4 + (int) floor($this->unit($matchId . '|' . $side . '|shot-count') * 5);
        for ($index = 0; $index < $attempts; ++$index) {
            $minute = 1 + (int) floor($this->unit($matchId . '|' . $side . '|shot-minute|' . $index) * 89);
            $activeAttackers = $this->activePlayersAtMinute($attackers, $attackerSubs, $minute, $playersById, $dismissals);
            $shooter = $this->scorerAtMinute($matchId . '|shot|' . $side, $activeAttackers, $minute, $index, $controlledPlayers, $controlledRoles, 'shot|' . $side . '|' . $minute . '|' . $index);
            if ($shooter === null) { continue; }
            $shotCounts[$shooter] = ($shotCounts[$shooter] ?? 0) + 1;
            $shooterPlayer = $playersById[$shooter] ?? null;
            if ($shooterPlayer === null || $this->unit($matchId . '|' . $side . '|shot-target|' . $index) >= $this->shotOnTargetChance($shooterPlayer)) { continue; }
            $shotsOnTargetCounts[$shooter] = ($shotsOnTargetCounts[$shooter] ?? 0) + 1;
            $goalkeeper = $this->goalkeeperAtMinute($defenders, $defenderSubs, $minute, $playersById, $dismissals);
            if ($goalkeeper !== null) { $saveCounts[$goalkeeper->id()->value()] = ($saveCounts[$goalkeeper->id()->value()] ?? 0) + 1; }
        }
    }

    private function shotOnTargetChance(Player $player): float
    {
        $positionBonus = match ($player->primaryPosition()->value) {
            'ST' => 0.18,
            'LW', 'RW', 'AM' => 0.14,
            'CM', 'DM' => 0.08,
            'CB', 'LB', 'RB' => 0.04,
            'GK' => 0.01,
        };

        return min(0.75, 0.18 + $positionBonus + ($player->attributes()->shooting() / 500));
    }

    /** @param list<Player> $starters @param list<MatchSubstitution> $substitutions @param array<string, Player> $playersById */
    private function goalkeeperAtMinute(array $starters, array $substitutions, int $minute, array $playersById, array $dismissals = []): ?Player
    {
        foreach ($this->activePlayersAtMinute($starters, $substitutions, $minute, $playersById, $dismissals) as $player) {
            if ($player->primaryPosition()->value === 'GK') { return $player; }
        }

        return null;
    }

    /** @param list<Player> $starters @param list<MatchSubstitution> $substitutions @param array<string, Player> $playersById @param array<string, int> $cleanSheetCounts */
    private function applyCleanSheet(bool $cleanSheet, array $starters, array $substitutions, array $playersById, array &$cleanSheetCounts): void
    {
        if (!$cleanSheet) { return; }
        $participants = $this->participatingPlayers($starters, $substitutions, $playersById);
        if (array_filter($participants, static fn (Player $player): bool => $player->primaryPosition()->value === 'GK') === []) { return; }
        foreach ($participants as $player) {
            if (in_array($player->primaryPosition()->value, ['GK', 'CB', 'LB', 'RB'], true)) { $cleanSheetCounts[$player->id()->value()] = 1; }
        }
    }

    /** @param list<Player> $starters @param list<MatchSubstitution> $substitutions @param array<string, Player> $playersById @param array<string, int> $tackles @param array<string, int> $interceptions @param array<string, int> $blocks */
    private function defensiveActions(string $matchId, string $side, array $starters, array $substitutions, array $playersById, array &$tackles, array &$interceptions, array &$blocks, array $dismissals = [], array $controlledRoles = []): void
    {
        foreach (['tackle' => 3, 'interception' => 2, 'block' => 1] as $type => $minimum) {
            $count = $minimum + (int) floor($this->unit($matchId . '|' . $side . '|defensive-' . $type . '-count') * 3);
            for ($index = 0; $index < $count; ++$index) {
                $minute = 1 + (int) floor($this->unit($matchId . '|' . $side . '|defensive-' . $type . '-minute|' . $index) * 89);
                $active = array_values(array_filter($this->activePlayersAtMinute($starters, $substitutions, $minute, $playersById, $dismissals), static fn (Player $player): bool => $player->primaryPosition()->value !== 'GK'));
                if ($active === []) { continue; }
                $playerId = $this->weightedPlayer($active, $matchId . '|' . $side . '|defensive-' . $type . '|player|' . $minute . '|' . $index, fn (Player $player): int => $this->defensiveWeight($player, $type, $controlledRoles[$player->id()->value()] ?? null));
                match ($type) {
                    'tackle' => $tackles[$playerId] = ($tackles[$playerId] ?? 0) + 1,
                    'interception' => $interceptions[$playerId] = ($interceptions[$playerId] ?? 0) + 1,
                    'block' => $blocks[$playerId] = ($blocks[$playerId] ?? 0) + 1,
                };
            }
        }
    }

    private function defensiveWeight(Player $player, string $type, ?OnPitchRole $role = null): int
    {
        $position = match ($player->primaryPosition()->value) { 'CB' => 20, 'LB', 'RB' => 16, 'DM' => 17, 'CM' => 11, 'AM' => 7, 'LW', 'RW' => 5, 'ST' => 3, 'GK' => 0 };
        $attributes = $player->attributes();
        return max(1, $position + intdiv($attributes->defending(), 3) + ($type === 'tackle' ? intdiv($attributes->physicality(), 4) : 0) + ($type === 'block' ? intdiv($attributes->physicality(), 8) : 0) + $this->roleService()->actionTendency($role, 'defend'));
    }

    /** @return list<array{club:string,player:string,minute:int,type:string,dismissal:int,side:string}> */
    private function disciplineActions(string $matchId, string $clubId, array $starters, array $substitutions, array $playersById, array &$fouls, array &$yellows, array &$reds, array &$dismissals): array
    {
        $candidates = [];
        $count = 2 + (int) floor($this->unit($matchId . '|' . $clubId . '|foul-count') * 4);
        for ($index = 0; $index < $count; ++$index) {
            $candidates[] = ['minute' => 1 + (int) floor($this->unit($matchId . '|' . $clubId . '|foul-minute|' . $index) * 89), 'index' => $index];
        }
        usort($candidates, static fn (array $left, array $right): int => ($left['minute'] <=> $right['minute']) ?: ($left['index'] <=> $right['index']));
        $events = [];
        foreach ($candidates as $candidate) {
            $minute = $candidate['minute']; $index = $candidate['index'];
            $active = $this->activePlayersAtMinute($starters, $substitutions, $minute, $playersById, $dismissals);
            if ($active === []) { continue; }
            $playerId = $this->weightedPlayer($active, $matchId . '|' . $clubId . '|foul-player|' . $minute . '|' . $index, fn (Player $player): int => $this->foulWeight($player));
            $fouls[$playerId] = ($fouls[$playerId] ?? 0) + 1;
            $directRed = $this->unit($matchId . '|' . $clubId . '|direct-red|' . $index) < 0.025;
            $yellow = !$directRed && $this->unit($matchId . '|' . $clubId . '|yellow|' . $index) < 0.22;
            if ($directRed) {
                $reds[$playerId] = 1; $dismissals[$playerId] = $minute;
                $events[] = ['club' => $clubId, 'player' => $playerId, 'minute' => $minute, 'type' => 'red_card', 'dismissal' => 1, 'side' => $clubId];
            } elseif ($yellow) {
                $yellows[$playerId] = ($yellows[$playerId] ?? 0) + 1;
                $dismissal = $yellows[$playerId] >= 2;
                if ($dismissal) { $reds[$playerId] = 1; $dismissals[$playerId] = $minute; }
                $events[] = ['club' => $clubId, 'player' => $playerId, 'minute' => $minute, 'type' => 'yellow_card', 'dismissal' => $dismissal ? 1 : 0, 'side' => $clubId];
                if ($dismissal) { $events[] = ['club' => $clubId, 'player' => $playerId, 'minute' => $minute, 'type' => 'red_card', 'dismissal' => 1, 'side' => $clubId]; }
            }
        }

        return $events;
    }

    private function foulWeight(Player $player): int
    {
        $position = match ($player->primaryPosition()->value) { 'GK' => 3, 'CB', 'LB', 'RB' => 9, 'DM' => 10, 'CM', 'AM' => 7, 'LW', 'RW' => 5, 'ST' => 4 };
        return max(1, $position + intdiv($player->attributes()->physicality(), 12) + intdiv($player->attributes()->defending(), 15));
    }

    /** @return list<MatchSubstitution> */
    private function effectiveSubstitutions(array $substitutions, array $dismissals): array
    {
        return array_values(array_filter($substitutions, static fn (MatchSubstitution $substitution): bool => ($dismissals[$substitution->outgoingPlayerId()->value()] ?? 91) > $substitution->minute()));
    }

    /** @param list<Player> $starters @param list<MatchSubstitution> $substitutions @param array<string, Player> $playersById @return list<Player> */
    private function participatingPlayers(array $starters, array $substitutions, array $playersById): array
    {
        $players = $starters;
        foreach ($substitutions as $substitution) {
            $incoming = $playersById[$substitution->incomingPlayerId()->value()] ?? null;
            if ($incoming !== null) { $players[] = $incoming; }
        }

        return $players;
    }

    /** @param list<Player> $players @param callable(Player): int $weight */
    private function weightedPlayer(array $players, string $key, callable $weight): string
    {
        $weights = array_map($weight, $players);
        $target = $this->unit($key) * array_sum($weights);
        foreach ($players as $index => $player) {
            $target -= $weights[$index];
            if ($target < 0) {
                return $player->id()->value();
            }
        }

        return $players[array_key_last($players)]->id()->value();
    }

    /** @param list<Player> $starters @param list<MatchSubstitution> $substitutions @param array<string, int> $goalCounts @param array<string, int> $assistCounts @param array<string, int> $shotCounts @param array<string, int> $shotsOnTargetCounts @param array<string, int> $saveCounts @param array<string, int> $cleanSheetCounts @param array<string, int> $tackleCounts @param array<string, int> $interceptionCounts @param array<string, int> $blockCounts @return list<PlayerMatchStat> */
    private function participantStats(GameMatch $match, \Goal\Legacy\Modules\Club\Domain\ClubId $clubId, array $starters, array $substitutions, array $goalCounts, array $assistCounts, array $shotCounts, array $shotsOnTargetCounts, array $saveCounts, array $cleanSheetCounts, array $tackleCounts, array $interceptionCounts, array $blockCounts, array $foulCounts, array $yellowCounts, array $redCounts, array $dismissals, array $playersById, array $controlledRoles = [], bool $fullDetail = true): array
    {
        $outgoingMinutes = [];
        foreach ($substitutions as $substitution) {
            $outgoingMinutes[$substitution->outgoingPlayerId()->value()] = $substitution->minute();
        }
        $stats = [];
        foreach ($starters as $starter) {
            $id = $starter->id()->value();
            $minutes = min($outgoingMinutes[$id] ?? 90, $dismissals[$id] ?? 90);
            [$attempted, $completed] = $fullDetail ? $this->passingEvidence($match->id()->value(), $starter, $minutes, $controlledRoles[$id] ?? null) : [0, 0];
            $stats[] = new PlayerMatchStat($match->id(), $starter->id(), $clubId, true, true, $minutes, $goalCounts[$id] ?? 0, $assistCounts[$id] ?? 0, $shotCounts[$id] ?? 0, $shotsOnTargetCounts[$id] ?? 0, $saveCounts[$id] ?? 0, $cleanSheetCounts[$id] ?? 0, $tackleCounts[$id] ?? 0, $interceptionCounts[$id] ?? 0, $blockCounts[$id] ?? 0, $attempted, $completed, $foulCounts[$id] ?? 0, $yellowCounts[$id] ?? 0, $redCounts[$id] ?? 0);
        }
        foreach ($substitutions as $substitution) {
            $incoming = $playersById[$substitution->incomingPlayerId()->value()] ?? null;
            if ($incoming === null) { continue; }
            $id = $incoming->id()->value();
            $minutes = max(0, min(90, $dismissals[$id] ?? 90) - $substitution->minute());
            if ($minutes < 1) { continue; }
            [$attempted, $completed] = $fullDetail ? $this->passingEvidence($match->id()->value(), $incoming, $minutes, $controlledRoles[$id] ?? null) : [0, 0];
            $stats[] = new PlayerMatchStat($match->id(), $incoming->id(), $clubId, true, false, $minutes, $goalCounts[$id] ?? 0, $assistCounts[$id] ?? 0, $shotCounts[$id] ?? 0, $shotsOnTargetCounts[$id] ?? 0, $saveCounts[$id] ?? 0, $cleanSheetCounts[$id] ?? 0, $tackleCounts[$id] ?? 0, $interceptionCounts[$id] ?? 0, $blockCounts[$id] ?? 0, $attempted, $completed, $foulCounts[$id] ?? 0, $yellowCounts[$id] ?? 0, $redCounts[$id] ?? 0);
        }

        return $stats;
    }

    /** @return array{int, int} */
    private function passingEvidence(string $matchId, Player $player, int $minutes, ?OnPitchRole $role = null): array
    {
        if ($minutes < 1) { return [0, 0]; }
        $involvement = match ($player->primaryPosition()->value) {
            'GK' => 22, 'CB', 'LB', 'RB' => 38, 'DM' => 55, 'CM' => 58,
            'AM' => 52, 'LW', 'RW' => 38, 'ST' => 32,
        } + $this->roleService()->actionTendency($role, 'pass');
        $variation = (int) floor($this->unit($matchId . '|passing-volume|' . $player->id()->value()) * 7) - 3;
        $attempted = max(1, (int) round(max(1, $involvement + $variation) * $minutes / 90));
        $completionRate = min(0.95, max(0.55, 0.58 + ($player->attributes()->passing() / 250) + (($this->unit($matchId . '|passing-completion|' . $player->id()->value()) - 0.5) * 0.06)));
        $completed = min($attempted, max(0, (int) floor($attempted * $completionRate)));

        return [$attempted, $completed];
    }

    /** @param list<Player> $players */
    public function strength(DatabaseInterface $database, string $clubId, array $players = []): TeamStrength
    {
        $base = 0;
        if (str_starts_with($clubId, 'national-team-')) {
            $statement = $database->connection()->prepare('SELECT strength FROM national_team_records WHERE id = :id');
            $statement->execute(['id' => $clubId]);
            $base = (int) ($statement->fetchColumn() ?: 0);
        } else {
            $base = $this->clubService->repository($database)->get($clubId)->reputation();
        }
        if ($players === []) { return new TeamStrength($base, false); }
        $average = (int) floor(array_sum(array_map(static fn (Player $player): int => $player->overallRating(), $players)) / count($players));
        return new TeamStrength((int) floor(($base + $average) / 2), true);
    }

    private function poisson(float $lambda, string $key): int { $u = max(0.0000001, $this->unit($key)); $probability = exp(-$lambda); $cumulative = $probability; $goals = 0; while ($u > $cumulative && $goals < 12) { $goals++; $probability *= $lambda / $goals; $cumulative += $probability; } return min(12, $goals); }
    private function unit(string $key): float { return hexdec(substr(hash('sha256', $key), 0, 12)) / 281474976710655; }

    private function roleService(): OnPitchRoleService
    {
        return $this->roles ??= new OnPitchRoleService();
    }
}
