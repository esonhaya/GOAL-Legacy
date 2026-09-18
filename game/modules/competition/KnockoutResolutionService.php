<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Competition;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\International\NationalTeamService;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use PDO;

/** Shared canonical single-leg knockout resolution for Cups and Europe. */
final class KnockoutResolutionService
{
    public function __construct(private readonly ClubService $clubs, private readonly ?NationalTeamService $nationalTeams = null)
    {
    }

    /** @return array<string, int|string|null>|null */
    public function resolve(DatabaseInterface $database, GameMatch $match, string $table): ?array
    {
        $this->assertTable($table);
        if ($match->status() !== MatchStatus::Completed || $match->result() === null) {
            return null;
        }
        $lookup = $database->connection()->prepare('SELECT * FROM ' . $table . ' WHERE match_id = :match_id');
        $lookup->execute(['match_id' => $match->id()->value()]);
        $state = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!is_array($state)) {
            return null;
        }
        $winnerColumn = $this->winnerColumn($table);
        if ($state[$winnerColumn] !== null) {
            return $this->resolution($database, $match->id()->value(), $table);
        }

        $regulationHome = $match->result()->homeGoals();
        $regulationAway = $match->result()->awayGoals();
        $extraTime = [0, 0];
        $shootout = [null, null];
        if ($regulationHome === $regulationAway) {
            $extraTime = $this->extraTime($match);
        }
        $aetHome = $regulationHome + $extraTime[0];
        $aetAway = $regulationAway + $extraTime[1];
        $winner = $aetHome > $aetAway
            ? $match->homeClubId()->value()
            : ($aetAway > $aetHome ? $match->awayClubId()->value() : null);
        if ($winner === null) {
            $shootout = $this->shootout($database, $match);
            $winner = $shootout[0] > $shootout[1]
                ? $match->homeClubId()->value()
                : $match->awayClubId()->value();
        }

        $statement = $database->connection()->prepare(
            'UPDATE ' . $table . ' SET ' . $winnerColumn . ' = :winner, '
            . 'extra_time_home_goals = :extra_home, extra_time_away_goals = :extra_away, '
            . 'shootout_home_goals = :shootout_home, shootout_away_goals = :shootout_away '
            . 'WHERE match_id = :match_id AND ' . $winnerColumn . ' IS NULL'
        );
        $statement->execute([
            'winner' => $winner,
            'extra_home' => $extraTime[0],
            'extra_away' => $extraTime[1],
            'shootout_home' => $shootout[0],
            'shootout_away' => $shootout[1],
            'match_id' => $match->id()->value(),
        ]);

        return $this->resolution($database, $match->id()->value(), $table);
    }

    /** @return array<string, int|string|null>|null */
    public function resolution(DatabaseInterface $database, string $matchId, string $table): ?array
    {
        $this->assertTable($table);
        $stateStatement = $database->connection()->prepare('SELECT * FROM ' . $table . ' WHERE match_id = :match_id');
        $stateStatement->execute(['match_id' => $matchId]);
        $state = $stateStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($state)) {
            return null;
        }
        $match = (new MatchRepository($database))->get($matchId);
        $regulationHome = $match->result()?->homeGoals() ?? 0;
        $regulationAway = $match->result()?->awayGoals() ?? 0;
        $extraHome = $state['extra_time_home_goals'] === null ? 0 : (int) $state['extra_time_home_goals'];
        $extraAway = $state['extra_time_away_goals'] === null ? 0 : (int) $state['extra_time_away_goals'];

        $winnerColumn = $this->winnerColumn($table);

        return [
            'round' => (int) ($state['round_number'] ?? 0),
            'stage' => (string) ($state['stage'] ?? ''),
            'winner_club_id' => $state[$winnerColumn] === null ? null : (string) $state[$winnerColumn],
            'regulation_home_goals' => $regulationHome,
            'regulation_away_goals' => $regulationAway,
            'extra_time_home_goals' => $extraHome,
            'extra_time_away_goals' => $extraAway,
            'aet_home_goals' => $regulationHome + $extraHome,
            'aet_away_goals' => $regulationAway + $extraAway,
            'shootout_home_goals' => $state['shootout_home_goals'] === null ? null : (int) $state['shootout_home_goals'],
            'shootout_away_goals' => $state['shootout_away_goals'] === null ? null : (int) $state['shootout_away_goals'],
            'decided_by' => $state['shootout_home_goals'] !== null
                ? 'penalties'
                : ($extraHome !== 0 || $extraAway !== 0 ? 'extra_time' : 'regulation'),
        ];
    }

    /** @return array{0:int,1:int} */
    private function extraTime(GameMatch $match): array
    {
        $home = hexdec(substr(hash('sha256', 'cup-extra-time:v1|' . $match->id()->value() . '|home'), 0, 8)) % 5 === 0 ? 1 : 0;
        $away = hexdec(substr(hash('sha256', 'cup-extra-time:v1|' . $match->id()->value() . '|away'), 0, 8)) % 5 === 0 ? 1 : 0;

        return [$home, $away];
    }

    /** @return array{0:int,1:int} */
    private function shootout(DatabaseInterface $database, GameMatch $match): array
    {
        $home = $this->strength($database, $match->homeClubId()->value());
        $away = $this->strength($database, $match->awayClubId()->value());
        $homeScore = min(5, max(2, 2 + intdiv($home, 30) + (hexdec(substr(hash('sha256', 'cup-penalty:v1|' . $match->id()->value() . '|home'), 0, 8)) % 2)));
        $awayScore = min(5, max(2, 2 + intdiv($away, 30) + (hexdec(substr(hash('sha256', 'cup-penalty:v1|' . $match->id()->value() . '|away'), 0, 8)) % 2)));
        if ($homeScore === $awayScore) {
            if (hexdec(substr(hash('sha256', 'cup-sudden-death:v1|' . $match->id()->value()), 0, 8)) % 2 === 0) {
                ++$homeScore;
            } else {
                ++$awayScore;
            }
        }

        return [$homeScore, $awayScore];
    }

    private function strength(DatabaseInterface $database, string $teamId): int
    {
        if ($this->nationalTeams?->isNationalTeam($teamId) === true) {
            return $this->nationalTeams->strength($database, $teamId);
        }

        return $this->clubs->repository($database)->get($teamId)->reputation();
    }

    private function assertTable(string $table): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $table) !== 1) {
            throw new \InvalidArgumentException('Knockout state table must be a stable local identifier.');
        }
    }

    private function winnerColumn(string $table): string
    {
        return $table === 'international_match_states' ? 'winner_team_id' : 'winner_club_id';
    }
}
