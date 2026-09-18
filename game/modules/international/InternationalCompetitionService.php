<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\International;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
use Goal\Legacy\Modules\Competition\KnockoutResolutionService;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Match\PlayerMatchRatingService;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerEventRepository;
use Goal\Legacy\Modules\Player\Domain\CareerEvent;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Competition\Persistence\CompetitionRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Persistence\SeasonRepository;
use PDO;
use RuntimeException;

/**
 * Bounded World Championship structure. It owns qualification, draw, stage
 * state and history; MatchService remains the only football engine.
 */
final class InternationalCompetitionService
{
    public const WORLD_CHAMPIONSHIP = 'world-championship';

    private const SEASONS = 'international_competitions';
    private const ENTRIES = 'international_competition_entries';
    private const GROUPS = 'international_group_memberships';
    private const MATCHES = 'international_match_states';
    private const HISTORY = 'international_history';

    private readonly KnockoutResolutionService $knockout;

    public function __construct(private readonly ClubService $clubs, private readonly NationalTeamService $teams)
    {
        $this->knockout = new KnockoutResolutionService($clubs, $teams);
    }

    public function initializeSchema(DatabaseInterface $database): void
    {
        SchemaInitializationGuard::run($database->connection(), self::class, function () use ($database): void {
            $c = $database->connection();
            $c->exec('CREATE TABLE IF NOT EXISTS ' . self::SEASONS . ' (competition_id TEXT NOT NULL, season_id TEXT NOT NULL, participant_count INTEGER NOT NULL, group_count INTEGER NOT NULL, group_size INTEGER NOT NULL, current_stage TEXT NOT NULL, status TEXT NOT NULL, winner_team_id TEXT NULL, runner_up_team_id TEXT NULL, cycle INTEGER NOT NULL, PRIMARY KEY (competition_id, season_id))');
            $c->exec('CREATE TABLE IF NOT EXISTS ' . self::ENTRIES . ' (competition_id TEXT NOT NULL, season_id TEXT NOT NULL, national_team_id TEXT NOT NULL, nation_id TEXT NOT NULL, qualification_source TEXT NOT NULL, qualification_rank INTEGER NULL, seed INTEGER NOT NULL, status TEXT NOT NULL, group_name TEXT NULL, eliminated_stage TEXT NULL, PRIMARY KEY (competition_id, season_id, national_team_id))');
            $c->exec('CREATE TABLE IF NOT EXISTS ' . self::GROUPS . ' (competition_id TEXT NOT NULL, season_id TEXT NOT NULL, group_name TEXT NOT NULL, national_team_id TEXT NOT NULL, seed INTEGER NOT NULL, PRIMARY KEY (competition_id, season_id, group_name, national_team_id))');
            $c->exec('CREATE TABLE IF NOT EXISTS ' . self::MATCHES . ' (match_id TEXT PRIMARY KEY, competition_id TEXT NOT NULL, season_id TEXT NOT NULL, stage TEXT NOT NULL, group_name TEXT NULL, round_number INTEGER NOT NULL, resolved INTEGER NOT NULL DEFAULT 0, winner_team_id TEXT NULL, extra_time_home_goals INTEGER NULL, extra_time_away_goals INTEGER NULL, shootout_home_goals INTEGER NULL, shootout_away_goals INTEGER NULL)');
            $c->exec('CREATE TABLE IF NOT EXISTS ' . self::HISTORY . ' (season_id TEXT NOT NULL, competition_id TEXT NOT NULL, winner_team_id TEXT NOT NULL, runner_up_team_id TEXT NOT NULL, PRIMARY KEY (season_id, competition_id))');
            $c->exec('CREATE INDEX IF NOT EXISTS idx_intl_entries_stage ON ' . self::ENTRIES . ' (competition_id, season_id, status, national_team_id)');
            $c->exec('CREATE INDEX IF NOT EXISTS idx_intl_matches_stage ON ' . self::MATCHES . ' (competition_id, season_id, stage, round_number, match_id)');
        });
    }

    public function isInternationalCompetition(DatabaseInterface $database, string $competitionId): bool
    {
        $statement = $database->connection()->prepare('SELECT type FROM competition_records WHERE id = :id');
        $statement->execute(['id' => $competitionId]);

        return $statement->fetchColumn() === CompetitionType::International->value;
    }

    public function isTournamentSeason(Season $season): bool
    {
        return (($season->startDate()->year() - 2024) % 4) === 0;
    }

    public function cycle(Season $season): int
    {
        return max(0, intdiv($season->startDate()->year() - 2024, 4));
    }

    public function ensureSeason(DatabaseInterface $database, Season $season, ?SeasonId $previousSeason = null): void
    {
        $this->initializeSchema($database);
        $this->teams->ensureSeason($database, $season);
        $records = $database->connection()->prepare('SELECT id FROM competition_records WHERE season_id = :season_id AND type = :type ORDER BY id ASC');
        $records->execute(['season_id' => $season->id()->value(), 'type' => CompetitionType::International->value]);
        foreach ($records->fetchAll(PDO::FETCH_COLUMN) as $competitionId) {
            $this->ensureCompetition($database, $season, (string) $competitionId, $previousSeason);
        }
        if ((int) $database->connection()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'match_records'")->fetchColumn() > 0) {
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
        $existing = $matches->byCompetition($competition, $season->id());
        if ($existing !== []) { return $existing; }
        $state = $this->state($database, $competition, $seasonKey);
        if ($state === null || (string) $state['status'] === 'completed' || !$this->isTournamentSeason($season)) { return []; }
        $this->scheduleGroupStage($database, $competition, $season);

        return $matches->byCompetition($competition, $season->id());
    }

    public function recordCompletedMatch(DatabaseInterface $database, GameMatch $match): void
    {
        if (!$this->isInternationalCompetition($database, $match->competitionId()->value()) || $match->status() !== MatchStatus::Completed) { return; }
        $this->initializeSchema($database);
        $lookup = $database->connection()->prepare('SELECT * FROM ' . self::MATCHES . ' WHERE match_id = :match_id');
        $lookup->execute(['match_id' => $match->id()->value()]);
        $state = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!is_array($state) || (int) ($state['resolved'] ?? 0) === 1) { return; }
        $this->recordControlledStats($database, $match);
        if ((string) $state['stage'] === 'Group Stage') {
            $database->connection()->prepare('UPDATE ' . self::MATCHES . ' SET resolved = 1 WHERE match_id = :match_id AND resolved = 0')->execute(['match_id' => $match->id()->value()]);
            $this->advanceGroupStageIfReady($database, $match->competitionId()->value(), $match->seasonId());
            return;
        }
        $resolution = $this->knockout->resolve($database, $match, self::MATCHES);
        $winner = is_array($resolution) && is_string($resolution['winner_club_id'] ?? null) ? $resolution['winner_club_id'] : null;
        if ($winner === null) { return; }
        $loser = $winner === $match->homeClubId()->value() ? $match->awayClubId()->value() : $match->homeClubId()->value();
        $stage = (string) $state['stage'];
        $this->recordKnockoutMilestones($database, $match, $stage, $winner);
        $round = (int) ($resolution['round'] ?? $match->round());
        $database->connection()->prepare('UPDATE ' . self::MATCHES . ' SET resolved = 1 WHERE match_id = :match_id AND resolved = 0')->execute(['match_id' => $match->id()->value()]);
        $database->connection()->prepare('UPDATE ' . self::ENTRIES . ' SET status = \'eliminated\', eliminated_stage = :stage WHERE competition_id = :competition_id AND season_id = :season_id AND national_team_id = :team_id AND status <> \'eliminated\'')->execute(['stage' => $stage, 'competition_id' => $match->competitionId()->value(), 'season_id' => $match->seasonId()->value(), 'team_id' => $loser]);
        $this->advanceKnockoutIfReady($database, $match->competitionId()->value(), $match->seasonId(), $stage, $round);
    }

    /** @return array<string,mixed>|null */
    public function matchResolution(DatabaseInterface $database, string $matchId): ?array
    {
        $this->initializeSchema($database);

        return $this->knockout->resolution($database, $matchId, self::MATCHES);
    }

    /** @return list<array<string,mixed>> */
    public function groupTables(DatabaseInterface $database, string $competitionId, SeasonId|string $seasonId): array
    {
        $season = $seasonId instanceof SeasonId ? $seasonId->value() : $seasonId;
        $groups = $database->connection()->prepare('SELECT DISTINCT group_name FROM ' . self::GROUPS . ' WHERE competition_id = :competition_id AND season_id = :season_id ORDER BY group_name ASC');
        $groups->execute(['competition_id' => $competitionId, 'season_id' => $season]);
        $result = [];
        foreach ($groups->fetchAll(PDO::FETCH_COLUMN) as $groupName) {
            $members = $database->connection()->prepare('SELECT national_team_id FROM ' . self::GROUPS . ' WHERE competition_id = :competition_id AND season_id = :season_id AND group_name = :group_name ORDER BY national_team_id ASC');
            $members->execute(['competition_id' => $competitionId, 'season_id' => $season, 'group_name' => $groupName]);
            $ids = array_map('strval', $members->fetchAll(PDO::FETCH_COLUMN));
            $table = [];
            foreach ($ids as $id) { $table[$id] = ['team_id' => $id, 'team' => $this->teams->displayName($database, $id), 'played' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'gf' => 0, 'ga' => 0, 'gd' => 0, 'points' => 0]; }
            $rows = $database->connection()->prepare('SELECT m.home_club_id, m.away_club_id, m.home_goals, m.away_goals FROM match_records m JOIN ' . self::MATCHES . ' s ON s.match_id = m.id WHERE s.competition_id = :competition_id AND s.season_id = :season_id AND s.group_name = :group_name AND m.status = \'completed\'');
            $rows->execute(['competition_id' => $competitionId, 'season_id' => $season, 'group_name' => $groupName]);
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $home = (string) $row['home_club_id']; $away = (string) $row['away_club_id']; $hg = (int) $row['home_goals']; $ag = (int) $row['away_goals'];
                if (!isset($table[$home], $table[$away])) { continue; }
                ++$table[$home]['played']; ++$table[$away]['played']; $table[$home]['gf'] += $hg; $table[$home]['ga'] += $ag; $table[$away]['gf'] += $ag; $table[$away]['ga'] += $hg;
                if ($hg > $ag) { ++$table[$home]['wins']; ++$table[$away]['losses']; $table[$home]['points'] += 3; } elseif ($ag > $hg) { ++$table[$away]['wins']; ++$table[$home]['losses']; $table[$away]['points'] += 3; } else { ++$table[$home]['draws']; ++$table[$away]['draws']; ++$table[$home]['points']; ++$table[$away]['points']; }
            }
            foreach ($table as &$row) { $row['gd'] = $row['gf'] - $row['ga']; } unset($row);
            $table = array_values($table);
            usort($table, static fn (array $a, array $b): int => ($b['points'] <=> $a['points']) ?: ($b['gd'] <=> $a['gd']) ?: ($b['gf'] <=> $a['gf']) ?: strcmp($a['team_id'], $b['team_id']));
            foreach ($table as $index => &$row) { $row['position'] = $index + 1; } unset($row);
            $result[] = ['group' => (string) $groupName, 'table' => $table];
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public function view(DatabaseInterface $database, string $competitionId, SeasonId|string $seasonId, ?string $controlledTeamId = null): array
    {
        $season = $seasonId instanceof SeasonId ? $seasonId->value() : $seasonId;
        $this->initializeSchema($database);
        $state = $this->state($database, $competitionId, $season);
        $matches = new MatchRepository($database);
        $rounds = [];
        $rows = $database->connection()->prepare('SELECT * FROM ' . self::MATCHES . ' WHERE competition_id = :competition_id AND season_id = :season_id ORDER BY round_number ASC, match_id ASC');
        $rows->execute(['competition_id' => $competitionId, 'season_id' => $season]);
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $match = $matches->get((string) $row['match_id']); $round = (int) $row['round_number'];
            $rounds[$round] = $rounds[$round] ?? ['round' => $round, 'stage' => (string) $row['stage'], 'group' => $row['group_name'], 'fixtures' => []];
            $rounds[$round]['fixtures'][] = ['match_id' => $match->id()->value(), 'date' => $match->scheduledDate()->toIsoString(), 'home' => $this->teams->displayName($database, $match->homeClubId()->value()), 'away' => $this->teams->displayName($database, $match->awayClubId()->value()), 'home_team_id' => $match->homeClubId()->value(), 'away_team_id' => $match->awayClubId()->value(), 'status' => $match->status()->value, 'home_goals' => $match->result()?->homeGoals(), 'away_goals' => $match->result()?->awayGoals(), 'winner_team_id' => $row['winner_team_id'], 'resolution' => $this->matchResolution($database, $match->id()->value()), 'controlled' => $controlledTeamId !== null && in_array($controlledTeamId, [$match->homeClubId()->value(), $match->awayClubId()->value()], true)];
        }
        $entries = $database->connection()->prepare('SELECT * FROM ' . self::ENTRIES . ' WHERE competition_id = :competition_id AND season_id = :season_id ORDER BY seed ASC, national_team_id ASC');
        $entries->execute(['competition_id' => $competitionId, 'season_id' => $season]);
        $entryRows = [];
        foreach ($entries->fetchAll(PDO::FETCH_ASSOC) as $entry) { $entryRows[] = ['id' => (string) $entry['national_team_id'], 'name' => $this->teams->displayName($database, (string) $entry['national_team_id']), 'nation_id' => (string) $entry['nation_id'], 'seed' => (int) $entry['seed'], 'group' => $entry['group_name'], 'status' => (string) $entry['status'], 'qualification_source' => (string) $entry['qualification_source'], 'qualification_rank' => $entry['qualification_rank'] === null ? null : (int) $entry['qualification_rank'], 'controlled' => (string) $entry['national_team_id'] === $controlledTeamId]; }

        return ['status' => is_array($state) ? (string) $state['status'] : 'not_initialized', 'stage' => is_array($state) ? (string) $state['current_stage'] : null, 'winner_team_id' => is_array($state) ? $state['winner_team_id'] : null, 'runner_up_team_id' => is_array($state) ? $state['runner_up_team_id'] : null, 'entries' => $entryRows, 'groups' => $this->groupTables($database, $competitionId, $season), 'rounds' => array_values($rounds), 'remaining_teams' => array_values(array_filter($entryRows, static fn (array $entry): bool => in_array($entry['status'], ['group_stage', 'qualified'], true)))];
    }

    public function complete(DatabaseInterface $database, string $competitionId, SeasonId|string $seasonId): bool
    {
        $season = $seasonId instanceof SeasonId ? $seasonId->value() : $seasonId; $state = $this->state($database, $competitionId, $season);
        return $state === null || (string) $state['status'] === 'completed';
    }

    public function winner(DatabaseInterface $database, string $competitionId, SeasonId|string $seasonId): ?string
    {
        $season = $seasonId instanceof SeasonId ? $seasonId->value() : $seasonId; $state = $this->state($database, $competitionId, $season); $winner = is_array($state) ? $state['winner_team_id'] : null;

        return is_string($winner) && $winner !== '' ? $winner : null;
    }

    /** @return list<array<string,mixed>> */
    public function history(DatabaseInterface $database, ?string $playerId = null): array
    {
        $this->initializeSchema($database);
        if ($playerId === null) { return $database->connection()->query('SELECT * FROM ' . self::HISTORY . ' ORDER BY season_id ASC')->fetchAll(PDO::FETCH_ASSOC); }
        $team = $database->connection()->prepare('SELECT national_team_id FROM international_team_squads WHERE player_id = :player_id GROUP BY national_team_id ORDER BY national_team_id ASC LIMIT 1');
        $team->execute(['player_id' => $playerId]); $teamId = $team->fetchColumn();
        if (!is_string($teamId)) { return []; }
        $statement = $database->connection()->prepare('SELECT h.* FROM ' . self::HISTORY . ' h WHERE h.winner_team_id = :team_id OR h.runner_up_team_id = :team_id ORDER BY h.season_id ASC'); $statement->execute(['team_id' => $teamId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> */
    public function playerContext(DatabaseInterface $database, string $playerId, SeasonId|string $seasonId, SimulationDate $date): array
    {
        $season = $seasonId instanceof SeasonId ? $seasonId->value() : $seasonId;
        $player = (new PlayerRepository($database))->get($playerId);
        // The national team is determined by the canonical primary nationality.
        $team = 'national-team-' . $player->primaryNationId()->value();
        $selected = $this->teams->selectionStatus($database, $playerId, $season);
        $next = null;
        foreach ((new MatchRepository($database))->byClub($team, $season) as $match) { if ($match->status() === MatchStatus::Scheduled && !$match->scheduledDate()->isBefore($date)) { $next = $match; break; } }

        return ['team_id' => $team, 'country' => $this->teams->displayName($database, $team), 'selection_status' => $selected, 'selected' => $selected === 'selected', 'next_fixture' => $next === null ? null : ['match_id' => $next->id()->value(), 'date' => $next->scheduledDate()->toIsoString(), 'competition_id' => $next->competitionId()->value(), 'opponent_team_id' => $next->homeClubId()->value() === $team ? $next->awayClubId()->value() : $next->homeClubId()->value()], 'stats' => $this->teams->playerStats($database, $playerId, $season), 'history' => $this->history($database, $playerId)];
    }

    private function ensureCompetition(DatabaseInterface $database, Season $season, string $competitionId, ?SeasonId $previousSeason): void
    {
        if (!$this->isTournamentSeason($season)) { return; }
        if ($this->state($database, $competitionId, $season->id()->value()) !== null) { return; }
        $teams = array_values(array_filter($this->teams->teams($database), static fn (array $team): bool => $team['pool'] >= NationalTeamService::MIN_PLAYER_POOL));
        if (count($teams) < 4) { return; }
        $scores = [];
        foreach ($teams as $team) { $scores[] = $team + ['score' => $this->qualificationScore($database, $team, $previousSeason), 'source' => $previousSeason === null ? 'initial_strength_seed' : 'domestic_results']; }
        usort($scores, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp($a['id'], $b['id']));
        $field = count($scores) >= 6 ? 6 : 4; $scores = array_slice($scores, 0, $field); $groupCount = 2; $groupSize = intdiv($field, 2);
        $insert = $database->connection()->prepare('INSERT INTO ' . self::SEASONS . ' (competition_id, season_id, participant_count, group_count, group_size, current_stage, status, cycle) VALUES (:competition_id, :season_id, :participants, :groups, :size, \'group_stage\', \'active\', :cycle)');
        $insert->execute(['competition_id' => $competitionId, 'season_id' => $season->id()->value(), 'participants' => $field, 'groups' => $groupCount, 'size' => $groupSize, 'cycle' => $this->cycle($season)]);
        $entry = $database->connection()->prepare('INSERT INTO ' . self::ENTRIES . ' (competition_id, season_id, national_team_id, nation_id, qualification_source, qualification_rank, seed, status) VALUES (:competition_id, :season_id, :team_id, :nation_id, :source, :rank, :seed, \'group_stage\')');
        foreach ($scores as $index => $team) { $entry->execute(['competition_id' => $competitionId, 'season_id' => $season->id()->value(), 'team_id' => $team['id'], 'nation_id' => $team['nation_id'], 'source' => $team['source'], 'rank' => $index + 1, 'seed' => $index + 1]); }
        $this->assignGroups($database, $competitionId, $season->id(), $field, $groupSize);
    }

    private function assignGroups(DatabaseInterface $database, string $competitionId, SeasonId $seasonId, int $field, int $groupSize): void
    {
        $statement = $database->connection()->prepare('SELECT national_team_id, seed FROM ' . self::ENTRIES . ' WHERE competition_id = :competition_id AND season_id = :season_id ORDER BY seed ASC'); $statement->execute(['competition_id' => $competitionId, 'season_id' => $seasonId->value()]); $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        usort($rows, fn (array $a, array $b): int => strcmp($this->drawKey($database, $competitionId, $seasonId->value(), 'groups', $a['national_team_id']), $this->drawKey($database, $competitionId, $seasonId->value(), 'groups', $b['national_team_id'])));
        $save = $database->connection()->prepare('INSERT INTO ' . self::GROUPS . ' (competition_id, season_id, group_name, national_team_id, seed) VALUES (:competition_id, :season_id, :group_name, :team_id, :seed)'); $update = $database->connection()->prepare('UPDATE ' . self::ENTRIES . ' SET group_name = :group_name WHERE competition_id = :competition_id AND season_id = :season_id AND national_team_id = :team_id');
        foreach ($rows as $index => $row) { $group = $index < $groupSize ? 'A' : 'B'; $save->execute(['competition_id' => $competitionId, 'season_id' => $seasonId->value(), 'group_name' => $group, 'team_id' => $row['national_team_id'], 'seed' => $row['seed']]); $update->execute(['group_name' => $group, 'competition_id' => $competitionId, 'season_id' => $seasonId->value(), 'team_id' => $row['national_team_id']]); }
    }

    private function scheduleGroupStage(DatabaseInterface $database, string $competitionId, Season $season): void
    {
        $state = $this->state($database, $competitionId, $season->id()->value()); if (!is_array($state)) { return; }
        $groups = $database->connection()->prepare('SELECT group_name, national_team_id FROM ' . self::GROUPS . ' WHERE competition_id = :competition_id AND season_id = :season_id ORDER BY group_name ASC, national_team_id ASC'); $groups->execute(['competition_id' => $competitionId, 'season_id' => $season->id()->value()]); $byGroup = [];
        foreach ($groups->fetchAll(PDO::FETCH_ASSOC) as $row) { $byGroup[(string) $row['group_name']][] = (string) $row['national_team_id']; }
        $matches = new MatchRepository($database); $save = $database->connection()->prepare('INSERT INTO ' . self::MATCHES . ' (match_id, competition_id, season_id, stage, group_name, round_number, resolved) VALUES (:match_id, :competition_id, :season_id, \'Group Stage\', :group_name, :round, 0)');
        foreach ($byGroup as $group => $teams) { $pairs = $this->roundRobinPairs(count($teams)); foreach ($pairs as $round => [$firstIndex, $secondIndex]) { $first = $teams[$firstIndex]; $second = $teams[$secondIndex]; if ($this->bit($database, 'home|' . $competitionId . '|' . $season->id()->value() . '|' . $group . '|' . $round)) { [$first, $second] = [$second, $first]; } $date = $this->freeDate($database, $first, $second, $season, $season->startDate()->addDays(35 + $round * 21)); $match = new GameMatch(new \Goal\Legacy\Modules\Match\Domain\MatchId(sprintf('international.%s.%s.%s.%02d.%s.%s', $competitionId, $season->id()->value(), strtolower($group), $round + 1, $first, $second)), new CompetitionId($competitionId), $season->id(), $round + 1, $date, new \Goal\Legacy\Modules\Club\Domain\ClubId($first), new \Goal\Legacy\Modules\Club\Domain\ClubId($second)); $matches->save($match); $save->execute(['match_id' => $match->id()->value(), 'competition_id' => $competitionId, 'season_id' => $season->id()->value(), 'group_name' => $group, 'round' => $round + 1]); } }
    }

    /** @return list<array{0:int,1:int}> */
    private function roundRobinPairs(int $count): array
    {
        $pairs = []; for ($a = 0; $a < $count; ++$a) { for ($b = $a + 1; $b < $count; ++$b) { $pairs[] = [$a, $b]; } }

        return $pairs;
    }

    private function advanceGroupStageIfReady(DatabaseInterface $database, string $competitionId, SeasonId $seasonId): void
    {
        $pending = $database->connection()->prepare('SELECT COUNT(*) FROM ' . self::MATCHES . ' s JOIN match_records m ON m.id = s.match_id WHERE s.competition_id = :competition_id AND s.season_id = :season_id AND s.stage = \'Group Stage\' AND (s.resolved = 0 OR m.status <> \'completed\')'); $pending->execute(['competition_id' => $competitionId, 'season_id' => $seasonId->value()]); if ((int) $pending->fetchColumn() > 0) { return; }
        $groups = $this->groupTables($database, $competitionId, $seasonId); $qualified = [];
        $update = $database->connection()->prepare('UPDATE ' . self::ENTRIES . ' SET status = :status, eliminated_stage = :eliminated WHERE competition_id = :competition_id AND season_id = :season_id AND national_team_id = :team_id');
        foreach ($groups as $group) { foreach ($group['table'] as $index => $row) { $active = $index < 2; $update->execute(['status' => $active ? 'qualified' : 'eliminated', 'eliminated' => $active ? null : 'Group Stage', 'competition_id' => $competitionId, 'season_id' => $seasonId->value(), 'team_id' => $row['team_id']]); if ($active) { $qualified[(string) $group['group']][] = (string) $row['team_id']; } } }
        $season = (new SeasonRepository($database))->get($seasonId); $this->scheduleKnockout($database, $competitionId, $season, 'Semi-finals', 4, [[$qualified['A'][0], $qualified['B'][1]], [$qualified['B'][0], $qualified['A'][1]]]);
    }

    private function advanceKnockoutIfReady(DatabaseInterface $database, string $competitionId, SeasonId $seasonId, string $stage, int $round): void
    {
        $pending = $database->connection()->prepare('SELECT COUNT(*) FROM ' . self::MATCHES . ' s JOIN match_records m ON m.id = s.match_id WHERE s.competition_id = :competition_id AND s.season_id = :season_id AND s.stage = :stage AND (s.resolved = 0 OR m.status <> \'completed\')'); $pending->execute(['competition_id' => $competitionId, 'season_id' => $seasonId->value(), 'stage' => $stage]); if ((int) $pending->fetchColumn() > 0) { return; }
        $season = (new SeasonRepository($database))->get($seasonId); $winners = $this->winners($database, $competitionId, $seasonId, $stage); $pairs = []; for ($i = 0; $i < count($winners); $i += 2) { if (isset($winners[$i + 1])) { $pairs[] = [$winners[$i], $winners[$i + 1]]; } }
        if ($stage === 'Semi-finals') { $this->scheduleKnockout($database, $competitionId, $season, 'Final', 5, $pairs); return; }
        $final = $database->connection()->prepare('SELECT match_id FROM ' . self::MATCHES . ' WHERE competition_id = :competition_id AND season_id = :season_id AND stage = \'Final\' LIMIT 1'); $final->execute(['competition_id' => $competitionId, 'season_id' => $seasonId->value()]); $matchId = (string) $final->fetchColumn(); $winner = $this->winnerForMatch($database, $matchId); $match = (new MatchRepository($database))->get($matchId); $runner = $winner === $match->homeClubId()->value() ? $match->awayClubId()->value() : $match->homeClubId()->value();
        $database->connection()->prepare('UPDATE ' . self::SEASONS . ' SET current_stage = \'completed\', status = \'completed\', winner_team_id = :winner, runner_up_team_id = :runner WHERE competition_id = :competition_id AND season_id = :season_id')->execute(['winner' => $winner, 'runner' => $runner, 'competition_id' => $competitionId, 'season_id' => $seasonId->value()]);
        $database->connection()->prepare('UPDATE ' . self::ENTRIES . ' SET status = \'winner\' WHERE competition_id = :competition_id AND season_id = :season_id AND national_team_id = :team_id')->execute(['competition_id' => $competitionId, 'season_id' => $seasonId->value(), 'team_id' => $winner]); $database->connection()->prepare('UPDATE ' . self::ENTRIES . ' SET status = \'runner_up\' WHERE competition_id = :competition_id AND season_id = :season_id AND national_team_id = :team_id')->execute(['competition_id' => $competitionId, 'season_id' => $seasonId->value(), 'team_id' => $runner]);
        $database->connection()->prepare('INSERT OR REPLACE INTO ' . self::HISTORY . ' (season_id, competition_id, winner_team_id, runner_up_team_id) VALUES (:season_id, :competition_id, :winner, :runner)')->execute(['season_id' => $seasonId->value(), 'competition_id' => $competitionId, 'winner' => $winner, 'runner' => $runner]);
    }

    /** @return list<string> */
    private function winners(DatabaseInterface $database, string $competitionId, SeasonId $seasonId, string $stage): array
    {
        $rows = $database->connection()->prepare('SELECT match_id FROM ' . self::MATCHES . ' WHERE competition_id = :competition_id AND season_id = :season_id AND stage = :stage ORDER BY match_id ASC'); $rows->execute(['competition_id' => $competitionId, 'season_id' => $seasonId->value(), 'stage' => $stage]); $result = []; foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $id) { $result[] = $this->winnerForMatch($database, (string) $id); }

        return $result;
    }

    /** @param list<array{0:string,1:string}> $pairs */
    private function scheduleKnockout(DatabaseInterface $database, string $competitionId, Season $season, string $stage, int $round, array $pairs): void
    {
        $existing = $database->connection()->prepare('SELECT COUNT(*) FROM ' . self::MATCHES . ' WHERE competition_id = :competition_id AND season_id = :season_id AND stage = :stage'); $existing->execute(['competition_id' => $competitionId, 'season_id' => $season->id()->value(), 'stage' => $stage]); if ((int) $existing->fetchColumn() > 0) { return; }
        $offset = match ($stage) { 'Semi-finals' => 105, default => 126 }; $matches = new MatchRepository($database); $save = $database->connection()->prepare('INSERT INTO ' . self::MATCHES . ' (match_id, competition_id, season_id, stage, round_number, resolved) VALUES (:match_id, :competition_id, :season_id, :stage, :round, 0)');
        foreach ($pairs as $index => [$first, $second]) { if ($this->bit($database, $stage . '|' . $index . '|' . $competitionId)) { [$first, $second] = [$second, $first]; } $date = $this->freeDate($database, $first, $second, $season, $season->startDate()->addDays($offset)); $match = new GameMatch(new \Goal\Legacy\Modules\Match\Domain\MatchId(sprintf('international.%s.%s.%s.%02d.%s.%s', $competitionId, $season->id()->value(), strtolower(str_replace('-', '', $stage)), $index + 1, $first, $second)), new CompetitionId($competitionId), $season->id(), $round, $date, new \Goal\Legacy\Modules\Club\Domain\ClubId($first), new \Goal\Legacy\Modules\Club\Domain\ClubId($second)); $matches->save($match); $save->execute(['match_id' => $match->id()->value(), 'competition_id' => $competitionId, 'season_id' => $season->id()->value(), 'stage' => $stage, 'round' => $round]); }
        $database->connection()->prepare('UPDATE ' . self::SEASONS . ' SET current_stage = :stage WHERE competition_id = :competition_id AND season_id = :season_id')->execute(['stage' => strtolower(str_replace('-', '_', $stage)), 'competition_id' => $competitionId, 'season_id' => $season->id()->value()]);
    }

    private function recordControlledStats(DatabaseInterface $database, GameMatch $match): void
    {
        $stats = (new PlayerMatchStatRepository($database))->byMatch($match->id()); if ($stats === []) { return; }
        $players = new PlayerRepository($database); $rating = new PlayerMatchRatingService(); $career = new CareerPlayerRepository($database); $events = new CareerEventRepository($database); $team = $match->homeClubId()->value();
        foreach ($stats as $stat) { if (!$this->teams->isNationalTeam($stat->clubId()->value())) { continue; } $player = $players->get($stat->playerId()); $score = $rating->rate($stat, $player->primaryPosition()); $this->teams->addPlayerMatchStats($database, $match->competitionId()->value(), $match->seasonId(), $stats, $player->id()->value(), (int) round(($score ?? 0) * 10)); if ($career->byPlayer($player->id()) !== null) { $this->addMilestone($database, $events, $player->id(), $match, 'first_cap', 'International debut', 'The first senior international appearance became a new Career milestone.'); if ($stat->goals() > 0) { $this->addMilestone($database, $events, $player->id(), $match, 'first_international_goal', 'First international goal', 'A first international goal was added to the Career record.'); } } }
    }

    private function addMilestone(DatabaseInterface $database, CareerEventRepository $events, PlayerId $playerId, GameMatch $match, string $milestone, string $title, string $description): void
    {
        $key = 'international|' . $playerId->value() . '|' . $milestone; if ($events->bySourceKey($key) !== null) { return; }
        $event = CareerEvent::pending('career-' . hash('sha256', $key), $playerId, $match->seasonId(), $match->scheduledDate(), $key, 'international', $milestone, $title, $description, [], ['historyworthy' => true, 'newsworthy' => true, 'competition_id' => $match->competitionId()->value()])->resolved('record', ['milestone' => $milestone, 'history' => $description]); $events->saveInTransaction($event);
    }

    private function recordKnockoutMilestones(DatabaseInterface $database, GameMatch $match, string $stage, string $winner): void
    {
        $career = new CareerPlayerRepository($database);
        $events = new CareerEventRepository($database);
        $teams = [$match->homeClubId()->value(), $match->awayClubId()->value()];
        foreach ($career->playerIds() as $playerId) {
            $selected = $database->connection()->prepare('SELECT national_team_id FROM international_team_squads WHERE season_id = :season_id AND player_id = :player_id AND national_team_id IN (:home, :away) AND status = \'selected\' LIMIT 1');
            $selected->execute(['season_id' => $match->seasonId()->value(), 'player_id' => $playerId, 'home' => $teams[0], 'away' => $teams[1]]);
            $team = $selected->fetchColumn();
            if (!is_string($team)) { continue; }
            $player = new PlayerId($playerId);
            if ($stage === 'Semi-finals') {
                $this->addMilestone($database, $events, $player, $match, $winner === $team ? 'semifinal' : 'elimination', $winner === $team ? 'World Championship semi-final' : 'International elimination', $winner === $team ? 'Reached the World Championship final stage.' : 'The World Championship run ended in the semi-final.');
            } elseif ($stage === 'Final') {
                $this->addMilestone($database, $events, $player, $match, $winner === $team ? 'championship' : 'final', $winner === $team ? 'World Championship champion' : 'World Championship final', $winner === $team ? 'Won the World Championship.' : 'Reached the World Championship final.');
            }
        }
    }

    private function qualificationScore(DatabaseInterface $database, array $team, ?SeasonId $previousSeason): int
    {
        $score = (int) $team['strength'] * 10; if ($previousSeason === null) { return $score; }
        $league = $database->connection()->prepare("SELECT id FROM competition_records WHERE season_id = :season_id AND nation_id = :nation_id AND type = 'domestic_league' AND tier = 1 LIMIT 1"); $league->execute(['season_id' => $previousSeason->value(), 'nation_id' => $team['nation_id']]); $leagueId = $league->fetchColumn(); if (is_string($leagueId)) { $score += 100; $completed = $database->connection()->prepare("SELECT COUNT(*) FROM match_records WHERE competition_id = :competition_id AND season_id = :season_id AND status = 'completed'"); $completed->execute(['competition_id' => $leagueId, 'season_id' => $previousSeason->value()]); $score += (int) $completed->fetchColumn(); }

        return $score;
    }

    private function state(DatabaseInterface $database, string $competitionId, string $seasonId): ?array
    {
        $statement = $database->connection()->prepare('SELECT * FROM ' . self::SEASONS . ' WHERE competition_id = :competition_id AND season_id = :season_id'); $statement->execute(['competition_id' => $competitionId, 'season_id' => $seasonId]); $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function winnerForMatch(DatabaseInterface $database, string $matchId): string
    {
        $row = $database->connection()->prepare('SELECT winner_team_id FROM ' . self::MATCHES . ' WHERE match_id = :match_id'); $row->execute(['match_id' => $matchId]); $winner = $row->fetchColumn(); if (!is_string($winner) || $winner === '') { throw new RuntimeException('Completed international knockout Match has no winner.'); }

        return $winner;
    }

    private function reconcileCompletedMatches(DatabaseInterface $database): void
    {
        $rows = $database->connection()->query("SELECT s.match_id FROM " . self::MATCHES . " s JOIN match_records m ON m.id = s.match_id WHERE m.status = 'completed' AND s.resolved = 0 ORDER BY s.round_number ASC, s.match_id ASC")->fetchAll(PDO::FETCH_COLUMN); foreach ($rows as $id) { $this->recordCompletedMatch($database, (new MatchRepository($database))->get((string) $id)); }
    }

    private function freeDate(DatabaseInterface $database, string $home, string $away, Season $season, SimulationDate $date): SimulationDate
    {
        $candidate = $date;
        $teamIds = [$home, $away];
        $clubs = $database->connection()->prepare('SELECT DISTINCT club_id FROM club_squad_memberships WHERE season_id = :season_id AND player_id IN (SELECT player_id FROM international_team_squads WHERE season_id = :squad_season AND national_team_id IN (:home, :away) AND status = \'selected\')');
        $clubs->execute(['season_id' => $season->id()->value(), 'squad_season' => $season->id()->value(), 'home' => $home, 'away' => $away]);
        $teamIds = array_values(array_unique(array_merge($teamIds, array_map('strval', $clubs->fetchAll(PDO::FETCH_COLUMN)))));
        $placeholders = implode(', ', array_map(static fn (int $index): string => ':team_' . $index, array_keys($teamIds)));
        $parameters = ['season_id' => $season->id()->value()]; foreach ($teamIds as $index => $teamId) { $parameters['team_' . $index] = $teamId; }
        $busyRows = $database->connection()->prepare('SELECT scheduled_date FROM match_records WHERE season_id = :season_id AND (home_club_id IN (' . $placeholders . ') OR away_club_id IN (' . $placeholders . '))');
        $busyRows->execute($parameters);
        $busyDates = array_fill_keys(array_map('strval', $busyRows->fetchAll(PDO::FETCH_COLUMN)), true);
        for ($attempt = 0; $attempt < 260; ++$attempt) { if (!isset($busyDates[$candidate->toIsoString()])) { return $candidate; } $candidate = $candidate->addDays(1); }
        throw new RuntimeException('No free international date found.');
    }

    private function drawKey(DatabaseInterface $database, string $competitionId, string $seasonId, string $stage, string $teamId): string
    {
        $seed = $database->connection()->query('SELECT universe_seed FROM world_records ORDER BY id ASC LIMIT 1')?->fetchColumn();

        return hash('sha256', 'international-draw:v1|' . (is_numeric($seed) ? $seed : 0) . '|' . $seasonId . '|' . $competitionId . '|' . $stage . '|' . $teamId);
    }

    private function bit(DatabaseInterface $database, string $key): bool
    {
        $seed = $database->connection()->query('SELECT universe_seed FROM world_records ORDER BY id ASC LIMIT 1')?->fetchColumn();

        return (hexdec(substr(hash('sha256', 'international-rng:v1|' . (is_numeric($seed) ? $seed : 0) . '|' . $key), 0, 8)) % 2) === 1;
    }
}
