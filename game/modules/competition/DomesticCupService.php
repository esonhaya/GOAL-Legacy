<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Competition;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\ClubCompetitionMembership;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Persistence\SeasonRepository;
use PDO;
use RuntimeException;

/**
 * Owns the small amount of state needed to run a domestic single-elimination
 * cup. Football itself remains owned by MatchService.
 */
final class DomesticCupService
{
    private const SEASONS = 'domestic_cup_seasons';
    private const ENTRIES = 'domestic_cup_entries';
    private const MATCHES = 'domestic_cup_match_states';

    public function __construct(private readonly ClubService $clubs)
    {
    }

    public function initializeSchema(DatabaseInterface $database): void
    {
        SchemaInitializationGuard::run($database->connection(), self::class, function () use ($database): void {
            $database->connection()->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::SEASONS . ' ('
                . 'competition_id TEXT NOT NULL, season_id TEXT NOT NULL, '
                . 'participant_count INTEGER NOT NULL, total_rounds INTEGER NOT NULL, current_round INTEGER NOT NULL, '
                . 'status TEXT NOT NULL, winner_club_id TEXT NULL, runner_up_club_id TEXT NULL, '
                . 'PRIMARY KEY (competition_id, season_id))'
            );
            $database->connection()->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::ENTRIES . ' ('
                . 'competition_id TEXT NOT NULL, season_id TEXT NOT NULL, club_id TEXT NOT NULL, '
                . 'status TEXT NOT NULL, eliminated_round INTEGER NULL, '
                . 'PRIMARY KEY (competition_id, season_id, club_id))'
            );
            $database->connection()->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::MATCHES . ' ('
                . 'match_id TEXT PRIMARY KEY, competition_id TEXT NOT NULL, season_id TEXT NOT NULL, '
                . 'round_number INTEGER NOT NULL, stage TEXT NOT NULL, winner_club_id TEXT NULL, '
                . 'extra_time_home_goals INTEGER NULL, extra_time_away_goals INTEGER NULL, '
                . 'shootout_home_goals INTEGER NULL, shootout_away_goals INTEGER NULL)'
            );
            foreach (['extra_time_home_goals', 'extra_time_away_goals'] as $column) {
                $columns = $database->connection()->query('PRAGMA table_info(' . self::MATCHES . ')')->fetchAll(PDO::FETCH_COLUMN, 1);
                if (!in_array($column, $columns, true)) {
                    $database->connection()->exec('ALTER TABLE ' . self::MATCHES . ' ADD COLUMN ' . $column . ' INTEGER NULL');
                }
            }
            $database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_domestic_cup_matches_round ON ' . self::MATCHES . ' (competition_id, season_id, round_number, match_id)');
            $database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_domestic_cup_entries_status ON ' . self::ENTRIES . ' (competition_id, season_id, status, club_id)');
        });
    }

    public function isDomesticCup(DatabaseInterface $database, string $competitionId): bool
    {
        $statement = $database->connection()->prepare('SELECT type FROM competition_records WHERE id = :id');
        $statement->execute(['id' => $competitionId]);

        return $statement->fetchColumn() === CompetitionType::DomesticCup->value;
    }

    /** Ensure all selected domestic cups have their current-Season state and memberships. */
    public function ensureSeason(DatabaseInterface $database, Season $season): void
    {
        $this->initializeSchema($database);
        $cups = $database->connection()->prepare(
            'SELECT id, nation_id FROM competition_records WHERE season_id = :season_id AND type = :type ORDER BY id ASC'
        );
        $cups->execute(['season_id' => $season->id()->value(), 'type' => CompetitionType::DomesticCup->value]);
        $records = $cups->fetchAll(PDO::FETCH_ASSOC);
        $hasMatchTable = (int) $database->connection()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'match_records'")->fetchColumn() > 0;
        foreach ($records as $cup) {
            $competitionId = (string) $cup['id'];
            $this->ensureCup($database, $season, $competitionId, (string) $cup['nation_id']);
            if (!$hasMatchTable) {
                continue;
            }
            $leagueMatches = $database->connection()->prepare(
                "SELECT COUNT(*) FROM match_records m JOIN competition_records c ON c.id = m.competition_id "
                . "WHERE m.season_id = :season_id AND c.type = 'domestic_league'"
            );
            $leagueMatches->execute(['season_id' => $season->id()->value()]);
            if ((int) $leagueMatches->fetchColumn() > 0) {
                $cupMatches = $database->connection()->prepare('SELECT COUNT(*) FROM ' . self::MATCHES . ' WHERE competition_id = :competition_id AND season_id = :season_id');
                $cupMatches->execute(['competition_id' => $competitionId, 'season_id' => $season->id()->value()]);
                if ((int) $cupMatches->fetchColumn() === 0) {
                    $this->scheduleRound($database, $competitionId, $season, 1);
                }
            }
        }
        if ($hasMatchTable) {
            $this->reconcileCompletedMatches($database);
        }
    }

    /** @return list<GameMatch> */
    public function generateFixtures(DatabaseInterface $database, CompetitionId|string $competitionId, SeasonId|string $seasonId): array
    {
        $competition = $competitionId instanceof CompetitionId ? $competitionId->value() : $competitionId;
        $seasonKey = $seasonId instanceof SeasonId ? $seasonId->value() : $seasonId;
        $season = (new SeasonRepository($database))->get($seasonKey);
        $this->ensureSeason($database, $season);
        $matches = new MatchRepository($database);
        if ($matches->byCompetition($competition, $season->id()) !== []) {
            return $matches->byCompetition($competition, $season->id());
        }

        // League dates are the calendar baseline. A cup is scheduled only
        // after those records exist so the shared calendar can avoid clashes.
        $leagueMatches = $database->connection()->query(
            "SELECT COUNT(*) FROM match_records m JOIN competition_records c ON c.id = m.competition_id "
            . "WHERE m.season_id = " . $database->connection()->quote($season->id()->value())
            . " AND c.type = 'domestic_league'"
        )->fetchColumn();
        if ((int) $leagueMatches === 0) {
            return [];
        }
        $this->scheduleRound($database, $competition, $season, 1);

        return $matches->byCompetition($competition, $season->id());
    }

    /** Called once after a completed Match has been durably written. */
    public function recordCompletedMatch(DatabaseInterface $database, GameMatch $match): void
    {
        if (!$this->isDomesticCup($database, $match->competitionId()->value()) || $match->status() !== MatchStatus::Completed) {
            return;
        }
        $this->initializeSchema($database);
        $lookup = $database->connection()->prepare('SELECT * FROM ' . self::MATCHES . ' WHERE match_id = :match_id');
        $lookup->execute(['match_id' => $match->id()->value()]);
        $state = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!is_array($state) || $state['winner_club_id'] !== null) {
            return;
        }
        $result = $match->result();
        if ($result === null) {
            return;
        }
        $extraTime = [0, 0];
        $shootout = [null, null];
        $regulationHome = $result->homeGoals();
        $regulationAway = $result->awayGoals();
        if ($result->isDraw()) {
            $extraTime = $this->extraTime($match);
        }
        $aetHome = $regulationHome + $extraTime[0];
        $aetAway = $regulationAway + $extraTime[1];
        $winner = $aetHome > $aetAway ? $match->homeClubId()->value() : ($aetAway > $aetHome ? $match->awayClubId()->value() : null);
        if ($winner === null) {
            $shootout = $this->shootout($database, $match);
            $winner = $shootout[0] > $shootout[1] ? $match->homeClubId()->value() : $match->awayClubId()->value();
        }
        $loser = $winner === $match->homeClubId()->value() ? $match->awayClubId()->value() : $match->homeClubId()->value();
        $round = (int) $state['round_number'];

        $database->transaction(function () use ($database, $match, $state, $winner, $loser, $round, $extraTime, $shootout): void {
            $statement = $database->connection()->prepare(
                'UPDATE ' . self::MATCHES . ' SET winner_club_id = :winner, extra_time_home_goals = :extra_home, extra_time_away_goals = :extra_away, shootout_home_goals = :home, shootout_away_goals = :away WHERE match_id = :match_id AND winner_club_id IS NULL'
            );
            $statement->execute(['winner' => $winner, 'extra_home' => $extraTime[0], 'extra_away' => $extraTime[1], 'home' => $shootout[0], 'away' => $shootout[1], 'match_id' => $match->id()->value()]);
            $loserUpdate = $database->connection()->prepare(
                'UPDATE ' . self::ENTRIES . ' SET status = :status, eliminated_round = :round WHERE competition_id = :competition_id AND season_id = :season_id AND club_id = :club_id AND status = :active'
            );
            $loserUpdate->execute(['status' => 'eliminated', 'round' => $round, 'competition_id' => $match->competitionId()->value(), 'season_id' => $match->seasonId()->value(), 'club_id' => $loser, 'active' => 'active']);
        });

        $this->advanceRoundIfReady($database, $match->competitionId()->value(), $match->seasonId(), $round);
    }

    /** @return array<string, mixed>|null */
    public function matchResolution(DatabaseInterface $database, string $matchId): ?array
    {
        $this->initializeSchema($database);
        $statement = $database->connection()->prepare('SELECT * FROM ' . self::MATCHES . ' WHERE match_id = :match_id');
        $statement->execute(['match_id' => $matchId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $match = (new MatchRepository($database))->get($matchId);
        $regulationHome = $match->result()?->homeGoals() ?? 0;
        $regulationAway = $match->result()?->awayGoals() ?? 0;
        $extraHome = $row['extra_time_home_goals'] === null ? 0 : (int) $row['extra_time_home_goals'];
        $extraAway = $row['extra_time_away_goals'] === null ? 0 : (int) $row['extra_time_away_goals'];

        return [
            'round' => (int) $row['round_number'],
            'stage' => (string) $row['stage'],
            'winner_club_id' => $row['winner_club_id'] === null ? null : (string) $row['winner_club_id'],
            'regulation_home_goals' => $regulationHome,
            'regulation_away_goals' => $regulationAway,
            'extra_time_home_goals' => $extraHome,
            'extra_time_away_goals' => $extraAway,
            'aet_home_goals' => $regulationHome + $extraHome,
            'aet_away_goals' => $regulationAway + $extraAway,
            'shootout_home_goals' => $row['shootout_home_goals'] === null ? null : (int) $row['shootout_home_goals'],
            'shootout_away_goals' => $row['shootout_away_goals'] === null ? null : (int) $row['shootout_away_goals'],
            'decided_by' => $row['shootout_home_goals'] !== null ? 'penalties' : (($row['extra_time_home_goals'] ?? 0) !== 0 || ($row['extra_time_away_goals'] ?? 0) !== 0 ? 'extra_time' : 'regulation'),
        ];
    }

    /** @return array<string, mixed> */
    public function view(DatabaseInterface $database, string $competitionId, SeasonId|string $seasonId, ?string $controlledClubId = null): array
    {
        $seasonKey = $seasonId instanceof SeasonId ? $seasonId->value() : $seasonId;
        $this->initializeSchema($database);
        $stateStatement = $database->connection()->prepare('SELECT * FROM ' . self::SEASONS . ' WHERE competition_id = :competition_id AND season_id = :season_id');
        $stateStatement->execute(['competition_id' => $competitionId, 'season_id' => $seasonKey]);
        $state = $stateStatement->fetch(PDO::FETCH_ASSOC);
        $matches = new MatchRepository($database);
        $clubs = $this->clubs->repository($database);
        $rounds = [];
        if (is_array($state)) {
            $rows = $database->connection()->prepare('SELECT * FROM ' . self::MATCHES . ' WHERE competition_id = :competition_id AND season_id = :season_id ORDER BY round_number ASC, match_id ASC');
            $rows->execute(['competition_id' => $competitionId, 'season_id' => $seasonKey]);
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $match = $matches->get((string) $row['match_id']);
                $round = (int) $row['round_number'];
                $rounds[$round]['round'] = $round;
                $rounds[$round]['stage'] = (string) $row['stage'];
                $rounds[$round]['fixtures'][] = [
                    'match_id' => $match->id()->value(),
                    'date' => $match->scheduledDate()->toIsoString(),
                    'home' => $clubs->get($match->homeClubId())->canonicalName(),
                    'away' => $clubs->get($match->awayClubId())->canonicalName(),
                    'home_club_id' => $match->homeClubId()->value(),
                    'away_club_id' => $match->awayClubId()->value(),
                    'status' => $match->status()->value,
                    'home_goals' => $match->result()?->homeGoals(),
                    'away_goals' => $match->result()?->awayGoals(),
                    'winner_club_id' => $row['winner_club_id'],
                    'extra_time_home_goals' => $row['extra_time_home_goals'] === null ? 0 : (int) $row['extra_time_home_goals'],
                    'extra_time_away_goals' => $row['extra_time_away_goals'] === null ? 0 : (int) $row['extra_time_away_goals'],
                    'aet_home_goals' => ($match->result()?->homeGoals() ?? 0) + (int) ($row['extra_time_home_goals'] ?? 0),
                    'aet_away_goals' => ($match->result()?->awayGoals() ?? 0) + (int) ($row['extra_time_away_goals'] ?? 0),
                    'shootout_home_goals' => $row['shootout_home_goals'],
                    'shootout_away_goals' => $row['shootout_away_goals'],
                    'controlled' => $controlledClubId !== null && in_array($controlledClubId, [$match->homeClubId()->value(), $match->awayClubId()->value()], true),
                ];
            }
        }
        ksort($rounds);
        $remaining = [];
        $entries = $database->connection()->prepare('SELECT club_id FROM ' . self::ENTRIES . ' WHERE competition_id = :competition_id AND season_id = :season_id AND status = :status ORDER BY club_id ASC');
        $entries->execute(['competition_id' => $competitionId, 'season_id' => $seasonKey, 'status' => 'active']);
        foreach ($entries->fetchAll(PDO::FETCH_COLUMN) as $clubId) {
            $remaining[] = ['id' => (string) $clubId, 'name' => $clubs->get((string) $clubId)->canonicalName(), 'controlled' => (string) $clubId === $controlledClubId];
        }

        return [
            'status' => is_array($state) ? (string) $state['status'] : 'not_initialized',
            'current_round' => is_array($state) ? (int) $state['current_round'] : null,
            'winner_club_id' => is_array($state) ? $state['winner_club_id'] : null,
            'runner_up_club_id' => is_array($state) ? $state['runner_up_club_id'] : null,
            'remaining_clubs' => $remaining,
            'rounds' => array_values($rounds),
        ];
    }

    public function complete(DatabaseInterface $database, string $competitionId, SeasonId|string $seasonId): bool
    {
        $seasonKey = $seasonId instanceof SeasonId ? $seasonId->value() : $seasonId;
        $statement = $database->connection()->prepare('SELECT status FROM ' . self::SEASONS . ' WHERE competition_id = :competition_id AND season_id = :season_id');
        $statement->execute(['competition_id' => $competitionId, 'season_id' => $seasonKey]);
        $status = $statement->fetchColumn();
        if ($status === false) { return true; }
        $matches = $database->connection()->prepare('SELECT COUNT(*) FROM ' . self::MATCHES . ' WHERE competition_id = :competition_id AND season_id = :season_id');
        $matches->execute(['competition_id' => $competitionId, 'season_id' => $seasonKey]);
        if ((int) $matches->fetchColumn() === 0) { return true; }

        return $status === 'completed';
    }

    /** @return list<array<string, int|string|null>> */
    public function historyForClub(DatabaseInterface $database, string $clubId): array
    {
        $this->initializeSchema($database);
        $statement = $database->connection()->prepare(
            'SELECT e.club_id, e.status AS club_status, e.eliminated_round, s.competition_id, s.season_id, '
            . 's.status, s.current_round, s.winner_club_id, s.runner_up_club_id, c.name AS competition_name '
            . 'FROM ' . self::ENTRIES . ' e JOIN ' . self::SEASONS . ' s '
            . 'ON s.competition_id = e.competition_id AND s.season_id = e.season_id '
            . 'JOIN competition_records c ON c.id = s.competition_id '
            . 'WHERE e.club_id = :club_id ORDER BY s.season_id DESC, s.competition_id ASC'
        );
        $statement->execute(['club_id' => $clubId]);

        return array_map(static function (array $row): array {
            foreach (['eliminated_round', 'current_round'] as $key) {
                if ($row[$key] !== null) { $row[$key] = (int) $row[$key]; }
            }
            foreach (['club_id', 'club_status', 'competition_id', 'season_id', 'status', 'winner_club_id', 'runner_up_club_id', 'competition_name'] as $key) {
                if ($row[$key] !== null) { $row[$key] = (string) $row[$key]; }
            }

            return $row;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function ensureCup(DatabaseInterface $database, Season $season, string $competitionId, string $nationId): void
    {
        $exists = $database->connection()->prepare('SELECT 1 FROM ' . self::SEASONS . ' WHERE competition_id = :competition_id AND season_id = :season_id');
        $exists->execute(['competition_id' => $competitionId, 'season_id' => $season->id()->value()]);
        if ($exists->fetchColumn() !== false) {
            return;
        }
        $clubs = $this->eligibleClubIds($database, $season->id(), $nationId);
        if (count($clubs) < 2) {
            return;
        }
        $power = 1;
        while ($power < count($clubs)) { $power *= 2; }
        $totalRounds = (int) round(log($power, 2));
        $database->transaction(function () use ($database, $competitionId, $season, $clubs, $totalRounds): void {
            $statement = $database->connection()->prepare('INSERT INTO ' . self::SEASONS . ' (competition_id, season_id, participant_count, total_rounds, current_round, status) VALUES (:competition_id, :season_id, :participant_count, :total_rounds, 1, :status)');
            $statement->execute(['competition_id' => $competitionId, 'season_id' => $season->id()->value(), 'participant_count' => count($clubs), 'total_rounds' => $totalRounds, 'status' => 'active']);
            $entry = $database->connection()->prepare('INSERT INTO ' . self::ENTRIES . ' (competition_id, season_id, club_id, status) VALUES (:competition_id, :season_id, :club_id, :status)');
            foreach ($clubs as $clubId) {
                $entry->execute(['competition_id' => $competitionId, 'season_id' => $season->id()->value(), 'club_id' => $clubId, 'status' => 'active']);
            }
            $memberships = $this->clubs->membershipRepository($database);
            foreach ($clubs as $clubId) {
                $membership = new ClubCompetitionMembership(new ClubId($clubId), new CompetitionId($competitionId), $season->id());
                if (!$memberships->exists($membership)) {
                    $memberships->save($membership);
                }
            }
        });
    }

    /** @return list<string> */
    private function eligibleClubIds(DatabaseInterface $database, SeasonId $seasonId, string $nationId): array
    {
        $statement = $database->connection()->prepare(
            "SELECT DISTINCT m.club_id FROM club_competition_memberships m "
            . "JOIN competition_records c ON c.id = m.competition_id "
            . "JOIN club_records club ON club.id = m.club_id "
            . "WHERE m.season_id = :season_id AND club.nation_id = :nation_id "
            . "AND c.type = 'domestic_league' AND c.tier <= 2 ORDER BY m.club_id ASC"
        );
        $statement->execute(['season_id' => $seasonId->value(), 'nation_id' => $nationId]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function scheduleRound(DatabaseInterface $database, string $competitionId, Season $season, int $round): void
    {
        $existing = $database->connection()->prepare('SELECT COUNT(*) FROM ' . self::MATCHES . ' WHERE competition_id = :competition_id AND season_id = :season_id AND round_number = :round');
        $existing->execute(['competition_id' => $competitionId, 'season_id' => $season->id()->value(), 'round' => $round]);
        if ((int) $existing->fetchColumn() > 0) {
            return;
        }
        $stateStatement = $database->connection()->prepare('SELECT * FROM ' . self::SEASONS . ' WHERE competition_id = :competition_id AND season_id = :season_id');
        $stateStatement->execute(['competition_id' => $competitionId, 'season_id' => $season->id()->value()]);
        $state = $stateStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($state)) {
            return;
        }
        $clubsStatement = $database->connection()->prepare('SELECT club_id FROM ' . self::ENTRIES . ' WHERE competition_id = :competition_id AND season_id = :season_id AND status = :status ORDER BY club_id ASC');
        $clubsStatement->execute(['competition_id' => $competitionId, 'season_id' => $season->id()->value(), 'status' => 'active']);
        $clubs = array_map('strval', $clubsStatement->fetchAll(PDO::FETCH_COLUMN));
        if (count($clubs) < 2 || count($clubs) % 2 !== 0) {
            throw new RuntimeException('Domestic Cup active entries must form pairs.');
        }
        $pairingClubs = $clubs;
        if ($round === 1) {
            $power = 1;
            while ($power < (int) $state['participant_count']) { $power *= 2; }
            $preliminaryPairs = (int) $state['participant_count'] - intdiv($power, 2);
            if ($preliminaryPairs > 0) {
                $pairingClubs = array_slice($clubs, 0, $preliminaryPairs * 2);
            }
        }
        $seed = $this->worldSeed($database);
        usort($pairingClubs, static fn (string $left, string $right): int => strcmp(hash('sha256', 'cup-draw:v1|' . $seed . '|' . $competitionId . '|' . $state['season_id'] . '|' . $round . '|' . $left), hash('sha256', 'cup-draw:v1|' . $seed . '|' . $competitionId . '|' . $state['season_id'] . '|' . $round . '|' . $right)) ?: strcmp($left, $right));
        $stage = $this->stageName((int) $state['total_rounds'], $round, (int) $state['participant_count']);
        $date = $this->roundDate($season, (int) $state['total_rounds'], $round);
        $matches = new MatchRepository($database);
        $matchState = $database->connection()->prepare('INSERT INTO ' . self::MATCHES . ' (match_id, competition_id, season_id, round_number, stage) VALUES (:match_id, :competition_id, :season_id, :round_number, :stage)');
        for ($index = 0; $index < count($pairingClubs); $index += 2) {
            $first = $pairingClubs[$index]; $second = $pairingClubs[$index + 1];
            $swap = hexdec(substr(hash('sha256', 'cup-home:v1|' . $seed . '|' . $competitionId . '|' . $state['season_id'] . '|' . $round . '|' . $index), 0, 8)) % 2 === 1;
            [$home, $away] = $swap ? [$second, $first] : [$first, $second];
            $matchDate = $this->freeDate($matches, $home, $away, $season, $date);
            $match = new GameMatch(new MatchId(sprintf('cup.%s.%s.r%02d.%s.%s', $competitionId, $state['season_id'], $round, $home, $away)), new CompetitionId($competitionId), new SeasonId((string) $state['season_id']), $round, $matchDate, new ClubId($home), new ClubId($away));
            $matches->save($match);
            $matchState->execute(['match_id' => $match->id()->value(), 'competition_id' => $competitionId, 'season_id' => $state['season_id'], 'round_number' => $round, 'stage' => $stage]);
        }
        $update = $database->connection()->prepare('UPDATE ' . self::SEASONS . ' SET current_round = :round WHERE competition_id = :competition_id AND season_id = :season_id');
        $update->execute(['round' => $round, 'competition_id' => $competitionId, 'season_id' => $state['season_id']]);
    }

    private function advanceRoundIfReady(DatabaseInterface $database, string $competitionId, SeasonId $seasonId, int $round): void
    {
        $stateStatement = $database->connection()->prepare('SELECT * FROM ' . self::SEASONS . ' WHERE competition_id = :competition_id AND season_id = :season_id');
        $stateStatement->execute(['competition_id' => $competitionId, 'season_id' => $seasonId->value()]);
        $state = $stateStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($state) || (int) $state['current_round'] !== $round) { return; }
        $roundMatches = $database->connection()->prepare('SELECT s.match_id, s.winner_club_id, m.status FROM ' . self::MATCHES . ' s JOIN match_records m ON m.id = s.match_id WHERE s.competition_id = :competition_id AND s.season_id = :season_id AND s.round_number = :round');
        $roundMatches->execute(['competition_id' => $competitionId, 'season_id' => $seasonId->value(), 'round' => $round]);
        $rows = $roundMatches->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === [] || count(array_filter($rows, static fn (array $row): bool => $row['status'] === MatchStatus::Completed->value && $row['winner_club_id'] !== null)) !== count($rows)) { return; }
        $totalRounds = (int) $state['total_rounds'];
        if ($round >= $totalRounds) {
            $winner = (string) $rows[0]['winner_club_id'];
            $runnerStatement = $database->connection()->prepare('SELECT CASE WHEN home_club_id = :winner THEN away_club_id ELSE home_club_id END FROM match_records WHERE id = :match_id');
            $runnerStatement->execute(['winner' => $winner, 'match_id' => $rows[0]['match_id']]);
            $runner = (string) $runnerStatement->fetchColumn();
            $complete = $database->connection()->prepare('UPDATE ' . self::SEASONS . ' SET status = :status, winner_club_id = :winner, runner_up_club_id = :runner WHERE competition_id = :competition_id AND season_id = :season_id AND status = :active');
            $complete->execute(['status' => 'completed', 'winner' => $winner, 'runner' => $runner, 'competition_id' => $competitionId, 'season_id' => $seasonId->value(), 'active' => 'active']);
            return;
        }
        $season = (new SeasonRepository($database))->get($seasonId);
        $this->scheduleRound($database, $competitionId, $season, $round + 1);
    }

    private function reconcileCompletedMatches(DatabaseInterface $database): void
    {
        $statement = $database->connection()->query(
            'SELECT s.match_id FROM ' . self::MATCHES . ' s JOIN match_records m ON m.id = s.match_id '
            . 'WHERE m.status = ' . $database->connection()->quote(MatchStatus::Completed->value) . ' AND s.winner_club_id IS NULL '
            . 'ORDER BY s.competition_id ASC, s.round_number ASC, s.match_id ASC'
        );
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $matchId) {
            $match = (new MatchRepository($database))->get((string) $matchId);
            $this->recordCompletedMatch($database, $match);
        }
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
        $clubs = $this->clubs->repository($database);
        $home = $clubs->get($match->homeClubId())->reputation();
        $away = $clubs->get($match->awayClubId())->reputation();
        $homeScore = min(5, max(2, 2 + intdiv($home, 30) + (hexdec(substr(hash('sha256', 'cup-penalty:v1|' . $match->id()->value() . '|home'), 0, 8)) % 2)));
        $awayScore = min(5, max(2, 2 + intdiv($away, 30) + (hexdec(substr(hash('sha256', 'cup-penalty:v1|' . $match->id()->value() . '|away'), 0, 8)) % 2)));
        if ($homeScore === $awayScore) {
            if (hexdec(substr(hash('sha256', 'cup-sudden-death:v1|' . $match->id()->value()), 0, 8)) % 2 === 0) { ++$homeScore; } else { ++$awayScore; }
        }

        return [$homeScore, $awayScore];
    }

    private function freeDate(MatchRepository $matches, string $home, string $away, Season $season, SimulationDate $date): SimulationDate
    {
        $candidate = $date;
        for ($attempt = 0; $attempt < 21; ++$attempt) {
            $homeBusy = array_filter($matches->byClub($home, $season->id()), static fn (GameMatch $match): bool => $match->scheduledDate()->toIsoString() === $candidate->toIsoString());
            $awayBusy = array_filter($matches->byClub($away, $season->id()), static fn (GameMatch $match): bool => $match->scheduledDate()->toIsoString() === $candidate->toIsoString());
            if ($homeBusy === [] && $awayBusy === []) { return $candidate; }
            $candidate = $candidate->addDays(1);
        }
        throw new RuntimeException(sprintf('No free domestic Cup date found for %s vs %s.', $home, $away));
    }

    private function roundDate(Season $season, int $totalRounds, int $round): SimulationDate
    {
        $available = max(1, $season->startDate()->daysUntil($season->endDate()) - 28);
        $offset = (int) floor(($available * $round) / ($totalRounds + 1));

        return $season->startDate()->addDays($offset);
    }

    private function stageName(int $totalRounds, int $round, int $participants): string
    {
        if ($round === $totalRounds) { return 'Final'; }
        if ($round === $totalRounds - 1) { return 'Semi-finals'; }
        if ($round === $totalRounds - 2) { return 'Quarter-finals'; }
        $power = 1;
        while ($power < $participants) { $power *= 2; }
        $remaining = intdiv($power, 1 << max(0, $round - 1));
        return $round === 1 && $participants !== $power ? 'Preliminary round' : 'Round of ' . $remaining;
    }

    private function worldSeed(DatabaseInterface $database): int
    {
        $statement = $database->connection()->query('SELECT universe_seed FROM world_records ORDER BY id ASC LIMIT 1');
        $value = $statement?->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }
}
