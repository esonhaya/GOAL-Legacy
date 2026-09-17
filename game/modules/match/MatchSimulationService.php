<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchHighlight;
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\Domain\MatchSimulation;
use Goal\Legacy\Modules\Match\Domain\MatchSubstitution;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\Domain\TeamStrength;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Match\Domain\SelectionStatus;
use Goal\Legacy\Modules\Match\Domain\SimulationFidelity;

final class MatchSimulationService
{
    public function __construct(private readonly ClubService $clubService, private readonly MatchSelectionService $selectionService) {}

    public function simulate(DatabaseInterface $database, GameMatch $match, SimulationFidelity $fidelity = SimulationFidelity::Player): MatchSimulation
    {
        $playerRepository = new PlayerRepository($database);
        $selections = $this->selectionService->select($database, $match);
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
        $homeSubstitutions = $this->substitutions($match, $match->homeClubId(), $homeStarters, $homeBench);
        $awaySubstitutions = $this->substitutions($match, $match->awayClubId(), $awayStarters, $awayBench);
        $substitutions = array_merge($homeSubstitutions, $awaySubstitutions);
        usort($substitutions, static fn (MatchSubstitution $left, MatchSubstitution $right): int => ($left->minute() <=> $right->minute()) ?: (($left->clubId()->value() <=> $right->clubId()->value()) ?: ($left->sequence() <=> $right->sequence())));
        $homeStrength = $this->strength($database, $match->homeClubId()->value(), $homeStarters);
        $awayStrength = $this->strength($database, $match->awayClubId()->value(), $awayStarters);
        $homeLambda = max(0.2, min(3.2, 1.10 + (($homeStrength->value() - $awayStrength->value()) / 100 * 0.75) + 0.18));
        $awayLambda = max(0.2, min(3.2, 1.00 + (($awayStrength->value() - $homeStrength->value()) / 100 * 0.75)));
        $homeGoals = $this->poisson($homeLambda, $match->id()->value() . '|home');
        $awayGoals = $this->poisson($awayLambda, $match->id()->value() . '|away');
        $result = new MatchResult($homeGoals, $awayGoals);
        $foulCounts = []; $yellowCounts = []; $redCounts = []; $dismissals = [];
        $disciplineEvents = array_merge(
            $this->disciplineActions($match->id()->value(), $match->homeClubId()->value(), $homeStarters, $homeSubstitutions, $playersById, $foulCounts, $yellowCounts, $redCounts, $dismissals),
            $this->disciplineActions($match->id()->value(), $match->awayClubId()->value(), $awayStarters, $awaySubstitutions, $playersById, $foulCounts, $yellowCounts, $redCounts, $dismissals),
        );
        $homeSubstitutions = $this->effectiveSubstitutions($homeSubstitutions, $dismissals);
        $awaySubstitutions = $this->effectiveSubstitutions($awaySubstitutions, $dismissals);
        $substitutions = array_merge($homeSubstitutions, $awaySubstitutions);
        usort($substitutions, static fn (MatchSubstitution $left, MatchSubstitution $right): int => ($left->minute() <=> $right->minute()) ?: (($left->clubId()->value() <=> $right->clubId()->value()) ?: ($left->sequence() <=> $right->sequence())));
        $goalEvents = [];
        for ($i = 0; $i < $homeGoals; $i++) {
            $minute = 1 + (int) floor($this->unit($match->id()->value() . '|home-goal|' . $i) * 89);
            $active = $this->activePlayersAtMinute($homeStarters, $homeSubstitutions, $minute, $playersById, $dismissals);
            $scorer = $this->scorerAtMinute($match->id()->value(), $active, $minute, $i);
            $goalEvents[] = ['club' => $match->homeClubId()->value(), 'player' => $scorer, 'assist' => $this->assistAtMinute($match->id()->value(), $active, $scorer, $minute, $i), 'minute' => $minute, 'side' => 'home', 'type' => 'goal'];
        }
        for ($i = 0; $i < $awayGoals; $i++) {
            $minute = 1 + (int) floor($this->unit($match->id()->value() . '|away-goal|' . $i) * 89);
            $active = $this->activePlayersAtMinute($awayStarters, $awaySubstitutions, $minute, $playersById, $dismissals);
            $scorer = $this->scorerAtMinute($match->id()->value(), $active, $minute, $i);
            $goalEvents[] = ['club' => $match->awayClubId()->value(), 'player' => $scorer, 'assist' => $this->assistAtMinute($match->id()->value(), $active, $scorer, $minute, $i), 'minute' => $minute, 'side' => 'away', 'type' => 'goal'];
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
            $this->additionalAttempts($match->id()->value(), 'home', $homeStarters, $homeSubstitutions, $awayStarters, $awaySubstitutions, $playersById, $shotCounts, $shotsOnTargetCounts, $saveCounts, $dismissals);
            $this->additionalAttempts($match->id()->value(), 'away', $awayStarters, $awaySubstitutions, $homeStarters, $homeSubstitutions, $playersById, $shotCounts, $shotsOnTargetCounts, $saveCounts, $dismissals);
            $this->defensiveActions($match->id()->value(), 'home', $homeStarters, $homeSubstitutions, $playersById, $tackleCounts, $interceptionCounts, $blockCounts, $dismissals);
            $this->defensiveActions($match->id()->value(), 'away', $awayStarters, $awaySubstitutions, $playersById, $tackleCounts, $interceptionCounts, $blockCounts, $dismissals);
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
            }
            if ($event['type'] === 'substitution') {
                /** @var MatchSubstitution $substitution */
                $substitution = $event['substitution'];
                $highlights[] = new MatchHighlight($match->id(), $index + 1, (int) $event['minute'], 'substitution', new \Goal\Legacy\Modules\Club\Domain\ClubId((string) $event['club']), new \Goal\Legacy\Modules\Player\Domain\PlayerId((string) $event['player']), ['outgoing_player_id' => $substitution->outgoingPlayerId()->value(), 'incoming_player_id' => $substitution->incomingPlayerId()->value(), 'sequence' => $substitution->sequence()]);
            } elseif ($event['type'] === 'yellow_card' || $event['type'] === 'red_card') {
                $highlights[] = new MatchHighlight($match->id(), $index + 1, (int) $event['minute'], $event['type'], new \Goal\Legacy\Modules\Club\Domain\ClubId((string) $event['club']), new \Goal\Legacy\Modules\Player\Domain\PlayerId((string) $event['player']), ['dismissal' => (int) $event['dismissal']]);
            } else {
                $highlights[] = new MatchHighlight($match->id(), $index + 1, (int) $event['minute'], 'goal', new \Goal\Legacy\Modules\Club\Domain\ClubId((string) $event['club']), $event['player'] === null ? null : new \Goal\Legacy\Modules\Player\Domain\PlayerId((string) $event['player']), ['side' => $event['side'], 'home_goals' => $homeGoals, 'away_goals' => $awayGoals, 'assist_player_id' => $event['assist']]);
            }
        }
        $stats = [];
        foreach ($this->participantStats($match, $match->homeClubId(), $homeStarters, $homeSubstitutions, $goalCounts, $assistCounts, $shotCounts, $shotsOnTargetCounts, $saveCounts, $cleanSheetCounts, $tackleCounts, $interceptionCounts, $blockCounts, $foulCounts, $yellowCounts, $redCounts, $dismissals, $playersById, $fullDetail) as $stat) { $stats[] = $stat; }
        foreach ($this->participantStats($match, $match->awayClubId(), $awayStarters, $awaySubstitutions, $goalCounts, $assistCounts, $shotCounts, $shotsOnTargetCounts, $saveCounts, $cleanSheetCounts, $tackleCounts, $interceptionCounts, $blockCounts, $foulCounts, $yellowCounts, $redCounts, $dismissals, $playersById, $fullDetail) as $stat) { $stats[] = $stat; }
        return new MatchSimulation($result, $stats, $highlights, $selections, $substitutions);
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

    /** @param list<Player> $starters @param list<Player> $bench @return list<MatchSubstitution> */
    private function substitutions(GameMatch $match, \Goal\Legacy\Modules\Club\Domain\ClubId $clubId, array $starters, array $bench): array
    {
        $count = min(count($starters), count($bench), 1 + (int) floor($this->unit($match->id()->value() . '|' . $clubId->value() . '|substitution-count') * 3));
        if ($count === 0) {
            return [];
        }
        usort($starters, fn (Player $left, Player $right): int => strcmp($this->unitKey($match->id()->value() . '|out|' . $left->id()->value()), $this->unitKey($match->id()->value() . '|out|' . $right->id()->value())));
        usort($bench, fn (Player $left, Player $right): int => strcmp($this->unitKey($match->id()->value() . '|in|' . $left->id()->value()), $this->unitKey($match->id()->value() . '|in|' . $right->id()->value())));
        $result = [];
        for ($index = 0; $index < $count; ++$index) {
            $minute = 55 + (int) floor($this->unit($match->id()->value() . '|' . $clubId->value() . '|substitution-minute|' . $index) * 28) + $index;
            $result[] = new MatchSubstitution($match->id(), $clubId, $index + 1, $starters[$index]->id(), $bench[$index]->id(), min(89, $minute));
        }

        usort($result, static fn (MatchSubstitution $left, MatchSubstitution $right): int => ($left->minute() <=> $right->minute()) ?: ($left->sequence() <=> $right->sequence()));

        return $result;
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
    private function scorerAtMinute(string $matchId, array $players, int $minute, int $goalIndex): ?string
    {
        if ($players === []) {
            return null;
        }

        return $this->weightedPlayer($players, $matchId . '|scorer|' . $minute . '|' . $goalIndex, static fn (Player $player): int => max(1, match ($player->primaryPosition()->value) {
            'GK' => 1,
            'CB', 'LB', 'RB' => 4,
            'DM' => 7,
            'CM' => 9,
            'AM' => 14,
            'LW', 'RW' => 16,
            'ST' => 20,
        } + intdiv($player->attributes()->shooting(), 5) + intdiv($player->attributes()->dribbling(), 10)));
    }

    /** @param list<Player> $players */
    private function assistAtMinute(string $matchId, array $players, ?string $scorer, int $minute, int $goalIndex): ?string
    {
        if ($scorer === null || count($players) < 2 || $this->unit($matchId . '|assist-chance|' . $minute . '|' . $goalIndex) >= 0.65) {
            return null;
        }

        $candidates = array_values(array_filter($players, static fn (Player $player): bool => $player->id()->value() !== $scorer));
        if ($candidates === []) {
            return null;
        }

        return $this->weightedPlayer($candidates, $matchId . '|assist|' . $minute . '|' . $goalIndex, static fn (Player $player): int => max(1, match ($player->primaryPosition()->value) {
            'GK' => 1,
            'CB', 'LB', 'RB' => 5,
            'DM' => 8,
            'CM' => 12,
            'AM' => 16,
            'LW', 'RW' => 14,
            'ST' => 8,
        } + intdiv($player->attributes()->passing(), 5) + intdiv($player->attributes()->dribbling(), 8)));
    }

    /** @param list<Player> $attackers @param list<MatchSubstitution> $attackerSubs @param list<Player> $defenders @param list<MatchSubstitution> $defenderSubs @param array<string, Player> $playersById @param array<string, int> $shotCounts @param array<string, int> $shotsOnTargetCounts @param array<string, int> $saveCounts */
    private function additionalAttempts(string $matchId, string $side, array $attackers, array $attackerSubs, array $defenders, array $defenderSubs, array $playersById, array &$shotCounts, array &$shotsOnTargetCounts, array &$saveCounts, array $dismissals = []): void
    {
        $attempts = 4 + (int) floor($this->unit($matchId . '|' . $side . '|shot-count') * 5);
        for ($index = 0; $index < $attempts; ++$index) {
            $minute = 1 + (int) floor($this->unit($matchId . '|' . $side . '|shot-minute|' . $index) * 89);
            $activeAttackers = $this->activePlayersAtMinute($attackers, $attackerSubs, $minute, $playersById, $dismissals);
            $shooter = $this->scorerAtMinute($matchId . '|shot|' . $side, $activeAttackers, $minute, $index);
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
    private function defensiveActions(string $matchId, string $side, array $starters, array $substitutions, array $playersById, array &$tackles, array &$interceptions, array &$blocks, array $dismissals = []): void
    {
        foreach (['tackle' => 3, 'interception' => 2, 'block' => 1] as $type => $minimum) {
            $count = $minimum + (int) floor($this->unit($matchId . '|' . $side . '|defensive-' . $type . '-count') * 3);
            for ($index = 0; $index < $count; ++$index) {
                $minute = 1 + (int) floor($this->unit($matchId . '|' . $side . '|defensive-' . $type . '-minute|' . $index) * 89);
                $active = array_values(array_filter($this->activePlayersAtMinute($starters, $substitutions, $minute, $playersById, $dismissals), static fn (Player $player): bool => $player->primaryPosition()->value !== 'GK'));
                if ($active === []) { continue; }
                $playerId = $this->weightedPlayer($active, $matchId . '|' . $side . '|defensive-' . $type . '|player|' . $minute . '|' . $index, fn (Player $player): int => $this->defensiveWeight($player, $type));
                match ($type) {
                    'tackle' => $tackles[$playerId] = ($tackles[$playerId] ?? 0) + 1,
                    'interception' => $interceptions[$playerId] = ($interceptions[$playerId] ?? 0) + 1,
                    'block' => $blocks[$playerId] = ($blocks[$playerId] ?? 0) + 1,
                };
            }
        }
    }

    private function defensiveWeight(Player $player, string $type): int
    {
        $position = match ($player->primaryPosition()->value) { 'CB' => 20, 'LB', 'RB' => 16, 'DM' => 17, 'CM' => 11, 'AM' => 7, 'LW', 'RW' => 5, 'ST' => 3, 'GK' => 0 };
        $attributes = $player->attributes();
        return max(1, $position + intdiv($attributes->defending(), 3) + ($type === 'tackle' ? intdiv($attributes->physicality(), 4) : 0) + ($type === 'block' ? intdiv($attributes->physicality(), 8) : 0));
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
    private function participantStats(GameMatch $match, \Goal\Legacy\Modules\Club\Domain\ClubId $clubId, array $starters, array $substitutions, array $goalCounts, array $assistCounts, array $shotCounts, array $shotsOnTargetCounts, array $saveCounts, array $cleanSheetCounts, array $tackleCounts, array $interceptionCounts, array $blockCounts, array $foulCounts, array $yellowCounts, array $redCounts, array $dismissals, array $playersById, bool $fullDetail = true): array
    {
        $outgoingMinutes = [];
        foreach ($substitutions as $substitution) {
            $outgoingMinutes[$substitution->outgoingPlayerId()->value()] = $substitution->minute();
        }
        $stats = [];
        foreach ($starters as $starter) {
            $id = $starter->id()->value();
            $minutes = min($outgoingMinutes[$id] ?? 90, $dismissals[$id] ?? 90);
            [$attempted, $completed] = $fullDetail ? $this->passingEvidence($match->id()->value(), $starter, $minutes) : [0, 0];
            $stats[] = new PlayerMatchStat($match->id(), $starter->id(), $clubId, true, true, $minutes, $goalCounts[$id] ?? 0, $assistCounts[$id] ?? 0, $shotCounts[$id] ?? 0, $shotsOnTargetCounts[$id] ?? 0, $saveCounts[$id] ?? 0, $cleanSheetCounts[$id] ?? 0, $tackleCounts[$id] ?? 0, $interceptionCounts[$id] ?? 0, $blockCounts[$id] ?? 0, $attempted, $completed, $foulCounts[$id] ?? 0, $yellowCounts[$id] ?? 0, $redCounts[$id] ?? 0);
        }
        foreach ($substitutions as $substitution) {
            $incoming = $playersById[$substitution->incomingPlayerId()->value()] ?? null;
            if ($incoming === null) { continue; }
            $id = $incoming->id()->value();
            $minutes = max(0, min(90, $dismissals[$id] ?? 90) - $substitution->minute());
            if ($minutes < 1) { continue; }
            [$attempted, $completed] = $fullDetail ? $this->passingEvidence($match->id()->value(), $incoming, $minutes) : [0, 0];
            $stats[] = new PlayerMatchStat($match->id(), $incoming->id(), $clubId, true, false, $minutes, $goalCounts[$id] ?? 0, $assistCounts[$id] ?? 0, $shotCounts[$id] ?? 0, $shotsOnTargetCounts[$id] ?? 0, $saveCounts[$id] ?? 0, $cleanSheetCounts[$id] ?? 0, $tackleCounts[$id] ?? 0, $interceptionCounts[$id] ?? 0, $blockCounts[$id] ?? 0, $attempted, $completed, $foulCounts[$id] ?? 0, $yellowCounts[$id] ?? 0, $redCounts[$id] ?? 0);
        }

        return $stats;
    }

    /** @return array{int, int} */
    private function passingEvidence(string $matchId, Player $player, int $minutes): array
    {
        if ($minutes < 1) { return [0, 0]; }
        $involvement = match ($player->primaryPosition()->value) {
            'GK' => 22, 'CB', 'LB', 'RB' => 38, 'DM' => 55, 'CM' => 58,
            'AM' => 52, 'LW', 'RW' => 38, 'ST' => 32,
        };
        $variation = (int) floor($this->unit($matchId . '|passing-volume|' . $player->id()->value()) * 7) - 3;
        $attempted = max(1, (int) round(max(1, $involvement + $variation) * $minutes / 90));
        $completionRate = min(0.95, max(0.55, 0.58 + ($player->attributes()->passing() / 250) + (($this->unit($matchId . '|passing-completion|' . $player->id()->value()) - 0.5) * 0.06)));
        $completed = min($attempted, max(0, (int) floor($attempted * $completionRate)));

        return [$attempted, $completed];
    }

    /** @param list<Player> $players */
    public function strength(DatabaseInterface $database, string $clubId, array $players = []): TeamStrength
    {
        $club = $this->clubService->repository($database)->get($clubId); if ($players === []) { return new TeamStrength($club->reputation(), false); }
        $average = (int) floor(array_sum(array_map(static fn (Player $player): int => $player->overallRating(), $players)) / count($players));
        return new TeamStrength((int) floor(($club->reputation() + $average) / 2), true);
    }

    private function poisson(float $lambda, string $key): int { $u = max(0.0000001, $this->unit($key)); $probability = exp(-$lambda); $cumulative = $probability; $goals = 0; while ($u > $cumulative && $goals < 12) { $goals++; $probability *= $lambda / $goals; $cumulative += $probability; } return min(12, $goals); }
    private function unit(string $key): float { return hexdec(substr(hash('sha256', $key), 0, 12)) / 281474976710655; }
}
