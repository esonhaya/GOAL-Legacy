<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Performance;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\OwnedArtifactCleanup;
use Goal\Legacy\Core\Persistence\SaveStore;
use Goal\Legacy\Core\Persistence\SqlProfiler;
use Goal\Legacy\Core\Persistence\SqliteStorageAttribution;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Devtools\Presentation\CareerPresentationService;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Web\WebApplication;
use PDO;
use RuntimeException;

/**
 * Bounded, read-mostly measurements for the player-facing Career paths.
 *
 * This class observes canonical state and invokes the normal WebApplication
 * routes. It never calculates gameplay outcomes and never writes the source
 * save; mutating probes use an owned clone that is deleted in finally.
 */
final class CareerPerformanceProbe
{
    public function __construct(
        private readonly CoreServices $services,
        private readonly string $projectRoot,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function capture(
        DatabaseInterface $database,
        SaveStore $store,
        string $saveId,
        string $savePath,
        string $playerId,
        int $seasonsCompleted,
        array $compaction = [],
    ): array {
        $started = hrtime(true);
        $summary = $this->summary($database, $saveId);
        $snapshot = $this->saveSnapshot($database, $savePath);
        $player = (new PlayerRepository($database))->get($playerId);
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->load($database, $saveId);
        $currentDate = $world->currentDate($worldService->calendar());
        $reads = $this->readPaths($database, $store, $saveId, $playerId, $summary, $savePath);

        $actions = [];
        if ($seasonsCompleted === 0) {
            $actions['training'] = $this->measureAction($store, $saveId, $savePath, 'training', $playerId);
            $actions['match'] = $this->measureAction($store, $saveId, $savePath, 'match', $playerId);
        }

        $club = is_array($summary['current_club'] ?? null) ? $summary['current_club'] : [];

        return [
            'career_age' => $seasonsCompleted === 0 ? 0 : $seasonsCompleted,
            'player_age' => $player->ageAt($currentDate),
            'season' => $world->currentSeasonId()?->value(),
            'date' => $currentDate->toIsoString(),
            'seasons_completed' => $seasonsCompleted,
            'current_club' => $club['name'] ?? 'Free Agent',
            'current_role' => $summary['current_role'] ?? null,
            'save' => $snapshot,
            'compaction' => $compaction,
            'read_paths' => $reads,
            'actions' => $actions,
            'capture_time_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
        ];
    }

    /** @return array<string,mixed> */
    private function summary(DatabaseInterface $database, string $saveId): array
    {
        return (array) ((new CareerPresentationService($this->services))->snapshot($database, $saveId, false)['summary'] ?? []);
    }

    /** @param array<string,mixed> $summary @return array<string,mixed> */
    private function readPaths(
        DatabaseInterface $database,
        SaveStore $store,
        string $saveId,
        string $playerId,
        array $summary,
        string $savePath,
    ): array {
        $presentation = new CareerPresentationService($this->services);
        $routes = [
            'CAREER_HOME' => ['page' => 'home', 'save' => $saveId],
            'PLAYER_PROFILE' => ['page' => 'profile', 'save' => $saveId, 'player' => $playerId],
            'TRAINING' => ['page' => 'training', 'save' => $saveId],
            'CAREER_HISTORY' => ['page' => 'career', 'save' => $saveId],
            'TROPHY_ROOM' => ['page' => 'trophies', 'save' => $saveId],
        ];

        $next = $presentation->nextMatch($database, $summary);
        if (is_array($next) && is_string($next['match_id'] ?? null) && $next['match_id'] !== '') {
            $routes['MATCHDAY'] = ['page' => 'matchday', 'save' => $saveId, 'match' => $next['match_id']];
        }
        $competition = is_array($summary['current_competition'] ?? null) ? $summary['current_competition'] : [];
        $seasonId = (string) ($summary['current_season_id'] ?? '');
        if (($competition['id'] ?? '') !== '' && $seasonId !== '') {
            $routes['COMPETITION'] = ['page' => 'competition', 'save' => $saveId, 'competition' => $competition['id']];
        }

        $measurements = [];
        foreach ($routes as $label => $query) {
            $measurements[$label] = $this->measureGet($store, $saveId, $query, $savePath);
        }

        return $measurements;
    }

    /** @param array<string,mixed> $query @return array<string,mixed> */
    private function measureGet(SaveStore $store, string $saveId, array $query, string $savePath): array
    {
        $profiler = new SqlProfiler();
        $application = new WebApplication($this->services, $this->projectRoot, $store, $profiler);
        $session = [];
        $application->handle('GET', '/', $query, [], $session); // warmup only
        $profiler->reset();
        $observer = $this->observer($savePath);
        $times = [];
        $queries = [];
        $dml = [];
        $dataVersionDeltas = [];
        $status = 0;
        $bytes = 0;
        for ($index = 0; $index < 2; ++$index) {
            $beforeVersion = (int) $observer->query('PRAGMA data_version')->fetchColumn();
            $profiler->reset();
            $started = hrtime(true);
            $session = [];
            $response = $application->handle('GET', '/', $query, [], $session);
            $times[] = round((hrtime(true) - $started) / 1_000_000, 2);
            $status = (int) ($response['status'] ?? 0);
            $bytes = strlen((string) ($response['body'] ?? ''));
            $afterVersion = (int) $observer->query('PRAGMA data_version')->fetchColumn();
            $snapshot = $profiler->snapshot();
            $queries[] = (int) ($snapshot['total_sql_calls'] ?? 0);
            $dml[] = $this->dmlCalls($snapshot);
            $dataVersionDeltas[] = $afterVersion - $beforeVersion;
        }

        return [
            'status' => $status,
            'time_ms' => round(array_sum($times) / count($times), 2),
            'min_ms' => min($times),
            'max_ms' => max($times),
            'samples_ms' => $times,
            'query_count' => $queries,
            'dml_count' => $dml,
            'data_version_delta' => $dataVersionDeltas,
            'response_bytes' => $bytes,
            'profile_top_by_total_ms' => $this->profileSummary($profiler->profilesBy('total_ms', 5)),
            'profile_top_by_calls' => $this->profileSummary($profiler->profilesBy('calls', 5)),
            'status_classification' => $status === 200 && max($dml) === 0 && max($dataVersionDeltas) === 0 ? 'PASS' : 'CHECK',
        ];
    }

    /** @param list<array<string,mixed>> $profiles @return list<array<string,mixed>> */
    private function profileSummary(array $profiles): array
    {
        return array_map(static fn (array $profile): array => [
            'fingerprint' => $profile['fingerprint'] ?? '',
            'calls' => (int) ($profile['calls'] ?? 0),
            'total_ms' => round((float) ($profile['total_ms'] ?? 0.0), 3),
            'average_ms' => round((float) ($profile['average_ms'] ?? 0.0), 3),
            'full_scan' => (bool) ($profile['full_scan'] ?? false),
            'temp_btree' => (bool) ($profile['temp_btree'] ?? false),
            'index_used' => (bool) ($profile['index_used'] ?? false),
        ], $profiles);
    }

    /** @return array<string,mixed> */
    private function measureAction(SaveStore $store, string $sourceId, string $sourcePath, string $kind, string $playerId): array
    {
        $directory = sys_get_temp_dir() . '/goal-legacy-p3020-' . $kind . '-' . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create P3-020 action probe directory.');
        }
        $clonePath = $directory . DIRECTORY_SEPARATOR . $sourceId . '.sqlite';
        if (!copy($sourcePath, $clonePath)) {
            throw new RuntimeException('Unable to create the owned P3-020 action snapshot.');
        }
        $actionStore = new SqliteSaveStore($directory, new JsonSerializer());
        try {
            $actionServices = (new Bootstrap())->create($this->projectRoot, ['APP_ENV' => 'test']);
            // Match advancement delegates into the full canonical world/match
            // path. Profiling every statement there changes its wall time by
            // an order of magnitude, so retain an honest unprofiled action
            // timing and use data_version for its write proof. Training is
            // small enough to collect statement counts directly.
            $profiler = $kind === 'match' ? null : new SqlProfiler();
            $application = new WebApplication($actionServices, $this->projectRoot, $actionStore, $profiler);
            $session = [];
            $query = ['page' => $kind === 'match' ? 'matchday' : 'training', 'save' => $sourceId];
            if ($kind === 'match') {
                $cloneDatabase = $actionStore->openDatabase($sourceId);
                $summary = (array) ((new CareerPresentationService($actionServices))->snapshot($cloneDatabase, $sourceId, false)['summary'] ?? []);
                $next = (new CareerPresentationService($actionServices))->nextMatch($cloneDatabase, $summary);
                if (!is_array($next) || !is_string($next['match_id'] ?? null)) {
                    return ['status' => 'NOT_AVAILABLE', 'reason' => 'no scheduled controlled-player fixture'];
                }
                $query['match'] = $next['match_id'];
            }
            $form = $application->handle('GET', '/', $query, [], $session);
            $token = $this->tokenFor($form, $kind === 'match' ? 'advance_match' : 'set_training');
            if ($token === null) {
                throw new RuntimeException('P3-020 action probe could not find its canonical one-use token.');
            }
            $payload = $kind === 'match'
                ? ['action' => 'advance_match', 'save' => $sourceId, 'match' => $query['match'], 'token' => $token]
                : ['action' => 'set_training', 'save' => $sourceId, 'focus' => 'passing', 'token' => $token];
            $observer = $this->observer($clonePath);
            $beforeVersion = (int) $observer->query('PRAGMA data_version')->fetchColumn();
            $profiler?->reset();
            $started = hrtime(true);
            $response = $application->handle('POST', '/', [], $payload, $session);
            $elapsed = round((hrtime(true) - $started) / 1_000_000, 2);
            $afterVersion = (int) $observer->query('PRAGMA data_version')->fetchColumn();
            $profile = $profiler?->snapshot();
            $writes = $profile === null ? null : $this->dmlCalls($profile);

            return [
                'status' => (int) ($response['status'] ?? 0),
                'time_ms' => $elapsed,
                'query_count' => $profile === null ? 'NOT_MEASURED' : (int) ($profile['total_sql_calls'] ?? 0),
                'write_count' => $writes ?? 'CONFIRMED_BY_DATA_VERSION',
                'data_version_delta' => $afterVersion - $beforeVersion,
                'classification' => ($response['status'] ?? 0) === 303 && $afterVersion > $beforeVersion ? 'PASS' : 'CHECK',
            ];
        } finally {
            if ($actionStore->exists($sourceId)) {
                $actionStore->delete($sourceId);
            }
            OwnedArtifactCleanup::removeOwnedDirectory($directory, 'goal-legacy-p3020-');
        }
    }

    /** @return array<string,mixed> */
    private function saveSnapshot(DatabaseInterface $database, string $savePath): array
    {
        $connection = $database->connection();
        $attribution = (new SqliteStorageAttribution())->inspectConnection($connection, $savePath);
        $tables = $connection->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $rows = [];
        foreach ($tables as $table) {
            $name = (string) $table;
            $quoted = '"' . str_replace('"', '""', $name) . '"';
            $rows[$name] = (int) $connection->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();
        }
        arsort($rows);
        $relevantRows = 0;
        foreach ($rows as $name => $count) {
            if (preg_match('/career|match|stat|event|transfer|loan|pulse|finance|development|injur|discipline|award|record|season/i', $name) === 1) {
                $relevantRows += $count;
            }
        }
        $duplicateGroups = $this->duplicatePrimaryKeyGroups($connection, array_keys($rows));
        $foreignKeyViolations = (int) $connection->query('SELECT COUNT(*) FROM pragma_foreign_key_check')->fetchColumn();

        return [
            'bytes' => (int) (filesize($savePath) ?: 0),
            'page_count' => $attribution['page_count'],
            'page_size' => $attribution['page_size'],
            'freelist_pages' => $attribution['freelist_count'],
            'used_approx_bytes' => $attribution['used_approx_bytes'],
            'free_approx_bytes' => $attribution['free_approx_bytes'],
            'table_count' => count($rows),
            'total_rows' => array_sum($rows),
            'relevant_history_rows' => $relevantRows,
            'largest_tables' => array_slice($rows, 0, 12, true),
            'largest_tables_by_bytes' => array_slice($attribution['tables'], 0, 12),
            'largest_indexes_by_bytes' => array_slice($attribution['indexes'], 0, 10),
            'rows' => $rows,
            'storage_attribution' => $attribution,
            'duplicate_primary_key_groups' => $duplicateGroups,
            'foreign_key_violations' => $attribution['foreign_key_violations'] ?? $foreignKeyViolations,
            'integrity' => $attribution['integrity_check'] ?? (string) $connection->query('PRAGMA integrity_check')->fetchColumn(),
        ];
    }

    /** @param list<string> $tables */
    private function duplicatePrimaryKeyGroups(PDO $connection, array $tables): int
    {
        $groups = 0;
        foreach ($tables as $table) {
            $quoted = '"' . str_replace('"', '""', $table) . '"';
            $columns = $connection->query('PRAGMA table_info(' . $quoted . ')')->fetchAll(PDO::FETCH_ASSOC);
            usort($columns, static fn (array $left, array $right): int => ((int) $left['pk']) <=> ((int) $right['pk']));
            $primary = array_values(array_filter($columns, static fn (array $column): bool => (int) ($column['pk'] ?? 0) > 0));
            if ($primary === []) {
                continue;
            }
            $groupBy = implode(', ', array_map(static fn (array $column): string => '"' . str_replace('"', '""', (string) $column['name']) . '"', $primary));
            $groups += (int) $connection->query('SELECT COUNT(*) FROM (SELECT ' . $groupBy . ' FROM ' . $quoted . ' GROUP BY ' . $groupBy . ' HAVING COUNT(*) > 1)')->fetchColumn();
        }

        return $groups;
    }

    /** @param array<string,mixed> $snapshot */
    private function dmlCalls(array $snapshot): int
    {
        $calls = 0;
        foreach ((array) ($snapshot['queries'] ?? []) as $profile) {
            if (preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\b/i', (string) ($profile['fingerprint'] ?? '')) === 1) {
                $calls += (int) ($profile['calls'] ?? 0);
            }
        }

        return $calls;
    }

    private function observer(string $path): PDO
    {
        return new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** @param array<string,mixed> $response */
    private function tokenFor(array $response, string $action): ?string
    {
        $pattern = '/<input type="hidden" name="action" value="' . preg_quote($action, '/') . '">.*?<input type="hidden" name="token" value="([^"]+)"/s';
        if (preg_match($pattern, (string) ($response['body'] ?? ''), $matches) !== 1) {
            return null;
        }

        $token = html_entity_decode((string) ($matches[1] ?? ''), ENT_QUOTES | ENT_HTML5);

        return $token === '' ? null : $token;
    }
}
