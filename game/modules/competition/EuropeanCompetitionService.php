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
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\StandingsService;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Persistence\SeasonRepository;
use PDO;
use RuntimeException;

/**
 * Owns European qualification and GROUP_TO_KNOCKOUT structure. MatchService
 * remains the only football simulation owner; knockout resolution is shared
 * with DomesticCupService.
 */
final class EuropeanCompetitionService
{
    public const TIER_1 = 'europe-tier-1';
    public const TIER_2 = 'europe-tier-2';

    private const SEASONS = 'european_seasons';
    private const ENTRIES = 'european_entries';
    private const GROUPS = 'european_group_memberships';
    private const MATCHES = 'european_match_states';

    private readonly KnockoutResolutionService $knockout;

    public function __construct(
        private readonly ClubService $clubs,
        private readonly DomesticCupService $domesticCups,
    ) {
        $this->knockout = new KnockoutResolutionService($clubs);
    }

    public function initializeSchema(DatabaseInterface $database): void
    {
        SchemaInitializationGuard::run($database->connection(), self::class, function () use ($database): void {
            $database->connection()->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::SEASONS . ' ('
                . 'competition_id TEXT NOT NULL, season_id TEXT NOT NULL, participant_count INTEGER NOT NULL, '
                . 'group_count INTEGER NOT NULL, group_size INTEGER NOT NULL, current_stage TEXT NOT NULL, '
                . 'status TEXT NOT NULL, winner_club_id TEXT NULL, runner_up_club_id TEXT NULL, '
                . 'qualification_season_id TEXT NULL, PRIMARY KEY (competition_id, season_id))'
            );
            $database->connection()->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::ENTRIES . ' ('
                . 'competition_id TEXT NOT NULL, season_id TEXT NOT NULL, club_id TEXT NOT NULL, nation_id TEXT NOT NULL, '
                . 'qualification_source TEXT NOT NULL, qualification_position INTEGER NULL, qualification_competition_id TEXT NULL, '
                . 'seed INTEGER NOT NULL, status TEXT NOT NULL, group_name TEXT NULL, eliminated_stage TEXT NULL, '
                . 'PRIMARY KEY (competition_id, season_id, club_id))'
            );
            $database->connection()->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::GROUPS . ' ('
                . 'competition_id TEXT NOT NULL, season_id TEXT NOT NULL, group_name TEXT NOT NULL, '
                . 'club_id TEXT NOT NULL, seed INTEGER NOT NULL, PRIMARY KEY (competition_id, season_id, group_name, club_id))'
            );
            $database->connection()->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::MATCHES . ' ('
                . 'match_id TEXT PRIMARY KEY, competition_id TEXT NOT NULL, season_id TEXT NOT NULL, stage TEXT NOT NULL, '
                . 'group_name TEXT NULL, round_number INTEGER NOT NULL, resolved INTEGER NOT NULL DEFAULT 0, winner_club_id TEXT NULL, '
                . 'extra_time_home_goals INTEGER NULL, extra_time_away_goals INTEGER NULL, '
                . 'shootout_home_goals INTEGER NULL, shootout_away_goals INTEGER NULL)'
            );
            foreach (['resolved', 'extra_time_home_goals', 'extra_time_away_goals'] as $column) {
                $columns = $database->connection()->query('PRAGMA table_info(' . self::MATCHES . ')')->fetchAll(PDO::FETCH_COLUMN, 1);
                if (!in_array($column, $columns, true)) {
                    $definition = $column === 'resolved' ? 'INTEGER NOT NULL DEFAULT 0' : 'INTEGER NULL';
                    $database->connection()->exec('ALTER TABLE ' . self::MATCHES . ' ADD COLUMN ' . $column . ' ' . $definition);
                }
            }
            $database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_european_entries_status ON ' . self::ENTRIES . ' (competition_id, season_id, status, club_id)');
            $database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_european_matches_stage ON ' . self::MATCHES . ' (competition_id, season_id, stage, round_number, match_id)');
        });
    }

    public function isEuropeanCompetition(DatabaseInterface $database, string $competitionId): bool
    {
        $statement = $database->connection()->prepare('SELECT type FROM competition_records WHERE id = :id');
        $statement->execute(['id' => $competitionId]);

        return $statement->fetchColumn() === CompetitionType::Continental->value;
    }

    /** @param SeasonId|string|null $qualificationSeasonId */
    public function ensureSeason(DatabaseInterface $database, Season $season, SeasonId|string|null $qualificationSeasonId = null): void
    {
        $this->initializeSchema($database);
        $records = $database->connection()->prepare(
            'SELECT id FROM competition_records WHERE season_id = :season_id AND type = :type ORDER BY id ASC'
        );
        $records->execute(['season_id' => $season->id()->value(), 'type' => CompetitionType::Continental->value]);
        foreach ($records->fetchAll(PDO::FETCH_COLUMN) as $competitionId) {
            $this->ensureCompetition($database, $season, (string) $competitionId, $qualificationSeasonId);
        }
        $hasMatchTable = (int) $database->connection()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'match_records'")->fetchColumn() > 0;
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
        $existing = $matches->byCompetition($competition, $season->id());
        if ($existing !== []) {
            return $existing;
        }
        $leagueMatches = $database->connection()->prepare(
            "SELECT COUNT(*) FROM match_records m JOIN competition_records c ON c.id = m.competition_id "
            . "WHERE m.season_id = :season_id AND c.type = 'domestic_league'"
        );
        $leagueMatches->execute(['season_id' => $season->id()->value()]);
        if ((int) $leagueMatches->fetchColumn() === 0) {
            return [];
        }
        $this->scheduleGroupStage($database, $competition, $season);

        return $matches->byCompetition($competition, $season->id());
    }

    public function recordCompletedMatch(DatabaseInterface $database, GameMatch $match): void
    {
        if (!$this->isEuropeanCompetition($database, $match->competitionId()->value()) || $match->status() !== MatchStatus::Completed) {
            return;
        }
        $this->initializeSchema($database);
        $lookup = $database->connection()->prepare('SELECT * FROM ' . self::MATCHES . ' WHERE match_id = :match_id');
        $lookup->execute(['match_id' => $match->id()->value()]);
        $state = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!is_array($state) || (int) ($state['resolved'] ?? 0) === 1) {
            return;
        }
        $stage = (string) $state['stage'];
        if ($stage === 'Group Stage') {
            $resolved = $database->connection()->prepare('UPDATE ' . self::MATCHES . ' SET resolved = 1 WHERE match_id = :match_id AND resolved = 0');
            $resolved->execute(['match_id' => $match->id()->value()]);
            $this->advanceGroupStageIfReady($database, $match->competitionId()->value(), $match->seasonId());
            return;
        }

        $resolution = $this->knockout->resolve($database, $match, self::MATCHES);
        if (!is_array($resolution) || !is_string($resolution['winner_club_id'] ?? null)) {
            return;
        }
        $winner = (string) $resolution['winner_club_id'];
        $loser = $winner === $match->homeClubId()->value() ? $match->awayClubId()->value() : $match->homeClubId()->value();
        $round = (int) ($resolution['round'] ?? $match->round());
        $database->transaction(function () use ($database, $match, $loser, $stage, $round): void {
            $resolved = $database->connection()->prepare('UPDATE ' . self::MATCHES . ' SET resolved = 1 WHERE match_id = :match_id AND resolved = 0');
            $resolved->execute(['match_id' => $match->id()->value()]);
            $entry = $database->connection()->prepare(
                'UPDATE ' . self::ENTRIES . ' SET status = :status, eliminated_stage = :stage WHERE competition_id = :competition_id AND season_id = :season_id AND club_id = :club_id AND status <> :eliminated'
            );
            $entry->execute(['status' => 'eliminated', 'stage' => $stage, 'competition_id' => $match->competitionId()->value(), 'season_id' => $match->seasonId()->value(), 'club_id' => $loser, 'eliminated' => 'eliminated']);
        });
        $this->advanceKnockoutIfReady($database, $match->competitionId()->value(), $match->seasonId(), $stage, $round);
    }

    /** @return array<string, mixed>|null */
    public function matchResolution(DatabaseInterface $database, string $matchId): ?array
    {
        $this->initializeSchema($database);
        return $this->knockout->resolution($database, $matchId, self::MATCHES);
    }

    /** @return list<array<string, mixed>> */
    public function groupTables(DatabaseInterface $database, string $competitionId, SeasonId|string $seasonId): array
    {
        $season = $seasonId instanceof SeasonId ? $seasonId->value() : $seasonId;
        $groups = $database->connection()->prepare('SELECT DISTINCT group_name FROM ' . self::GROUPS . ' WHERE competition_id = :competition_id AND season_id = :season_id ORDER BY group_name ASC');
        $groups->execute(['competition_id' => $competitionId, 'season_id' => $season]);
        $standings = new StandingsService($this->clubs);
        $result = [];
        foreach ($groups->fetchAll(PDO::FETCH_COLUMN) as $groupName) {
            $members = $database->connection()->prepare('SELECT club_id FROM ' . self::GROUPS . ' WHERE competition_id = :competition_id AND season_id = :season_id AND group_name = :group_name ORDER BY club_id ASC');
            $members->execute(['competition_id' => $competitionId, 'season_id' => $season, 'group_name' => $groupName]);
            $clubIds = array_map('strval', $members->fetchAll(PDO::FETCH_COLUMN));
            $rows = $standings->tableForClubs($database, new CompetitionId($competitionId), new SeasonId($season), $clubIds);
            foreach ($rows as $index => &$row) {
                $row['position'] = $index + 1;
                $row['club'] = $this->clubs->repository($database)->get((string) $row['club_id'])->canonicalName();
            }
            unset($row);
            $result[] = ['group' => (string) $groupName, 'table' => $rows];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function view(DatabaseInterface $database, string $competitionId, SeasonId|string $seasonId, ?string $controlledClubId = null): array
    {
        $season = $seasonId instanceof SeasonId ? $seasonId->value() : $seasonId;
        $this->initializeSchema($database);
        $stateStatement = $database->connection()->prepare('SELECT * FROM ' . self::SEASONS . ' WHERE competition_id = :competition_id AND season_id = :season_id');
        $stateStatement->execute(['competition_id' => $competitionId, 'season_id' => $season]);
        $state = $stateStatement->fetch(PDO::FETCH_ASSOC);
        $clubs = $this->clubs->repository($database);
        $matches = new MatchRepository($database);
        $rounds = [];
        $rows = $database->connection()->prepare('SELECT * FROM ' . self::MATCHES . ' WHERE competition_id = :competition_id AND season_id = :season_id ORDER BY round_number ASC, match_id ASC');
        $rows->execute(['competition_id' => $competitionId, 'season_id' => $season]);
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $match = $matches->get((string) $row['match_id']);
            $round = (int) $row['round_number'];
            $rounds[$round]['round'] = $round;
            $rounds[$round]['stage'] = (string) $row['stage'];
            $rounds[$round]['group'] = $row['group_name'] === null ? null : (string) $row['group_name'];
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
                'resolution' => $this->matchResolution($database, $match->id()->value()),
                'controlled' => $controlledClubId !== null && in_array($controlledClubId, [$match->homeClubId()->value(), $match->awayClubId()->value()], true),
            ];
        }
        ksort($rounds);
        $entries = $database->connection()->prepare('SELECT * FROM ' . self::ENTRIES . ' WHERE competition_id = :competition_id AND season_id = :season_id ORDER BY seed ASC, club_id ASC');
        $entries->execute(['competition_id' => $competitionId, 'season_id' => $season]);
        $entryRows = [];
        foreach ($entries->fetchAll(PDO::FETCH_ASSOC) as $entry) {
            $entryRows[] = [
                'id' => (string) $entry['club_id'],
                'name' => $clubs->get((string) $entry['club_id'])->canonicalName(),
                'nation_id' => (string) $entry['nation_id'],
                'seed' => (int) $entry['seed'],
                'group' => $entry['group_name'],
                'status' => (string) $entry['status'],
                'qualification_source' => (string) $entry['qualification_source'],
                'qualification_position' => $entry['qualification_position'] === null ? null : (int) $entry['qualification_position'],
                'controlled' => (string) $entry['club_id'] === $controlledClubId,
            ];
        }

        return [
            'status' => is_array($state) ? (string) $state['status'] : 'not_initialized',
            'stage' => is_array($state) ? (string) $state['current_stage'] : null,
            'winner_club_id' => is_array($state) ? $state['winner_club_id'] : null,
            'runner_up_club_id' => is_array($state) ? $state['runner_up_club_id'] : null,
            'entries' => $entryRows,
            'groups' => $this->groupTables($database, $competitionId, $season),
            'rounds' => array_values($rounds),
            'remaining_clubs' => array_values(array_filter($entryRows, static fn (array $entry): bool => in_array($entry['status'], ['qualified', 'group_stage'], true))),
        ];
    }

    public function complete(DatabaseInterface $database, string $competitionId, SeasonId|string $seasonId): bool
    {
        $season = $seasonId instanceof SeasonId ? $seasonId->value() : $seasonId;
        $statement = $database->connection()->prepare('SELECT status FROM ' . self::SEASONS . ' WHERE competition_id = :competition_id AND season_id = :season_id');
        $statement->execute(['competition_id' => $competitionId, 'season_id' => $season]);

        $status = $statement->fetchColumn();
        if ($status === false) {
            return true;
        }
        $matches = $database->connection()->prepare('SELECT COUNT(*) FROM ' . self::MATCHES . ' WHERE competition_id = :competition_id AND season_id = :season_id');
        $matches->execute(['competition_id' => $competitionId, 'season_id' => $season]);
        if ((int) $matches->fetchColumn() === 0) {
            return true;
        }

        return $status === 'completed';
    }

    public function winner(DatabaseInterface $database, string $competitionId, SeasonId|string $seasonId): ?string
    {
        $season = $seasonId instanceof SeasonId ? $seasonId->value() : $seasonId;
        $statement = $database->connection()->prepare('SELECT winner_club_id FROM ' . self::SEASONS . ' WHERE competition_id = :competition_id AND season_id = :season_id');
        $statement->execute(['competition_id' => $competitionId, 'season_id' => $season]);
        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<array<string, mixed>> */
    public function historyForClub(DatabaseInterface $database, string $clubId): array
    {
        $this->initializeSchema($database);
        $statement = $database->connection()->prepare(
            'SELECT e.club_id, e.status AS club_status, e.qualification_source, e.qualification_position, '
            . 's.competition_id, s.season_id, s.status, s.current_stage, s.winner_club_id, s.runner_up_club_id, '
            . 'c.name AS competition_name FROM ' . self::ENTRIES . ' e JOIN ' . self::SEASONS . ' s '
            . 'ON s.competition_id = e.competition_id AND s.season_id = e.season_id '
            . 'JOIN competition_records c ON c.id = s.competition_id WHERE e.club_id = :club_id '
            . 'ORDER BY s.season_id DESC, s.competition_id ASC'
        );
        $statement->execute(['club_id' => $clubId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function ensureCompetition(DatabaseInterface $database, Season $season, string $competitionId, SeasonId|string|null $qualificationSeasonId): void
    {
        $exists = $database->connection()->prepare('SELECT 1 FROM ' . self::SEASONS . ' WHERE competition_id = :competition_id AND season_id = :season_id');
        $exists->execute(['competition_id' => $competitionId, 'season_id' => $season->id()->value()]);
        if ($exists->fetchColumn() !== false) {
            return;
        }
        $qualificationSeason = $qualificationSeasonId instanceof SeasonId ? $qualificationSeasonId->value() : $qualificationSeasonId;
        $entries = $this->qualificationEntries($database, $competitionId, $season->id(), $qualificationSeason);
        if (count($entries) < 2) {
            return;
        }
        usort($entries, fn (array $left, array $right): int => ($this->clubReputation($database, $right['club_id']) <=> $this->clubReputation($database, $left['club_id'])) ?: strcmp($left['club_id'], $right['club_id']));
        $database->transaction(function () use ($database, $competitionId, $season, $qualificationSeason, $entries): void {
            $state = $database->connection()->prepare(
                'INSERT INTO ' . self::SEASONS . ' (competition_id, season_id, participant_count, group_count, group_size, current_stage, status, qualification_season_id) VALUES (:competition_id, :season_id, :participant_count, 4, 4, :stage, :status, :qualification_season_id)'
            );
            $state->execute(['competition_id' => $competitionId, 'season_id' => $season->id()->value(), 'participant_count' => count($entries), 'stage' => 'group_stage', 'status' => 'active', 'qualification_season_id' => $qualificationSeason]);
            $entry = $database->connection()->prepare(
                'INSERT INTO ' . self::ENTRIES . ' (competition_id, season_id, club_id, nation_id, qualification_source, qualification_position, qualification_competition_id, seed, status) VALUES (:competition_id, :season_id, :club_id, :nation_id, :source, :position, :qualification_competition_id, :seed, :status)'
            );
            foreach ($entries as $index => $candidate) {
                $entry->execute(['competition_id' => $competitionId, 'season_id' => $season->id()->value(), 'club_id' => $candidate['club_id'], 'nation_id' => $candidate['nation_id'], 'source' => $candidate['source'], 'position' => $candidate['position'], 'qualification_competition_id' => $candidate['qualification_competition_id'], 'seed' => $index + 1, 'status' => 'group_stage']);
                $membership = new ClubCompetitionMembership(new ClubId($candidate['club_id']), new CompetitionId($competitionId), $season->id());
                if (!$this->clubs->membershipRepository($database)->exists($membership)) {
                    $this->clubs->membershipRepository($database)->save($membership);
                }
            }
        });
        $this->assignGroups($database, $competitionId, $season->id());
    }

    /** @return list<array{club_id:string,nation_id:string,source:string,position:int|null,qualification_competition_id:string|null}> */
    private function qualificationEntries(DatabaseInterface $database, string $competitionId, SeasonId $seasonId, ?string $previousSeasonId): array
    {
        $eligible = $this->eligibleClubs($database, $seasonId);
        if ($previousSeasonId === null || !$this->hasCompletedDomesticResults($database, $previousSeasonId)) {
            usort($eligible, fn (array $left, array $right): int => ($right['reputation'] <=> $left['reputation']) ?: strcmp($left['club_id'], $right['club_id']));
            $offset = $competitionId === self::TIER_1 ? 0 : 16;
            return array_map(static fn (array $club, int $index): array => ['club_id' => $club['club_id'], 'nation_id' => $club['nation_id'], 'source' => 'initial_seeding', 'position' => $index + 1, 'qualification_competition_id' => null], array_slice($eligible, $offset, 16), range($offset, $offset + 15));
        }

        $byNation = [];
        foreach ($eligible as $club) { $byNation[$club['nation_id']][] = $club; }
        foreach ($byNation as &$clubs) {
            $leagueId = $this->leagueId($database, $previousSeasonId, (string) $clubs[0]['nation_id']);
            $table = $leagueId === null ? [] : (new StandingsService($this->clubs))->table($database, new CompetitionId($leagueId), new SeasonId($previousSeasonId));
            $ranked = [];
            foreach ($table as $index => $row) {
                $candidate = $this->clubFromEligible($clubs, (string) $row['club_id']);
                if ($candidate !== null) { $candidate['position'] = $index + 1; $ranked[] = $candidate; }
            }
            foreach ($clubs as $club) {
                if (array_search($club['club_id'], array_column($ranked, 'club_id'), true) === false) { $club['position'] = count($ranked) + 1; $ranked[] = $club; }
            }
            $clubs = $ranked;
        }
        unset($clubs);

        $selected = [];
        $selectedIds = [];
        $add = static function (array $club, string $source, ?string $qualificationCompetitionId = null) use (&$selected, &$selectedIds): void {
            if (isset($selectedIds[$club['club_id']])) { return; }
            $selectedIds[$club['club_id']] = true;
            $selected[] = ['club_id' => $club['club_id'], 'nation_id' => $club['nation_id'], 'source' => $source, 'position' => $club['position'] ?? null, 'qualification_competition_id' => $qualificationCompetitionId];
        };
        foreach ($byNation as $nationId => $clubs) {
            if ($competitionId === self::TIER_1) {
                foreach (array_slice($clubs, 0, 2) as $club) { $add($club, 'league_position', $this->leagueId($database, $previousSeasonId, $nationId)); }
                $cupId = $this->cupId($database, $previousSeasonId, $nationId);
                $cupWinner = $cupId === null ? null : $this->domesticCups->winnerClubId($database, $cupId, $previousSeasonId);
                if ($cupWinner !== null) {
                    $cupClub = $this->clubFromEligible($eligible, $cupWinner) ?? ['club_id' => $cupWinner, 'nation_id' => $nationId, 'position' => null, 'reputation' => 0];
                    $add($cupClub, 'cup_winner', $cupId);
                }
                foreach ($clubs as $club) { if (count(array_filter($selected, static fn (array $row): bool => $row['nation_id'] === $nationId)) >= 3) { break; } $add($club, 'league_position', $this->leagueId($database, $previousSeasonId, $nationId)); }
            }
        }
        if ($competitionId === self::TIER_1) {
            $candidates = $this->flattenNationClubs($byNation);
            usort($candidates, static fn (array $left, array $right): int => (($left['position'] ?? 99) <=> ($right['position'] ?? 99)) ?: ($right['reputation'] <=> $left['reputation']) ?: strcmp($left['club_id'], $right['club_id']));
            foreach ($candidates as $club) { if (count($selected) >= 16) { break; } $add($club, 'continental_wildcard', $this->leagueId($database, $previousSeasonId, $club['nation_id'])); }
            return array_slice($selected, 0, 16);
        }

        $tierOneIds = [];
        $tierOne = $this->qualificationEntries($database, self::TIER_1, $seasonId, $previousSeasonId);
        foreach ($tierOne as $row) { $tierOneIds[$row['club_id']] = true; }
        $selected = [];
        $selectedIds = $tierOneIds;
        $addTierTwo = static function (array $club, string $source, ?string $qualificationCompetitionId = null) use (&$selected, &$selectedIds): void {
            if (isset($selectedIds[$club['club_id']])) { return; }
            $selectedIds[$club['club_id']] = true;
            $selected[] = ['club_id' => $club['club_id'], 'nation_id' => $club['nation_id'], 'source' => $source, 'position' => $club['position'] ?? null, 'qualification_competition_id' => $qualificationCompetitionId];
        };
        foreach ($byNation as $nationId => $clubs) {
            foreach (array_slice($clubs, 2, 3) as $club) { $addTierTwo($club, 'league_position', $this->leagueId($database, $previousSeasonId, $nationId)); }
        }
        $candidates = $this->flattenNationClubs($byNation);
        usort($candidates, static fn (array $left, array $right): int => (($left['position'] ?? 99) <=> ($right['position'] ?? 99)) ?: ($right['reputation'] <=> $left['reputation']) ?: strcmp($left['club_id'], $right['club_id']));
        foreach ($candidates as $club) { if (count($selected) >= 16) { break; } $addTierTwo($club, 'continental_wildcard', $this->leagueId($database, $previousSeasonId, $club['nation_id'])); }

        return array_slice($selected, 0, 16);
    }

    private function assignGroups(DatabaseInterface $database, string $competitionId, SeasonId $seasonId): void
    {
        $entries = $database->connection()->prepare('SELECT club_id, nation_id, seed FROM ' . self::ENTRIES . ' WHERE competition_id = :competition_id AND season_id = :season_id ORDER BY seed ASC');
        $entries->execute(['competition_id' => $competitionId, 'season_id' => $seasonId->value()]);
        $rows = $entries->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 16) { throw new RuntimeException('European V1 requires exactly 16 Clubs per tier.'); }
        $groups = ['A', 'B', 'C', 'D'];
        $usedNations = array_fill_keys($groups, []);
        $membership = $database->connection()->prepare('INSERT OR IGNORE INTO ' . self::GROUPS . ' (competition_id, season_id, group_name, club_id, seed) VALUES (:competition_id, :season_id, :group_name, :club_id, :seed)');
        $update = $database->connection()->prepare('UPDATE ' . self::ENTRIES . ' SET group_name = :group_name WHERE competition_id = :competition_id AND season_id = :season_id AND club_id = :club_id');
        foreach (array_chunk($rows, 4) as $potIndex => $pot) {
            usort($pot, fn (array $left, array $right): int => strcmp($this->drawKey($database, $competitionId, $seasonId->value(), 'group', $potIndex + 1, $left['club_id']), $this->drawKey($database, $competitionId, $seasonId->value(), 'group', $potIndex + 1, $right['club_id'])));
            foreach ($pot as $row) {
                $available = array_values(array_filter($groups, static fn (string $group): bool => count($usedNations[$group]) < 4 && !in_array((string) $row['nation_id'], $usedNations[$group], true)));
                if ($available === []) { $available = $groups; }
                $available = array_values(array_filter($available, static fn (string $group): bool => count($usedNations[$group]) < 4));
                usort($available, fn (string $left, string $right): int => strcmp($this->drawKey($database, $competitionId, $seasonId->value(), 'group-slot', $potIndex + 1, $left), $this->drawKey($database, $competitionId, $seasonId->value(), 'group-slot', $potIndex + 1, $right)));
                $group = $available[0];
                $usedNations[$group][] = (string) $row['nation_id'];
                $membership->execute(['competition_id' => $competitionId, 'season_id' => $seasonId->value(), 'group_name' => $group, 'club_id' => $row['club_id'], 'seed' => $row['seed']]);
                $update->execute(['group_name' => $group, 'competition_id' => $competitionId, 'season_id' => $seasonId->value(), 'club_id' => $row['club_id']]);
            }
        }
    }

    private function scheduleGroupStage(DatabaseInterface $database, string $competitionId, Season $season): void
    {
        $groups = $database->connection()->prepare('SELECT group_name, club_id FROM ' . self::GROUPS . ' WHERE competition_id = :competition_id AND season_id = :season_id ORDER BY group_name ASC, club_id ASC');
        $groups->execute(['competition_id' => $competitionId, 'season_id' => $season->id()->value()]);
        $byGroup = [];
        foreach ($groups->fetchAll(PDO::FETCH_ASSOC) as $row) { $byGroup[(string) $row['group_name']][] = (string) $row['club_id']; }
        $matches = new MatchRepository($database);
        $state = $database->connection()->prepare('INSERT OR IGNORE INTO ' . self::MATCHES . ' (match_id, competition_id, season_id, stage, group_name, round_number, resolved) VALUES (:match_id, :competition_id, :season_id, :stage, :group_name, :round_number, 0)');
        $pairs = [[0, 1], [0, 2], [0, 3], [1, 2], [1, 3], [2, 3]];
        foreach ($byGroup as $groupName => $clubs) {
            if (count($clubs) !== 4) { throw new RuntimeException('European group must contain exactly four Clubs.'); }
            foreach ($pairs as $round => [$homeIndex, $awayIndex]) {
                $first = $clubs[$homeIndex]; $second = $clubs[$awayIndex];
                if (hexdec(substr(hash('sha256', 'europe-home:v1|' . $this->worldSeed($database) . '|' . $competitionId . '|' . $season->id()->value() . '|group|' . $groupName . '|' . $round), 0, 8)) % 2 === 1) { [$first, $second] = [$second, $first]; }
                $date = $this->freeDate($matches, $first, $second, $season, $season->startDate()->addDays(55 + ($round * 30)));
                $match = new GameMatch(new MatchId(sprintf('europe.%s.%s.g%s.r%02d.%s.%s', $competitionId, $season->id()->value(), strtolower($groupName), $round + 1, $first, $second)), new CompetitionId($competitionId), $season->id(), $round + 1, $date, new ClubId($first), new ClubId($second));
                $matches->save($match);
                $state->execute(['match_id' => $match->id()->value(), 'competition_id' => $competitionId, 'season_id' => $season->id()->value(), 'stage' => 'Group Stage', 'group_name' => $groupName, 'round_number' => $round + 1]);
            }
        }
    }

    private function advanceGroupStageIfReady(DatabaseInterface $database, string $competitionId, SeasonId $seasonId): void
    {
        $state = $database->connection()->prepare('SELECT current_stage FROM ' . self::SEASONS . ' WHERE competition_id = :competition_id AND season_id = :season_id');
        $state->execute(['competition_id' => $competitionId, 'season_id' => $seasonId->value()]);
        if ($state->fetchColumn() !== 'group_stage') { return; }
        $pending = $database->connection()->prepare('SELECT COUNT(*) FROM ' . self::MATCHES . ' s JOIN match_records m ON m.id = s.match_id WHERE s.competition_id = :competition_id AND s.season_id = :season_id AND s.stage = :stage AND (s.resolved = 0 OR m.status <> :status)');
        $pending->execute(['competition_id' => $competitionId, 'season_id' => $seasonId->value(), 'stage' => 'Group Stage', 'status' => MatchStatus::Completed->value]);
        if ((int) $pending->fetchColumn() > 0) { return; }
        $tables = $this->groupTables($database, $competitionId, $seasonId);
        $qualified = [];
        $update = $database->connection()->prepare('UPDATE ' . self::ENTRIES . ' SET status = :status, eliminated_stage = :eliminated_stage WHERE competition_id = :competition_id AND season_id = :season_id AND club_id = :club_id');
        foreach ($tables as $group) {
            foreach (array_values($group['table']) as $index => $row) {
                $status = $index < 2 ? 'qualified' : 'eliminated';
                $update->execute(['status' => $status, 'eliminated_stage' => $status === 'eliminated' ? 'Group Stage' : null, 'competition_id' => $competitionId, 'season_id' => $seasonId->value(), 'club_id' => $row['club_id']]);
                if ($index < 2) { $qualified[(string) $group['group']][] = (string) $row['club_id']; }
            }
        }
        $season = (new SeasonRepository($database))->get($seasonId);
        $this->scheduleKnockout($database, $competitionId, $season, 'Quarter-finals', 7, $this->quarterFinalPairs($qualified));
    }

    /** @param array<string, list<string>> $qualified @return list<array{0:string,1:string}> */
    private function quarterFinalPairs(array $qualified): array
    {
        return [[$qualified['A'][0], $qualified['B'][1]], [$qualified['B'][0], $qualified['A'][1]], [$qualified['C'][0], $qualified['D'][1]], [$qualified['D'][0], $qualified['C'][1]]];
    }

    private function advanceKnockoutIfReady(DatabaseInterface $database, string $competitionId, SeasonId $seasonId, string $stage, int $round): void
    {
        $pending = $database->connection()->prepare('SELECT COUNT(*) FROM ' . self::MATCHES . ' s JOIN match_records m ON m.id = s.match_id WHERE s.competition_id = :competition_id AND s.season_id = :season_id AND s.stage = :stage AND (s.resolved = 0 OR m.status <> :status)');
        $pending->execute(['competition_id' => $competitionId, 'season_id' => $seasonId->value(), 'stage' => $stage, 'status' => MatchStatus::Completed->value]);
        if ((int) $pending->fetchColumn() > 0) { return; }
        $season = (new SeasonRepository($database))->get($seasonId);
        if ($stage === 'Quarter-finals') {
            $this->scheduleKnockout($database, $competitionId, $season, 'Semi-finals', 8, $this->winnersAsPairs($database, $competitionId, $seasonId, $stage));
        } elseif ($stage === 'Semi-finals') {
            $this->scheduleKnockout($database, $competitionId, $season, 'Final', 9, $this->winnersAsPairs($database, $competitionId, $seasonId, $stage));
        } else {
            $final = $database->connection()->prepare('SELECT match_id FROM ' . self::MATCHES . ' WHERE competition_id = :competition_id AND season_id = :season_id AND stage = :stage ORDER BY match_id ASC LIMIT 1');
            $final->execute(['competition_id' => $competitionId, 'season_id' => $seasonId->value(), 'stage' => 'Final']);
            $matchId = (string) $final->fetchColumn();
            $match = (new MatchRepository($database))->get($matchId);
            $winner = $this->winnerForMatch($database, $matchId);
            $runner = $winner === $match->homeClubId()->value() ? $match->awayClubId()->value() : $match->homeClubId()->value();
            $complete = $database->connection()->prepare('UPDATE ' . self::SEASONS . ' SET current_stage = :stage, status = :status, winner_club_id = :winner, runner_up_club_id = :runner WHERE competition_id = :competition_id AND season_id = :season_id AND status = :active');
            $complete->execute(['stage' => 'completed', 'status' => 'completed', 'winner' => $winner, 'runner' => $runner, 'competition_id' => $competitionId, 'season_id' => $seasonId->value(), 'active' => 'active']);
            $this->markFinalEntries($database, $competitionId, $seasonId, $winner, $runner);
        }
    }

    /** @param list<array{0:string,1:string}> $pairs */
    private function scheduleKnockout(DatabaseInterface $database, string $competitionId, Season $season, string $stage, int $round, array $pairs): void
    {
        $existing = $database->connection()->prepare('SELECT COUNT(*) FROM ' . self::MATCHES . ' WHERE competition_id = :competition_id AND season_id = :season_id AND stage = :stage');
        $existing->execute(['competition_id' => $competitionId, 'season_id' => $season->id()->value(), 'stage' => $stage]);
        if ((int) $existing->fetchColumn() > 0) { return; }
        $offset = match ($stage) { 'Quarter-finals' => 245, 'Semi-finals' => 275, default => 300 };
        $matches = new MatchRepository($database);
        $state = $database->connection()->prepare('INSERT INTO ' . self::MATCHES . ' (match_id, competition_id, season_id, stage, group_name, round_number, resolved) VALUES (:match_id, :competition_id, :season_id, :stage, NULL, :round, 0)');
        foreach ($pairs as $index => [$first, $second]) {
            $swap = hexdec(substr(hash('sha256', 'europe-knockout-home:v1|' . $this->worldSeed($database) . '|' . $competitionId . '|' . $season->id()->value() . '|' . $stage . '|' . $index), 0, 8)) % 2 === 1;
            if ($swap) { [$first, $second] = [$second, $first]; }
            $date = $this->freeDate($matches, $first, $second, $season, $season->startDate()->addDays($offset));
            $match = new GameMatch(new MatchId(sprintf('europe.%s.%s.%s.%02d.%s.%s', $competitionId, $season->id()->value(), strtolower(str_replace('-', '', $stage)), $index + 1, $first, $second)), new CompetitionId($competitionId), $season->id(), $round, $date, new ClubId($first), new ClubId($second));
            $matches->save($match);
            $state->execute(['match_id' => $match->id()->value(), 'competition_id' => $competitionId, 'season_id' => $season->id()->value(), 'stage' => $stage, 'round' => $round]);
        }
        $update = $database->connection()->prepare('UPDATE ' . self::SEASONS . ' SET current_stage = :stage WHERE competition_id = :competition_id AND season_id = :season_id');
        $update->execute(['stage' => strtolower(str_replace('-', '_', $stage)), 'competition_id' => $competitionId, 'season_id' => $season->id()->value()]);
    }

    /** @return list<array{0:string,1:string}> */
    private function winnersAsPairs(DatabaseInterface $database, string $competitionId, SeasonId $seasonId, string $stage): array
    {
        $rows = $database->connection()->prepare('SELECT match_id FROM ' . self::MATCHES . ' WHERE competition_id = :competition_id AND season_id = :season_id AND stage = :stage ORDER BY match_id ASC');
        $rows->execute(['competition_id' => $competitionId, 'season_id' => $seasonId->value(), 'stage' => $stage]);
        $winners = array_map(fn (string $matchId): string => $this->winnerForMatch($database, $matchId), array_map('strval', $rows->fetchAll(PDO::FETCH_COLUMN)));
        $pairs = [];
        for ($index = 0; $index < count($winners); $index += 2) { $pairs[] = [$winners[$index], $winners[$index + 1]]; }

        return $pairs;
    }

    private function winnerForMatch(DatabaseInterface $database, string $matchId): string
    {
        $row = $database->connection()->prepare('SELECT winner_club_id FROM ' . self::MATCHES . ' WHERE match_id = :match_id');
        $row->execute(['match_id' => $matchId]);
        $winner = $row->fetchColumn();
        if (!is_string($winner) || $winner === '') { throw new RuntimeException('Completed European knockout Match has no winner.'); }

        return $winner;
    }

    private function markFinalEntries(DatabaseInterface $database, string $competitionId, SeasonId $seasonId, string $winner, string $runner): void
    {
        $statement = $database->connection()->prepare('UPDATE ' . self::ENTRIES . ' SET status = :status WHERE competition_id = :competition_id AND season_id = :season_id AND club_id = :club_id');
        $statement->execute(['status' => 'winner', 'competition_id' => $competitionId, 'season_id' => $seasonId->value(), 'club_id' => $winner]);
        $statement->execute(['status' => 'runner_up', 'competition_id' => $competitionId, 'season_id' => $seasonId->value(), 'club_id' => $runner]);
    }

    private function reconcileCompletedMatches(DatabaseInterface $database): void
    {
        $rows = $database->connection()->query('SELECT s.match_id FROM ' . self::MATCHES . ' s JOIN match_records m ON m.id = s.match_id WHERE m.status = ' . $database->connection()->quote(MatchStatus::Completed->value) . ' AND s.resolved = 0 ORDER BY s.competition_id ASC, s.round_number ASC, s.match_id ASC')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $matchId) { $this->recordCompletedMatch($database, (new MatchRepository($database))->get((string) $matchId)); }
    }

    /** @return list<array{club_id:string,nation_id:string,reputation:int}> */
    private function eligibleClubs(DatabaseInterface $database, SeasonId $seasonId): array
    {
        $statement = $database->connection()->prepare(
            "SELECT DISTINCT club.id AS club_id, club.nation_id, club.reputation FROM club_competition_memberships m JOIN competition_records c ON c.id = m.competition_id JOIN club_records club ON club.id = m.club_id WHERE m.season_id = :season_id AND c.type = 'domestic_league' AND c.tier <= 2 ORDER BY club.id ASC"
        );
        $statement->execute(['season_id' => $seasonId->value()]);
        return array_map(static fn (array $row): array => ['club_id' => (string) $row['club_id'], 'nation_id' => (string) $row['nation_id'], 'reputation' => (int) $row['reputation']], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function hasCompletedDomesticResults(DatabaseInterface $database, string $seasonId): bool
    {
        $statement = $database->connection()->prepare("SELECT COUNT(*) FROM match_records m JOIN competition_records c ON c.id = m.competition_id WHERE m.season_id = :season_id AND c.type = 'domestic_league' AND m.status = 'completed'");
        $statement->execute(['season_id' => $seasonId]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function leagueId(DatabaseInterface $database, string $seasonId, string $nationId): ?string
    {
        $statement = $database->connection()->prepare("SELECT id FROM competition_records WHERE season_id = :season_id AND nation_id = :nation_id AND type = 'domestic_league' AND tier = 1 ORDER BY id ASC LIMIT 1");
        $statement->execute(['season_id' => $seasonId, 'nation_id' => $nationId]);
        $value = $statement->fetchColumn();
        return is_string($value) ? $value : null;
    }

    private function cupId(DatabaseInterface $database, string $seasonId, string $nationId): ?string
    {
        $statement = $database->connection()->prepare("SELECT id FROM competition_records WHERE season_id = :season_id AND nation_id = :nation_id AND type = 'domestic_cup' ORDER BY id ASC LIMIT 1");
        $statement->execute(['season_id' => $seasonId, 'nation_id' => $nationId]);
        $value = $statement->fetchColumn();
        return is_string($value) ? $value : null;
    }

    /** @param list<array{club_id:string,nation_id:string,reputation:int,position?:int}> $clubs */
    private function clubFromEligible(array $clubs, string $clubId): ?array
    {
        foreach ($clubs as $club) { if ($club['club_id'] === $clubId) { return $club; } }
        return null;
    }

    /** @param array<string, list<array{club_id:string,nation_id:string,reputation:int,position?:int}>> $byNation */
    private function flattenNationClubs(array $byNation): array
    {
        $result = [];
        foreach ($byNation as $clubs) { foreach ($clubs as $club) { $result[] = $club; } }
        return $result;
    }

    private function clubReputation(DatabaseInterface $database, string $clubId): int
    {
        return $this->clubs->repository($database)->get(new ClubId($clubId))->reputation();
    }

    private function drawKey(DatabaseInterface $database, string $competitionId, string $seasonId, string $stage, int|string $round, string $clubId): string
    {
        return hash('sha256', 'europe-draw:v1|' . $this->worldSeed($database) . '|' . $seasonId . '|' . $competitionId . '|' . $stage . '|' . $round . '|' . $clubId);
    }

    private function worldSeed(DatabaseInterface $database): int
    {
        $value = $database->connection()->query('SELECT universe_seed FROM world_records ORDER BY id ASC LIMIT 1')?->fetchColumn();
        return is_numeric($value) ? (int) $value : 0;
    }

    private function freeDate(MatchRepository $matches, string $home, string $away, Season $season, SimulationDate $date): SimulationDate
    {
        $candidate = $date;
        for ($attempt = 0; $attempt < 46; ++$attempt) {
            $homeBusy = array_filter($matches->byClub($home, $season->id()), static fn (GameMatch $match): bool => $match->scheduledDate()->toIsoString() === $candidate->toIsoString());
            $awayBusy = array_filter($matches->byClub($away, $season->id()), static fn (GameMatch $match): bool => $match->scheduledDate()->toIsoString() === $candidate->toIsoString());
            if ($homeBusy === [] && $awayBusy === []) { return $candidate; }
            $candidate = $candidate->addDays(1);
        }
        throw new RuntimeException(sprintf('No free European date found for %s vs %s.', $home, $away));
    }
}
