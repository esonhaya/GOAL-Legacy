<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchHighlight;
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\Domain\MatchSimulation;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\Domain\TeamStrength;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Competition\Persistence\PlayerRegistrationRepository;
use Goal\Legacy\Modules\Contract\Persistence\ContractRepository;

final class MatchSimulationService
{
    public function __construct(private readonly ClubService $clubService) {}

    public function simulate(DatabaseInterface $database, GameMatch $match): MatchSimulation
    {
        $playerRepository = new PlayerRepository($database);
        $homePlayers = $this->eligiblePlayers($database, $match, $match->homeClubId()->value(), $playerRepository);
        $awayPlayers = $this->eligiblePlayers($database, $match, $match->awayClubId()->value(), $playerRepository);
        $homeStrength = $this->strength($database, $match->homeClubId()->value(), $homePlayers);
        $awayStrength = $this->strength($database, $match->awayClubId()->value(), $awayPlayers);
        $homeLambda = max(0.2, min(3.2, 1.10 + (($homeStrength->value() - $awayStrength->value()) / 100 * 0.75) + 0.18));
        $awayLambda = max(0.2, min(3.2, 1.00 + (($awayStrength->value() - $homeStrength->value()) / 100 * 0.75)));
        $homeGoals = $this->poisson($homeLambda, $match->id()->value() . '|home');
        $awayGoals = $this->poisson($awayLambda, $match->id()->value() . '|away');
        $result = new MatchResult($homeGoals, $awayGoals);
        $goalEvents = [];
        for ($i = 0; $i < $homeGoals; $i++) { $goalEvents[] = ['club' => $match->homeClubId()->value(), 'player' => $homePlayers === [] ? null : $homePlayers[$i % count($homePlayers)]->id()->value(), 'minute' => 1 + (int) floor($this->unit($match->id()->value() . '|home-goal|' . $i) * 89), 'side' => 'home']; }
        for ($i = 0; $i < $awayGoals; $i++) { $goalEvents[] = ['club' => $match->awayClubId()->value(), 'player' => $awayPlayers === [] ? null : $awayPlayers[$i % count($awayPlayers)]->id()->value(), 'minute' => 1 + (int) floor($this->unit($match->id()->value() . '|away-goal|' . $i) * 89), 'side' => 'away']; }
        usort($goalEvents, static fn (array $a, array $b): int => ($a['minute'] <=> $b['minute']) ?: strcmp((string) $a['side'], (string) $b['side']));
        $goalCounts = [];
        $highlights = [];
        foreach ($goalEvents as $index => $event) { if ($event['player'] !== null) { $goalCounts[$event['player']] = ($goalCounts[$event['player']] ?? 0) + 1; } $highlights[] = new MatchHighlight($match->id(), $index + 1, (int) $event['minute'], 'goal', new \Goal\Legacy\Modules\Club\Domain\ClubId((string) $event['club']), $event['player'] === null ? null : new \Goal\Legacy\Modules\Player\Domain\PlayerId((string) $event['player']), ['side' => $event['side'], 'home_goals' => $homeGoals, 'away_goals' => $awayGoals]); }
        $stats = [];
        foreach (array_merge($homePlayers, $awayPlayers) as $player) { $clubId = in_array($player, $homePlayers, true) ? $match->homeClubId() : $match->awayClubId(); $stats[] = new PlayerMatchStat($match->id(), $player->id(), $clubId, true, true, 90, $goalCounts[$player->id()->value()] ?? 0); }
        return new MatchSimulation($result, $stats, $highlights);
    }

    /** @return list<Player> */
    private function eligiblePlayers(DatabaseInterface $database, GameMatch $match, string $clubId, PlayerRepository $players): array
    {
        new PlayerRegistrationRepository($database);
        new ContractRepository($database);
        $registeredStatement = $database->connection()->prepare('SELECT r.player_id FROM player_competition_registrations r INNER JOIN contract_records c ON c.player_id = r.player_id AND c.club_id = r.club_id WHERE r.season_id = :season_id AND r.competition_id = :competition_id AND r.club_id = :club_id AND c.status = :status ORDER BY r.player_id ASC');
        $registeredStatement->execute(['season_id' => $match->seasonId()->value(), 'competition_id' => $match->competitionId()->value(), 'club_id' => $clubId, 'status' => 'active']);
        $registered = array_fill_keys(array_map('strval', $registeredStatement->fetchAll(\PDO::FETCH_COLUMN)), true);
        $result = [];
        foreach ($this->clubService->squadRepository($database)->byClub($clubId, $match->seasonId()) as $squad) {
            if (isset($registered[$squad->playerId()->value()])) { $result[$squad->playerId()->value()] = $players->get($squad->playerId()); }
        }
        ksort($result, SORT_STRING);
        return array_values($result);
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
