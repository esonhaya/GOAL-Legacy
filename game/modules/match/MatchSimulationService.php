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

final class MatchSimulationService
{
    public function __construct(private readonly ClubService $clubService, private readonly MatchSelectionService $selectionService) {}

    public function simulate(DatabaseInterface $database, GameMatch $match): MatchSimulation
    {
        $playerRepository = new PlayerRepository($database);
        $selections = $this->selectionService->select($database, $match);
        $homeStarters = $this->selectedPlayers($selections, $match->homeClubId()->value(), SelectionStatus::Starter, $playerRepository);
        $awayStarters = $this->selectedPlayers($selections, $match->awayClubId()->value(), SelectionStatus::Starter, $playerRepository);
        $homeBench = $this->selectedPlayers($selections, $match->homeClubId()->value(), SelectionStatus::Bench, $playerRepository);
        $awayBench = $this->selectedPlayers($selections, $match->awayClubId()->value(), SelectionStatus::Bench, $playerRepository);
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
        $goalEvents = [];
        for ($i = 0; $i < $homeGoals; $i++) {
            $minute = 1 + (int) floor($this->unit($match->id()->value() . '|home-goal|' . $i) * 89);
            $goalEvents[] = ['club' => $match->homeClubId()->value(), 'player' => $this->scorerAtMinute($match->id()->value(), $homeStarters, $homeSubstitutions, $minute, $i), 'minute' => $minute, 'side' => 'home', 'type' => 'goal'];
        }
        for ($i = 0; $i < $awayGoals; $i++) {
            $minute = 1 + (int) floor($this->unit($match->id()->value() . '|away-goal|' . $i) * 89);
            $goalEvents[] = ['club' => $match->awayClubId()->value(), 'player' => $this->scorerAtMinute($match->id()->value(), $awayStarters, $awaySubstitutions, $minute, $i), 'minute' => $minute, 'side' => 'away', 'type' => 'goal'];
        }
        foreach ($substitutions as $substitution) {
            $goalEvents[] = ['club' => $substitution->clubId()->value(), 'player' => $substitution->incomingPlayerId()->value(), 'minute' => $substitution->minute(), 'side' => $substitution->clubId()->value(), 'type' => 'substitution', 'substitution' => $substitution];
        }
        usort($goalEvents, static fn (array $a, array $b): int => ($a['minute'] <=> $b['minute']) ?: (($a['type'] === $b['type']) ? strcmp((string) $a['side'], (string) $b['side']) : strcmp((string) $a['type'], (string) $b['type'])));
        $goalCounts = [];
        $highlights = [];
        foreach ($goalEvents as $index => $event) {
            if ($event['type'] === 'goal' && $event['player'] !== null) {
                $goalCounts[$event['player']] = ($goalCounts[$event['player']] ?? 0) + 1;
            }
            if ($event['type'] === 'substitution') {
                /** @var MatchSubstitution $substitution */
                $substitution = $event['substitution'];
                $highlights[] = new MatchHighlight($match->id(), $index + 1, (int) $event['minute'], 'substitution', new \Goal\Legacy\Modules\Club\Domain\ClubId((string) $event['club']), new \Goal\Legacy\Modules\Player\Domain\PlayerId((string) $event['player']), ['outgoing_player_id' => $substitution->outgoingPlayerId()->value(), 'incoming_player_id' => $substitution->incomingPlayerId()->value(), 'sequence' => $substitution->sequence()]);
            } else {
                $highlights[] = new MatchHighlight($match->id(), $index + 1, (int) $event['minute'], 'goal', new \Goal\Legacy\Modules\Club\Domain\ClubId((string) $event['club']), $event['player'] === null ? null : new \Goal\Legacy\Modules\Player\Domain\PlayerId((string) $event['player']), ['side' => $event['side'], 'home_goals' => $homeGoals, 'away_goals' => $awayGoals]);
            }
        }
        $stats = [];
        foreach ($this->participantStats($match, $match->homeClubId(), $homeStarters, $homeSubstitutions, $goalCounts, $playerRepository) as $stat) { $stats[] = $stat; }
        foreach ($this->participantStats($match, $match->awayClubId(), $awayStarters, $awaySubstitutions, $goalCounts, $playerRepository) as $stat) { $stats[] = $stat; }
        return new MatchSimulation($result, $stats, $highlights, $selections, $substitutions);
    }

    /** @param list<\Goal\Legacy\Modules\Match\Domain\PlayerSelection> $selections @return list<Player> */
    private function selectedPlayers(array $selections, string $clubId, SelectionStatus $status, PlayerRepository $players): array
    {
        $result = [];
        foreach ($selections as $selection) {
            if ($selection->clubId()->value() === $clubId && $selection->status() === $status) {
                $result[] = $players->get($selection->playerId());
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

    /** @param list<Player> $starters @param list<MatchSubstitution> $substitutions */
    private function scorerAtMinute(string $matchId, array $starters, array $substitutions, int $minute, int $goalIndex): ?string
    {
        $players = [];
        foreach ($starters as $starter) {
            $active = true;
            foreach ($substitutions as $substitution) {
                if ($substitution->outgoingPlayerId()->value() === $starter->id()->value() && $minute >= $substitution->minute()) {
                    $active = false;
                }
            }
            if ($active) {
                $players[] = $starter->id()->value();
            }
        }
        foreach ($substitutions as $substitution) {
            if ($minute >= $substitution->minute()) {
                $players[] = $substitution->incomingPlayerId()->value();
            }
        }

        if ($players === []) {
            return null;
        }
        $offset = (int) floor($this->unit($matchId . '|scorer|' . $minute . '|' . $goalIndex) * count($players));

        return $players[$offset % count($players)];
    }

    /** @param list<Player> $starters @param list<MatchSubstitution> $substitutions @param array<string, int> $goalCounts @return list<PlayerMatchStat> */
    private function participantStats(GameMatch $match, \Goal\Legacy\Modules\Club\Domain\ClubId $clubId, array $starters, array $substitutions, array $goalCounts, PlayerRepository $players): array
    {
        $outgoingMinutes = [];
        foreach ($substitutions as $substitution) {
            $outgoingMinutes[$substitution->outgoingPlayerId()->value()] = $substitution->minute();
        }
        $stats = [];
        foreach ($starters as $starter) {
            $id = $starter->id()->value();
            $stats[] = new PlayerMatchStat($match->id(), $starter->id(), $clubId, true, true, $outgoingMinutes[$id] ?? 90, $goalCounts[$id] ?? 0);
        }
        foreach ($substitutions as $substitution) {
            $incoming = $players->get($substitution->incomingPlayerId());
            $id = $incoming->id()->value();
            $stats[] = new PlayerMatchStat($match->id(), $incoming->id(), $clubId, true, false, 90 - $substitution->minute(), $goalCounts[$id] ?? 0);
        }

        return $stats;
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
