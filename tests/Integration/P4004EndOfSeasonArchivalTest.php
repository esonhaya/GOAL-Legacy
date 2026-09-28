<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqlProfiler;
use Goal\Legacy\Core\Persistence\SqliteStorageAttribution;
use Goal\Legacy\Devtools\Presentation\CareerPresentationService;
use Goal\Legacy\Devtools\Simulation\GoalScenarioBuilder;
use Goal\Legacy\Devtools\Simulation\GoalScenarioFixture;
use Goal\Legacy\Modules\Match\MatchSelectionService;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\World\SeasonArchiveService;
use Goal\Legacy\Modules\World\SeasonCompactionService;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use PDO;
use PHPUnit\Framework\TestCase;

final class P4004EndOfSeasonArchivalTest extends TestCase
{
    public function testCompletedSeasonArchivePreservesCareerAndSuccessorGameplay(): void
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $fixture = null;
        try {
            $fixture = (new GoalScenarioBuilder($services))->buildFinalizedSeasonWithActiveSuccessor(4004);
            $database = $fixture->database();
            $worldService = $services->worldModule()->service();
            $oldSeason = $worldService->seasonRepository($database)->get($fixture->seasonId());
            $activeSeason = $worldService->seasonRollover()?->nextSeason($oldSeason);
            self::assertNotNull($activeSeason);
            self::assertSame('completed', $oldSeason->status()->value);
            self::assertSame('active', $worldService->seasonRepository($database)->get($activeSeason->id())->status()->value);

            $savePath = $fixture->store()->storageDirectory() . '/' . $fixture->saveId() . '.sqlite';
            $attribution = new SqliteStorageAttribution();
            $beforeStorage = $attribution->inspect($savePath);
            $beforeCheckpoint = $this->checkpoint($database, $fixture->playerId()->value(), $activeSeason->id()->value());
            $beforeDate = $worldService->load($database, $fixture->saveId())->currentDate($worldService->calendar())->toIsoString();
            $beforeStandings = $services->matchModule()->service()->standings($database, 'premier-league', $oldSeason->id());
            $historicalMatch = (new MatchRepository($database))->byCompetition('premier-league', $oldSeason->id())[0] ?? null;
            self::assertNotNull($historicalMatch);

            $currentMembership = $services->clubModule()->service()->squadRepository($database)->byPlayer($fixture->playerId(), $activeSeason->id())[0] ?? null;
            self::assertNotNull($currentMembership);
            $nextMatch = array_values(array_filter(
                (new MatchRepository($database))->byClub($currentMembership->clubId(), $activeSeason->id()),
                static fn ($match): bool => $match->status() === MatchStatus::Scheduled,
            ))[0] ?? null;
            self::assertNotNull($nextMatch);
            $selectionService = new MatchSelectionService($services->clubModule()->service());
            $players = new PlayerRepository($database);
            $eligibleBefore = $this->playerIds($selectionService->eligiblePlayers($database, $nextMatch, $currentMembership->clubId()->value(), $players));

            // Keep a byte-identical, owned comparison save for the bounded
            // deterministic next-Match proof and the rollback proof.
            $cloneId = 'p4004-uncompacted';
            $sourceMetadata = $fixture->store()->open($fixture->saveId());
            $cloneMetadata = SaveMetadata::create($cloneId, 'P4-004 unarchived comparison', $sourceMetadata->simulationTime(), new DateTimeImmutable(), $sourceMetadata->ownerId())->asSandbox($fixture->saveId());
            $fixture->store()->cloneSave($fixture->saveId(), $cloneMetadata);

            $rollback = $fixture->store()->openDatabase($cloneId);
            (new SeasonCompactionService())->compact($rollback, $oldSeason->id(), $activeSeason->startDate()->toIsoString());
            $rollback->connection()->exec('CREATE TABLE IF NOT EXISTS save_archival_seasons (season_id TEXT PRIMARY KEY, active_season_id TEXT NOT NULL, archived_at TEXT NOT NULL, registration_rows INTEGER NOT NULL, membership_rows INTEGER NOT NULL, role_history_rows INTEGER NOT NULL, contract_rows INTEGER NOT NULL)');
            $rollback->connection()->exec("CREATE TRIGGER p4004_archive_failure BEFORE INSERT ON save_archival_seasons BEGIN SELECT RAISE(ABORT, 'p4-004 rollback proof'); END");
            $rollbackCounts = $this->candidateCounts($rollback->connection(), $oldSeason->id());
            $rollbackFailed = false;
            try {
                (new SeasonArchiveService())->archive($rollback, $oldSeason->id(), $activeSeason->id(), $activeSeason->startDate()->toIsoString());
            } catch (\Throwable) {
                $rollbackFailed = true;
            }
            self::assertTrue($rollbackFailed, 'The injected archive failure must be raised.');
            self::assertSame($rollbackCounts, $this->candidateCounts($rollback->connection(), $oldSeason->id()));
            self::assertSame(0, (int) $rollback->connection()->query('SELECT COUNT(*) FROM save_archival_seasons')->fetchColumn());
            unset($rollback);

            $archiveStarted = hrtime(true);
            $archive = (new SeasonArchiveService())->archive($database, $oldSeason->id(), $activeSeason->id(), $activeSeason->startDate()->toIsoString());
            $archive['duration_ms'] = round((hrtime(true) - $archiveStarted) / 1_000_000, 2);
            self::assertTrue((bool) $archive['archived']);
            self::assertGreaterThan(0, (int) $archive['logical_rows_removed']);
            self::assertGreaterThan(0, (int) $archive['registrations']);
            self::assertGreaterThan(0, (int) $archive['role_history']);
            self::assertSame($beforeCheckpoint, $this->checkpoint($database, $fixture->playerId()->value(), $activeSeason->id()->value()));
            self::assertSame($beforeDate, $worldService->load($database, $fixture->saveId())->currentDate($worldService->calendar())->toIsoString());
            self::assertSame($beforeStandings, $services->matchModule()->service()->standings($database, 'premier-league', $oldSeason->id()));
            self::assertTrue((new MatchRepository($database))->exists($historicalMatch->id()));

            $npcSeasonAggregate = $database->connection()->query("SELECT COUNT(*) FROM player_season_statistics WHERE season_id = '" . $oldSeason->id()->value() . "' AND player_id NOT IN (SELECT player_id FROM career_player_references)")->fetchColumn();
            self::assertGreaterThan(0, (int) $npcSeasonAggregate);
            $afterLogicalStorage = $attribution->inspect($savePath);
            $beforeCandidateBytes = $this->candidateBytes($beforeStorage);
            $afterCandidateBytes = $this->candidateBytes($afterLogicalStorage);
            self::assertLessThan($beforeCandidateBytes, $afterCandidateBytes);

            $second = (new SeasonArchiveService())->archive($database, $oldSeason->id(), $activeSeason->id(), $activeSeason->startDate()->toIsoString());
            self::assertFalse((bool) $second['archived']);
            self::assertTrue((bool) $second['idempotent']);
            self::assertSame(0, (int) $second['logical_rows_removed']);
            self::assertSame($beforeStandings, $services->matchModule()->service()->standings($database, 'premier-league', $oldSeason->id()));

            $semanticBeforeVacuum = $this->checkpoint($database, $fixture->playerId()->value(), $activeSeason->id()->value());
            $fixture->release();
            $physicalStarted = hrtime(true);
            $physical = $fixture->store()->compact($fixture->saveId(), fn (DatabaseInterface $reopened): array => $this->checkpoint($reopened, $fixture->playerId()->value(), $activeSeason->id()->value()));
            $physical['duration_ms'] = round((hrtime(true) - $physicalStarted) / 1_000_000, 2);
            self::assertGreaterThan(0, (int) $physical['bytes_reclaimed']);
            self::assertSame('ok', $physical['after']['integrity_check']);
            self::assertSame(0, (int) $physical['after']['foreign_key_violations']);
            $fixture->reload();
            $database = $fixture->database();
            self::assertSame($semanticBeforeVacuum, $this->checkpoint($database, $fixture->playerId()->value(), $activeSeason->id()->value()));

            $afterArchiveSummary = (new PlayerCareerProgressionQuery($services->clubModule()->service()))->summary(
                $database,
                $fixture->playerId(),
                $activeSeason->startDate(),
                $activeSeason->id(),
            );
            self::assertSame($beforeCheckpoint['player'], $this->checkpoint($database, $fixture->playerId()->value(), $activeSeason->id()->value())['player']);
            self::assertSame($eligibleBefore, $this->playerIds($selectionService->eligiblePlayers($database, $nextMatch, $currentMembership->clubId()->value(), new PlayerRepository($database))));
            self::assertSame($fixture->playerId()->value(), (string) ($afterArchiveSummary['player_id'] ?? $fixture->playerId()->value()));

            // The source and the untouched owned comparison save share the
            // same successor state and RNG inputs. A bounded real Match must
            // therefore produce the same semantic result after archival.
            $sourceResult = $this->simulateNextMatch($services, $database, $nextMatch, $activeSeason->startDate());
            $comparison = $fixture->store()->openDatabase($cloneId);
            $comparisonResult = $this->simulateNextMatch($services, $comparison, $nextMatch, $activeSeason->startDate());
            self::assertSame($comparisonResult, $sourceResult);
            unset($comparison);

            $fixture->release();
            $profiler = new SqlProfiler();
            $readDatabase = $fixture->store()->openDatabase($fixture->saveId(), $profiler);
            $presentation = new CareerPresentationService($services);
            $readSnapshot = $presentation->snapshot($readDatabase, $fixture->saveId(), false);
            $presentation->careerHome($readSnapshot['summary'], $readSnapshot['date']);
            $presentation->seasonReview($readDatabase, $readSnapshot['summary'], $oldSeason->id()->value());
            $presentation->trophyRoomSummary($readDatabase, $fixture->saveId());
            self::assertSame(0, $this->dmlCalls($profiler));
            unset($readDatabase);

            fwrite(STDOUT, 'P4004_ARCHIVE ' . json_encode([
                'before_bytes' => $beforeStorage['file_size_bytes'],
                'after_logical_bytes' => $afterLogicalStorage['file_size_bytes'],
                'after_physical_bytes' => $physical['after']['file_size_bytes'],
                'archive' => [
                    'registrations' => $archive['registrations'],
                    'memberships' => $archive['memberships'],
                    'role_history' => $archive['role_history'],
                    'contracts' => $archive['contracts'],
                    'logical_rows_removed' => $archive['logical_rows_removed'],
                    'family_metrics_before' => $archive['family_metrics_before'],
                    'family_metrics_after' => $archive['family_metrics_after'],
                    'historical_compaction' => [
                        'logical_rows_removed' => $archive['historical_compaction']['logical_rows_removed'] ?? 0,
                        'family_metrics_before' => $archive['historical_compaction']['family_metrics_before'] ?? [],
                        'family_metrics_after' => $archive['historical_compaction']['family_metrics_after'] ?? [],
                    ],
                    'duration_ms' => $archive['duration_ms'],
                ],
                'physical' => [
                    'bytes_reclaimed' => $physical['bytes_reclaimed'],
                    'duration_ms' => $physical['duration_ms'],
                    'before' => [
                        'file_size_bytes' => $physical['before']['file_size_bytes'],
                        'page_count' => $physical['before']['page_count'],
                        'freelist_count' => $physical['before']['freelist_count'],
                    ],
                    'after' => [
                        'file_size_bytes' => $physical['after']['file_size_bytes'],
                        'page_count' => $physical['after']['page_count'],
                        'freelist_count' => $physical['after']['freelist_count'],
                    ],
                ],
                'candidate_bytes_before' => $beforeCandidateBytes,
                'candidate_bytes_after' => $afterCandidateBytes,
            ], JSON_THROW_ON_ERROR) . PHP_EOL);
        } finally {
            $fixture?->close();
        }
    }

    /** @return array<string, mixed> */
    private function checkpoint(DatabaseInterface $database, string $playerId, string $activeSeasonId): array
    {
        $connection = $database->connection();
        $row = static function (string $sql, array $parameters = []) use ($connection): array {
            $statement = $connection->prepare($sql);
            $statement->execute($parameters);
            $value = $statement->fetch(PDO::FETCH_ASSOC);

            return is_array($value) ? $value : [];
        };
        $count = static function (string $sql, array $parameters = []) use ($connection): int {
            $statement = $connection->prepare($sql);
            $statement->execute($parameters);

            return (int) $statement->fetchColumn();
        };

        return [
            'player' => $row('SELECT * FROM player_records WHERE id = :id', ['id' => $playerId]),
            'active_membership' => $row('SELECT * FROM club_squad_memberships WHERE player_id = :player_id AND season_id = :season_id ORDER BY club_id ASC LIMIT 1', ['player_id' => $playerId, 'season_id' => $activeSeasonId]),
            'active_contract' => $row("SELECT * FROM contract_records WHERE player_id = :player_id AND status = 'active' ORDER BY start_date DESC, id ASC LIMIT 1", ['player_id' => $playerId]),
            'world' => $row('SELECT current_season_id FROM world_records LIMIT 1'),
            'season' => $row('SELECT * FROM season_records WHERE id = :id', ['id' => $activeSeasonId]),
            'career_stats' => $count('SELECT COUNT(*) FROM match_player_stats WHERE player_id = :player_id', ['player_id' => $playerId]),
            'role_history' => $count('SELECT COUNT(*) FROM club_squad_role_history WHERE player_id = :player_id', ['player_id' => $playerId]),
            'development_history' => $count('SELECT COUNT(*) FROM player_development_history WHERE player_id = :player_id', ['player_id' => $playerId]),
            'contracts' => $count('SELECT COUNT(*) FROM contract_records WHERE player_id = :player_id', ['player_id' => $playerId]),
            'transfers' => $this->optionalCount($connection, 'transfer_records', 'SELECT COUNT(*) FROM transfer_records WHERE player_id = :player_id', ['player_id' => $playerId]),
            'loans' => $this->optionalCount($connection, 'player_loans', 'SELECT COUNT(*) FROM player_loans WHERE player_id = :player_id', ['player_id' => $playerId]),
        ];
    }

    private function optionalCount(PDO $connection, string $table, string $sql, array $parameters): int
    {
        $exists = $connection->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table");
        $exists->execute(['table' => $table]);
        if ($exists->fetchColumn() === false) {
            return 0;
        }
        $statement = $connection->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, int> */
    private function candidateCounts(PDO $connection, SeasonId $season): array
    {
        $counts = [];
        foreach (['player_competition_registrations' => 'registrations', 'club_squad_memberships' => 'memberships', 'club_squad_role_history' => 'role_history'] as $table => $key) {
            $statement = $connection->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE season_id = :season_id');
            $statement->execute(['season_id' => $season->value()]);
            $counts[$key] = (int) $statement->fetchColumn();
        }
        $counts['contracts'] = (int) $connection->query("SELECT COUNT(*) FROM contract_records WHERE status IN ('expired', 'terminated')")->fetchColumn();

        return $counts;
    }

    /** @return list<string> */
    private function playerIds(array $players): array
    {
        $ids = array_map(static fn ($player): string => $player->id()->value(), $players);
        sort($ids, SORT_STRING);

        return $ids;
    }

    /** @return array<string, mixed> */
    private function simulateNextMatch(object $services, DatabaseInterface $database, object $match, object $date): array
    {
        $worldId = (string) $database->connection()->query('SELECT id FROM world_records LIMIT 1')->fetchColumn();
        $services->worldModule()->service()->advanceToDate($database, $worldId, $match->scheduledDate());
        $completed = $services->matchModule()->service()->simulateDue($database, $match->scheduledDate());
        foreach ($completed as $candidate) {
            if ($candidate->id()->value() === $match->id()->value()) {
                return $candidate->toArray();
            }
        }

        self::fail('The canonical successor Match was not completed.');
    }

    private function dmlCalls(SqlProfiler $profiler): int
    {
        $calls = 0;
        foreach ($profiler->snapshot()['queries'] as $query) {
            if (preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\b/i', trim((string) ($query['fingerprint'] ?? ''))) === 1) {
                $calls += (int) ($query['calls'] ?? 0);
            }
        }

        return $calls;
    }

    /** @param array<string,mixed> $storage */
    private function candidateBytes(array $storage): int
    {
        $families = ['player_competition_registrations', 'club_squad_memberships', 'club_squad_role_history', 'contract_records'];
        $bytes = 0;
        foreach ($storage['tables'] as $table) {
            if (in_array($table['table_name'], $families, true)) {
                $bytes += (int) ($table['approx_storage_bytes'] ?? 0) + (int) ($table['index_storage_bytes'] ?? 0);
            }
        }

        return $bytes;
    }
}
