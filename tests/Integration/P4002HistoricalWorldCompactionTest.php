<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Core\Persistence\SqliteStorageAttribution;
use Goal\Legacy\Core\Time\SimulationTime;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Match\MatchStoryService;
use Goal\Legacy\Modules\Player\CompetitionStatisticsQuery;
use Goal\Legacy\Modules\Player\PlayerSeasonPerformanceService;
use Goal\Legacy\Modules\World\SeasonCompactionService;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class P4002HistoricalWorldCompactionTest extends TestCase
{
    private string $root;
    private SqliteSaveStore $store;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/goal-legacy-p4-002-test-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/saves', 0775, true)) {
            throw new RuntimeException('Unable to create P4-002 isolated storage fixture.');
        }
        $this->store = new SqliteSaveStore($this->root . '/saves', new JsonSerializer());
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testFinalizedSeasonCompactionIsRowAwareMaterialAndReopenable(): void
    {
        $this->createSave('historical-save');
        $database = $this->store->openDatabase('historical-save');
        $this->schema($database);
        $this->fixtures($database, 120);

        $beforePerformance = $this->performance($database);
        $beforeCompetition = (new CompetitionStatisticsQuery())->forCompetitionSeason($database, 'competition-1', new SeasonId('season-2024-25'));
        $beforeCheckpoint = $this->checkpoint($database);
        $attribution = new SqliteStorageAttribution();
        $beforeStorage = $attribution->inspect($this->root . '/saves/historical-save.sqlite');
        $beforeTargetBytes = $this->targetBytes($beforeStorage);
        $beforeTargetRows = $this->targetRows($beforeStorage);

        $result = (new SeasonCompactionService())->compact($database, 'season-2024-25', '2025-08-01');

        self::assertTrue($result['compacted']);
        self::assertGreaterThan(0, $result['logical_rows_removed']);
        self::assertGreaterThan(0, $result['stats']);
        self::assertGreaterThan(0, $result['selections']);
        self::assertGreaterThan(0, $result['highlights']);
        self::assertGreaterThan(0, $result['substitutions']);
        self::assertGreaterThan(0, $result['evaluations']);
        self::assertGreaterThan(0, $result['development']);
        self::assertGreaterThan(0, $result['availability']);
        self::assertTrue($result['physical_compaction_recommended']);
        self::assertLessThan($beforeTargetRows, array_sum((array) $result['target_rows_after']));

        $afterLogicalPerformance = $this->performance($database);
        self::assertSame($beforePerformance, $afterLogicalPerformance);
        self::assertSame($beforeCompetition, (new CompetitionStatisticsQuery())->forCompetitionSeason($database, 'competition-1', new SeasonId('season-2024-25')));
        self::assertSame($beforeCheckpoint, $this->checkpoint($database));
        self::assertTrue((new MatchStoryService())->integrity($database, $this->protectedMatch())['valid']);

        $second = (new SeasonCompactionService())->compact($database, 'season-2024-25', '2025-08-01');
        self::assertFalse($second['compacted']);
        self::assertTrue($second['idempotent']);
        self::assertSame($beforeCheckpoint, $this->checkpoint($database));

        unset($database);
        $logicalStorage = $attribution->inspect($this->root . '/saves/historical-save.sqlite');
        $logicalTargetBytes = $this->targetBytes($logicalStorage);
        self::assertLessThan($beforeTargetRows, $this->targetRows($logicalStorage));
        self::assertLessThan($beforeTargetBytes, $logicalTargetBytes);
        self::assertGreaterThanOrEqual(0.50, ($beforeTargetBytes - $logicalTargetBytes) / $beforeTargetBytes);

        $physicalBefore = $this->store->size('historical-save');
        $physical = $this->store->compact('historical-save', fn (DatabaseInterface $reopened): array => $this->checkpoint($reopened));
        self::assertLessThan($physicalBefore, $this->store->size('historical-save'));
        self::assertGreaterThan(0, $physical['bytes_reclaimed']);
        self::assertSame('PRESERVED', $physical['semantic_checkpoint']);
        self::assertSame('ok', $physical['after']['integrity_check']);
        self::assertSame(0, $physical['after']['foreign_key_violations']);

        $reopened = $this->store->openDatabase('historical-save');
        self::assertSame($beforePerformance, $this->performance($reopened));
        self::assertSame($beforeCheckpoint, $this->checkpoint($reopened));
        self::assertTrue((new MatchStoryService())->integrity($reopened, $this->protectedMatch())['valid']);
        self::assertSame([], $reopened->connection()->query('PRAGMA foreign_key_check')->fetchAll());
        unset($reopened);

        $afterStorage = $attribution->inspect($this->root . '/saves/historical-save.sqlite');
        self::assertLessThanOrEqual($beforeTargetBytes, $this->targetBytes($afterStorage));
        self::assertLessThanOrEqual($beforeTargetRows, $this->targetRows($afterStorage));
    }

    public function testNonFinalizedSeasonAndFailedTransactionCannotPartiallyCompact(): void
    {
        $this->createSave('rollback-save');
        $database = $this->store->openDatabase('rollback-save');
        $this->schema($database);
        $this->fixtures($database, 12);
        $connection = $database->connection();
        $beforeStats = (int) $connection->query('SELECT COUNT(*) FROM match_player_stats')->fetchColumn();

        $connection->exec("UPDATE season_records SET status = 'active' WHERE id = 'season-2024-25'");
        try {
            (new SeasonCompactionService())->compact($database, 'season-2024-25', '2025-08-01');
            self::fail('Active Seasons must not be compacted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('completed Season', $exception->getMessage());
        }
        self::assertSame($beforeStats, (int) $connection->query('SELECT COUNT(*) FROM match_player_stats')->fetchColumn());

        $connection->exec("UPDATE season_records SET status = 'completed' WHERE id = 'season-2024-25'");
        $connection->exec("CREATE TRIGGER p4_fail_compaction BEFORE DELETE ON match_player_stats WHEN OLD.player_id LIKE 'npc-%' BEGIN SELECT RAISE(ABORT, 'P4-002 forced rollback'); END");
        try {
            (new SeasonCompactionService())->compact($database, 'season-2024-25', '2025-08-01');
            self::fail('Compaction failure must propagate.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('P4-002 forced rollback', $exception->getMessage());
        }

        self::assertSame($beforeStats, (int) $connection->query('SELECT COUNT(*) FROM match_player_stats')->fetchColumn());
        self::assertSame(0, (int) $connection->query('SELECT COUNT(*) FROM save_compaction_seasons')->fetchColumn());
        self::assertSame(0, (int) $connection->query('SELECT COUNT(*) FROM player_season_statistics')->fetchColumn());
    }

    /** @return array<string, array<string, mixed>> */
    private function performance(DatabaseInterface $database): array
    {
        $result = [];
        foreach ((new PlayerSeasonPerformanceService())->assessMany($database, new SeasonId('season-2024-25')) as $playerId => $assessment) {
            $result[(string) $playerId] = $assessment->toArray();
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @return array<string, mixed> */
    private function checkpoint(DatabaseInterface $database): array
    {
        $connection = $database->connection();

        return [
            'season' => $connection->query("SELECT id, status FROM season_records WHERE id = 'season-2024-25'")->fetch(PDO::FETCH_ASSOC),
            'protected_match' => $connection->query("SELECT id, home_club_id, away_club_id, home_goals, away_goals FROM match_records WHERE id = 'protected-match'")->fetch(PDO::FETCH_ASSOC),
            'controlled_stats' => $connection->query("SELECT match_id, player_id, minutes, goals, assists FROM match_player_stats WHERE player_id = 'controlled-1' ORDER BY match_id")->fetchAll(PDO::FETCH_ASSOC),
            'protected_stats' => $connection->query("SELECT player_id, minutes, goals FROM match_player_stats WHERE match_id = 'protected-match' ORDER BY player_id")->fetchAll(PDO::FETCH_ASSOC),
            'protected_selections' => $connection->query("SELECT player_id, status FROM match_player_selections WHERE match_id = 'protected-match' ORDER BY player_id")->fetchAll(PDO::FETCH_ASSOC),
            'protected_highlights' => $connection->query("SELECT sequence_number, minute, type, player_id, data_json FROM match_highlights WHERE match_id = 'protected-match' ORDER BY sequence_number")->fetchAll(PDO::FETCH_ASSOC),
            'protected_substitutions' => $connection->query("SELECT club_id, sequence_number, outgoing_player_id, incoming_player_id, minute FROM match_substitutions WHERE match_id = 'protected-match' ORDER BY sequence_number")->fetchAll(PDO::FETCH_ASSOC),
            'controlled_development' => $connection->query("SELECT id, before_ovr, after_ovr FROM player_development_history WHERE player_id = 'controlled-1' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
            'controlled_availability' => $connection->query("SELECT player_id, source_type, source_id, occurred_date FROM player_availability_sources WHERE player_id = 'controlled-1' ORDER BY source_id")->fetchAll(PDO::FETCH_ASSOC),
            'competition_aggregate' => $connection->query("SELECT player_id, season_id, competition_id, goals, appearances FROM player_competition_statistics WHERE player_id = 'npc-1'")->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /** @param array<string, mixed> $report */
    private function targetBytes(array $report): int
    {
        $target = array_fill_keys([
            'match_player_stats',
            'match_player_selections',
            'match_highlights',
            'match_substitutions',
            'career_match_evaluations',
            'player_development_history',
            'player_availability_sources',
        ], true);
        $bytes = 0;
        foreach ((array) ($report['tables'] ?? []) as $table) {
            if (!isset($target[(string) ($table['table_name'] ?? '')])) {
                continue;
            }
            $bytes += (int) ($table['approx_storage_bytes'] ?? 0);
            $bytes += (int) ($table['index_storage_bytes'] ?? 0);
        }

        return $bytes;
    }

    /** @param array<string, mixed> $report */
    private function targetRows(array $report): int
    {
        $target = array_fill_keys([
            'match_player_stats',
            'match_player_selections',
            'match_highlights',
            'match_substitutions',
            'career_match_evaluations',
            'player_development_history',
            'player_availability_sources',
        ], true);
        $rows = 0;
        foreach ((array) ($report['tables'] ?? []) as $table) {
            if (isset($target[(string) ($table['table_name'] ?? '')])) {
                $rows += (int) ($table['row_count'] ?? 0);
            }
        }

        return $rows;
    }

    private function protectedMatch(): GameMatch
    {
        return new GameMatch(
            new MatchId('protected-match'),
            new CompetitionId('competition-1'),
            new SeasonId('season-2024-25'),
            1,
            SimulationDate::fromIsoString('2025-05-01'),
            new ClubId('club-protected'),
            new ClubId('club-rival'),
            MatchStatus::Completed,
            new MatchResult(1, 0),
        );
    }

    private function createSave(string $id): void
    {
        $this->store->create(SaveMetadata::create($id, 'P4-002 ' . $id, new SimulationTime(0), new DateTimeImmutable('@0'), 'p4-002-test'));
    }

    private function schema(DatabaseInterface $database): void
    {
        $connection = $database->connection();
        $connection->exec('CREATE TABLE season_records (id TEXT PRIMARY KEY, label TEXT NOT NULL, start_date TEXT NOT NULL, end_date TEXT NOT NULL, status TEXT NOT NULL)');
        $connection->exec("INSERT INTO season_records VALUES ('season-2024-25', '2024/25', '2024-08-01', '2025-05-31', 'completed')");
        $connection->exec('CREATE TABLE career_player_references (career_id TEXT PRIMARY KEY, player_id TEXT NOT NULL UNIQUE, start_date TEXT NOT NULL)');
        $connection->exec("INSERT INTO career_player_references VALUES ('career-1', 'controlled-1', '2024-07-31')");
        $connection->exec('CREATE TABLE player_records (id TEXT PRIMARY KEY, first_name TEXT NOT NULL, last_name TEXT NOT NULL, preferred_name TEXT NOT NULL, birth_date TEXT NOT NULL, birth_nation_id TEXT NOT NULL, primary_nation_id TEXT NOT NULL, height_cm INTEGER NOT NULL, weight_kg INTEGER NOT NULL, primary_position TEXT NOT NULL, pace INTEGER NOT NULL, shooting INTEGER NOT NULL, passing INTEGER NOT NULL, dribbling INTEGER NOT NULL, defending INTEGER NOT NULL, physicality INTEGER NOT NULL, potential INTEGER NOT NULL, development_profile TEXT NOT NULL, creation_seed INTEGER NOT NULL, career_state TEXT NOT NULL, preferred_foot TEXT NOT NULL, weak_foot TEXT NOT NULL)');
        $connection->exec('CREATE TABLE player_nationalities (player_id TEXT NOT NULL, nation_id TEXT NOT NULL, PRIMARY KEY (player_id, nation_id))');
        $connection->exec('CREATE TABLE player_eligibilities (player_id TEXT NOT NULL, nation_id TEXT NOT NULL, PRIMARY KEY (player_id, nation_id))');
        $connection->exec('CREATE TABLE club_squad_memberships (club_id TEXT NOT NULL, player_id TEXT NOT NULL, season_id TEXT NOT NULL, role TEXT NOT NULL, PRIMARY KEY (club_id, player_id, season_id))');
        $connection->exec('CREATE TABLE match_records (id TEXT PRIMARY KEY, season_id TEXT NOT NULL, competition_id TEXT NOT NULL, round_number INTEGER NOT NULL, scheduled_date TEXT NOT NULL, home_club_id TEXT NOT NULL, away_club_id TEXT NOT NULL, status TEXT NOT NULL, home_goals INTEGER NULL, away_goals INTEGER NULL)');
        $connection->exec('CREATE TABLE match_player_stats (match_id TEXT NOT NULL, player_id TEXT NOT NULL, club_id TEXT NOT NULL, appeared INTEGER NOT NULL, started INTEGER NOT NULL, minutes INTEGER NOT NULL, goals INTEGER NOT NULL, assists INTEGER NOT NULL, shots INTEGER NOT NULL, shots_on_target INTEGER NOT NULL, saves INTEGER NOT NULL, clean_sheets INTEGER NOT NULL, tackles INTEGER NOT NULL, interceptions INTEGER NOT NULL, blocks INTEGER NOT NULL, passes_attempted INTEGER NOT NULL, passes_completed INTEGER NOT NULL, fouls_committed INTEGER NOT NULL, yellow_cards INTEGER NOT NULL, red_cards INTEGER NOT NULL, PRIMARY KEY (match_id, player_id))');
        $connection->exec('CREATE TABLE match_player_selections (match_id TEXT NOT NULL, player_id TEXT NOT NULL, club_id TEXT NOT NULL, status TEXT NOT NULL, is_captain INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (match_id, player_id))');
        $connection->exec('CREATE TABLE match_highlights (match_id TEXT NOT NULL, sequence_number INTEGER NOT NULL, minute INTEGER NOT NULL, type TEXT NOT NULL, club_id TEXT NULL, player_id TEXT NULL, data_json TEXT NOT NULL, PRIMARY KEY (match_id, sequence_number))');
        $connection->exec('CREATE TABLE match_substitutions (match_id TEXT NOT NULL, club_id TEXT NOT NULL, sequence_number INTEGER NOT NULL, outgoing_player_id TEXT NOT NULL, incoming_player_id TEXT NOT NULL, minute INTEGER NOT NULL, reason TEXT NULL, PRIMARY KEY (match_id, club_id, sequence_number))');
        $connection->exec('CREATE TABLE career_match_evaluations (match_id TEXT NOT NULL, player_id TEXT NOT NULL, club_id TEXT NOT NULL, occurred_date TEXT NOT NULL, evaluation_score INTEGER NOT NULL, expectation_status TEXT NOT NULL, PRIMARY KEY (match_id, player_id))');
        $connection->exec('CREATE TABLE player_development_history (id TEXT PRIMARY KEY, player_id TEXT NOT NULL, occurred_date TEXT NOT NULL, source TEXT NOT NULL, source_id TEXT NOT NULL, attribute_deltas_json TEXT NOT NULL, before_ovr INTEGER NOT NULL, after_ovr INTEGER NOT NULL)');
        $connection->exec('CREATE TABLE player_availability_sources (player_id TEXT NOT NULL, source_type TEXT NOT NULL, source_id TEXT NOT NULL, fatigue_delta INTEGER NOT NULL, occurred_date TEXT NOT NULL, PRIMARY KEY (player_id, source_type, source_id))');
        $connection->exec('CREATE TABLE player_competition_statistics (player_id TEXT NOT NULL, season_id TEXT NOT NULL, competition_id TEXT NOT NULL, club_id TEXT NOT NULL, appearances INTEGER NOT NULL, starts INTEGER NOT NULL, minutes INTEGER NOT NULL, goals INTEGER NOT NULL, assists INTEGER NOT NULL, shots INTEGER NOT NULL, shots_on_target INTEGER NOT NULL, saves INTEGER NOT NULL, clean_sheets INTEGER NOT NULL, tackles INTEGER NOT NULL, interceptions INTEGER NOT NULL, blocks INTEGER NOT NULL, passes_attempted INTEGER NOT NULL, passes_completed INTEGER NOT NULL, fouls_committed INTEGER NOT NULL, yellow_cards INTEGER NOT NULL, red_cards INTEGER NOT NULL, rated_appearances INTEGER NOT NULL, rating_total REAL NOT NULL, PRIMARY KEY (player_id, season_id, competition_id, club_id))');
    }

    private function fixtures(DatabaseInterface $database, int $backgroundMatches): void
    {
        $connection = $database->connection();
        $playerInsert = $connection->prepare('INSERT INTO player_records (id, first_name, last_name, preferred_name, birth_date, birth_nation_id, primary_nation_id, height_cm, weight_kg, primary_position, pace, shooting, passing, dribbling, defending, physicality, potential, development_profile, creation_seed, career_state, preferred_foot, weak_foot) VALUES (:id, :first_name, :last_name, :preferred_name, :birth_date, :birth_nation_id, :primary_nation_id, 180, 75, :position, 70, 70, 70, 70, 70, 70, 80, \'regular\', :seed, \'active\', \'right\', \'usable\')');
        $playerIds = ['controlled-1'];
        for ($index = 1; $index <= 14; ++$index) {
            $playerIds[] = 'npc-' . $index;
        }
        foreach ($playerIds as $index => $playerId) {
            $playerInsert->execute(['id' => $playerId, 'first_name' => 'Player', 'last_name' => $playerId, 'preferred_name' => $playerId, 'birth_date' => '2000-01-01', 'birth_nation_id' => 'england', 'primary_nation_id' => 'england', 'position' => 'CM', 'seed' => 4002 + $index]);
        }
        $nationality = $connection->prepare('INSERT INTO player_nationalities (player_id, nation_id) VALUES (:player_id, \'wales\')');
        $eligibility = $connection->prepare('INSERT INTO player_eligibilities (player_id, nation_id) VALUES (:player_id, \'england\')');
        foreach ($playerIds as $playerId) {
            $nationality->execute(['player_id' => $playerId]);
            $eligibility->execute(['player_id' => $playerId]);
        }
        $connection->exec("INSERT INTO club_squad_memberships VALUES ('club-protected', 'controlled-1', 'season-2024-25', 'regular')");
        $connection->exec("INSERT INTO club_squad_memberships VALUES ('club-protected', 'npc-1', 'season-2024-25', 'regular')");

        $match = $connection->prepare('INSERT INTO match_records (id, season_id, competition_id, round_number, scheduled_date, home_club_id, away_club_id, status, home_goals, away_goals) VALUES (:id, :season, :competition, :round, :date, :home, :away, \'completed\', :home_goals, :away_goals)');
        $stat = $connection->prepare('INSERT INTO match_player_stats (match_id, player_id, club_id, appeared, started, minutes, goals, assists, shots, shots_on_target, saves, clean_sheets, tackles, interceptions, blocks, passes_attempted, passes_completed, fouls_committed, yellow_cards, red_cards) VALUES (:match_id, :player_id, :club_id, 1, :started, :minutes, :goals, 0, 2, 1, 0, 0, 1, 1, 0, 30, 24, 1, 0, 0)');
        $selection = $connection->prepare('INSERT INTO match_player_selections (match_id, player_id, club_id, status, is_captain) VALUES (:match_id, :player_id, :club_id, :status, 0)');
        $highlight = $connection->prepare('INSERT INTO match_highlights (match_id, sequence_number, minute, type, club_id, player_id, data_json) VALUES (:match_id, :sequence, :minute, :type, :club_id, :player_id, :data)');
        $substitution = $connection->prepare('INSERT INTO match_substitutions (match_id, club_id, sequence_number, outgoing_player_id, incoming_player_id, minute, reason) VALUES (:match_id, :club_id, :sequence, :outgoing, :incoming, 60, \'TACTICAL\')');
        for ($index = 0; $index < $backgroundMatches; ++$index) {
            $matchId = 'background-match-' . $index;
            $home = 'npc-home';
            $away = 'npc-away';
            $match->execute(['id' => $matchId, 'season' => 'season-2024-25', 'competition' => 'competition-1', 'round' => $index + 2, 'date' => '2024-08-02', 'home' => $home, 'away' => $away, 'home_goals' => 2, 'away_goals' => 1]);
            for ($player = 1; $player <= 14; ++$player) {
                $playerId = 'npc-' . $player;
                $club = $player <= 7 ? $home : $away;
                $stat->execute(['match_id' => $matchId, 'player_id' => $playerId, 'club_id' => $club, 'started' => 1, 'minutes' => 90, 'goals' => $player === 1 ? 1 : 0]);
                $selection->execute(['match_id' => $matchId, 'player_id' => $playerId, 'club_id' => $club, 'status' => 'starter']);
            }
            for ($event = 1; $event <= 6; ++$event) {
                $highlight->execute(['match_id' => $matchId, 'sequence' => $event, 'minute' => 10 + $event, 'type' => $event === 1 ? 'goal' : 'yellow_card', 'club_id' => $home, 'player_id' => 'npc-1', 'data' => json_encode(['background' => str_repeat('x', 180), 'event' => $event], JSON_THROW_ON_ERROR)]);
            }
            $substitution->execute(['match_id' => $matchId, 'club_id' => $home, 'sequence' => 1, 'outgoing' => 'npc-1', 'incoming' => 'npc-2']);
            if ($index < 6) {
                for ($evaluation = 0; $evaluation < 4; ++$evaluation) {
                    $evaluationMatchId = 'background-match-' . (($index * 4) + $evaluation);
                    $connection->prepare('INSERT INTO career_match_evaluations VALUES (:match_id, :player_id, :club_id, :date, :score, \'met\')')->execute(['match_id' => $evaluationMatchId, 'player_id' => 'npc-1', 'club_id' => $home, 'date' => '2024-08-02', 'score' => 60 + $evaluation]);
                }
            }
        }

        $match->execute(['id' => 'protected-match', 'season' => 'season-2024-25', 'competition' => 'competition-1', 'round' => 1, 'date' => '2025-05-01', 'home' => 'club-protected', 'away' => 'club-rival', 'home_goals' => 1, 'away_goals' => 0]);
        $stat->execute(['match_id' => 'protected-match', 'player_id' => 'controlled-1', 'club_id' => 'club-protected', 'started' => 1, 'minutes' => 90, 'goals' => 1]);
        $stat->execute(['match_id' => 'protected-match', 'player_id' => 'npc-1', 'club_id' => 'club-protected', 'started' => 1, 'minutes' => 45, 'goals' => 0]);
        $stat->execute(['match_id' => 'protected-match', 'player_id' => 'npc-2', 'club_id' => 'club-protected', 'started' => 0, 'minutes' => 30, 'goals' => 0]);
        $stat->execute(['match_id' => 'protected-match', 'player_id' => 'npc-3', 'club_id' => 'club-protected', 'started' => 1, 'minutes' => 90, 'goals' => 0]);
        $selection->execute(['match_id' => 'protected-match', 'player_id' => 'controlled-1', 'club_id' => 'club-protected', 'status' => 'starter']);
        $selection->execute(['match_id' => 'protected-match', 'player_id' => 'npc-1', 'club_id' => 'club-protected', 'status' => 'starter']);
        $selection->execute(['match_id' => 'protected-match', 'player_id' => 'npc-2', 'club_id' => 'club-protected', 'status' => 'bench']);
        $selection->execute(['match_id' => 'protected-match', 'player_id' => 'npc-3', 'club_id' => 'club-protected', 'status' => 'starter']);
        $highlight->execute(['match_id' => 'protected-match', 'sequence' => 1, 'minute' => 20, 'type' => 'goal', 'club_id' => 'club-protected', 'player_id' => 'controlled-1', 'data' => '{"source":"controlled"}']);
        $highlight->execute(['match_id' => 'protected-match', 'sequence' => 2, 'minute' => 60, 'type' => 'substitution', 'club_id' => 'club-protected', 'player_id' => 'npc-2', 'data' => '{"incoming_player_id":"npc-2","outgoing_player_id":"npc-1","reason":"TACTICAL"}']);
        $substitution->execute(['match_id' => 'protected-match', 'club_id' => 'club-protected', 'sequence' => 1, 'outgoing' => 'npc-1', 'incoming' => 'npc-2']);

        $connection->prepare('INSERT INTO player_development_history VALUES (:id, :player_id, :date, \'training\', :source, \'{}\', 50, 51)')->execute(['id' => 'dev-controlled', 'player_id' => 'controlled-1', 'date' => '2024-08-01', 'source' => 'controlled-training']);
        for ($index = 1; $index <= 14; ++$index) {
            $connection->prepare('INSERT INTO player_development_history VALUES (:id, :player_id, :date, \'training\', :source, \'{}\', 50, 51)')->execute(['id' => 'dev-npc-' . $index, 'player_id' => 'npc-' . $index, 'date' => '2024-08-01', 'source' => 'npc-training-' . $index]);
            $connection->prepare('INSERT INTO player_availability_sources VALUES (:player_id, \'match\', :source, 10, \'2024-08-01\')')->execute(['player_id' => 'npc-' . $index, 'source' => 'background-source-' . $index]);
        }
        $connection->exec("INSERT INTO player_availability_sources VALUES ('controlled-1', 'training', 'controlled-source', 1, '2024-08-01')");
        $competitionAggregate = $connection->prepare('INSERT INTO player_competition_statistics (player_id, season_id, competition_id, club_id, appearances, starts, minutes, goals, assists, shots, shots_on_target, saves, clean_sheets, tackles, interceptions, blocks, passes_attempted, passes_completed, fouls_committed, yellow_cards, red_cards, rated_appearances, rating_total) VALUES (:player_id, \'season-2024-25\', \'competition-1\', :club_id, :appearances, :starts, :minutes, :goals, 0, :appearances_shots, :appearances_shots_on_target, 0, 0, :appearances_tackles, :appearances_interceptions, 0, :appearances_passes, :appearances_completed_passes, :appearances_fouls, 0, 0, :appearances, :rating_total)');
        for ($index = 1; $index <= 14; ++$index) {
            $competitionAggregate->execute([
                'player_id' => 'npc-' . $index,
                'club_id' => $index <= 7 ? 'npc-home' : 'npc-away',
                'appearances' => $backgroundMatches,
                'starts' => $backgroundMatches,
                'minutes' => $backgroundMatches * 90,
                'goals' => $index === 1 ? $backgroundMatches : 0,
                'appearances_shots' => $backgroundMatches * 2,
                'appearances_shots_on_target' => $backgroundMatches,
                'appearances_tackles' => $backgroundMatches,
                'appearances_interceptions' => $backgroundMatches,
                'appearances_passes' => $backgroundMatches * 30,
                'appearances_completed_passes' => $backgroundMatches * 24,
                'appearances_fouls' => $backgroundMatches,
                'rating_total' => $backgroundMatches * 6.8,
            ]);
        }
        foreach ([['npc-1', 1, 0, 45], ['npc-2', 0, 0, 30], ['npc-3', 1, 0, 90]] as [$playerId, $starts, $goals, $minutes]) {
            $competitionAggregate->execute([
                'player_id' => $playerId,
                'club_id' => 'club-protected',
                'appearances' => 1,
                'starts' => $starts,
                'minutes' => $minutes,
                'goals' => $goals,
                'appearances_shots' => 2,
                'appearances_shots_on_target' => 1,
                'appearances_tackles' => 1,
                'appearances_interceptions' => 1,
                'appearances_passes' => 30,
                'appearances_completed_passes' => 24,
                'appearances_fouls' => 1,
                'rating_total' => 6.8,
            ]);
        }
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } elseif (is_file($path) || is_link($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
