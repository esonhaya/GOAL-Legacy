<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Persistence;

use PDO;
use Throwable;

/** SQLite-native size and retention evidence for an explicit diagnostic call. */
final class SqliteStorageAttribution
{
    /** @var array<string, array{growth:string,consumers:list<string>,retention:string}> */
    private const RETENTION_MAP = [
        'core_save_metadata' => ['growth' => 'STATIC', 'consumers' => ['SaveStore open/list/delete/compact'], 'retention' => 'ACTIVE_REQUIRED'],
        'career_player_references' => ['growth' => 'STATIC', 'consumers' => ['CareerPlayerRepository', 'Career Home', 'Profile'], 'retention' => 'ACTIVE_REQUIRED'],
        'player_records' => ['growth' => 'WORLD_LINEAR', 'consumers' => ['PlayerRepository', 'Profile', 'Club and Match services'], 'retention' => 'ACTIVE_REQUIRED'],
        'contract_records' => ['growth' => 'MOVEMENT_LINEAR', 'consumers' => ['Contract state', 'Transfer/loan logic', 'Career History'], 'retention' => 'PLAYER_HISTORY_REQUIRED'],
        'career_opportunities' => ['growth' => 'EVENT_LINEAR', 'consumers' => ['Career decisions and Transfer Market offers'], 'retention' => 'EPHEMERAL'],
        'club_squad_memberships' => ['growth' => 'SEASON_LINEAR', 'consumers' => ['Club squad queries', 'Career History', 'Movement and selection'], 'retention' => 'PLAYER_HISTORY_REQUIRED'],
        'club_squad_role_history' => ['growth' => 'SEASON_LINEAR', 'consumers' => ['Career progression/profile role history'], 'retention' => 'PLAYER_HISTORY_REQUIRED'],
        'player_competition_registrations' => ['growth' => 'SEASON_LINEAR', 'consumers' => ['Competition eligibility and transfer logic'], 'retention' => 'ACTIVE_REQUIRED'],
        'match_records' => ['growth' => 'MATCH_LINEAR', 'consumers' => ['Match load', 'Season history', 'Career History'], 'retention' => 'WORLD_HISTORY_REQUIRED'],
        'match_player_stats' => ['growth' => 'MATCH_LINEAR', 'consumers' => ['Match Story', 'Career History', 'Profile', 'Trophy/records aggregation'], 'retention' => 'PLAYER_HISTORY_REQUIRED'],
        'match_player_selections' => ['growth' => 'MATCH_LINEAR', 'consumers' => ['Selection/readiness and Match audit'], 'retention' => 'WORLD_HISTORY_REQUIRED'],
        'match_highlights' => ['growth' => 'MATCH_LINEAR', 'consumers' => ['Match Story and saved Match presentation'], 'retention' => 'WORLD_HISTORY_REQUIRED'],
        'match_substitutions' => ['growth' => 'MATCH_LINEAR', 'consumers' => ['Match Story and Match audit'], 'retention' => 'WORLD_HISTORY_REQUIRED'],
        'career_match_evaluations' => ['growth' => 'MATCH_LINEAR', 'consumers' => ['Development feedback and Career progression'], 'retention' => 'PLAYER_HISTORY_REQUIRED'],
        'player_season_statistics' => ['growth' => 'SEASON_LINEAR', 'consumers' => ['Profile', 'Career History', 'Trophy/records', 'Awards'], 'retention' => 'PLAYER_HISTORY_REQUIRED'],
        'player_competition_statistics' => ['growth' => 'SEASON_LINEAR', 'consumers' => ['Competition leaders and Profile'], 'retention' => 'WORLD_HISTORY_REQUIRED'],
        'player_development_history' => ['growth' => 'EVENT_LINEAR', 'consumers' => ['Training feedback', 'Profile', 'Career History'], 'retention' => 'PLAYER_HISTORY_REQUIRED'],
        'player_development_state' => ['growth' => 'ACTIVE_REQUIRED', 'consumers' => ['Training and development progression'], 'retention' => 'ACTIVE_REQUIRED'],
        'player_availability_sources' => ['growth' => 'EVENT_LINEAR', 'consumers' => ['Readiness, injury/suspension recovery, fatigue deduplication'], 'retention' => 'ACTIVE_REQUIRED'],
        'player_availability_state' => ['growth' => 'ACTIVE_REQUIRED', 'consumers' => ['Readiness and active availability'], 'retention' => 'ACTIVE_REQUIRED'],
        'player_discipline_states' => ['growth' => 'ACTIVE_REQUIRED', 'consumers' => ['Suspension eligibility and recovery'], 'retention' => 'ACTIVE_REQUIRED'],
        'player_discipline_sources' => ['growth' => 'EVENT_LINEAR', 'consumers' => ['Suspension accumulation and audit'], 'retention' => 'PLAYER_HISTORY_REQUIRED'],
        'player_injuries' => ['growth' => 'EVENT_LINEAR', 'consumers' => ['Availability and recovery history'], 'retention' => 'PLAYER_HISTORY_REQUIRED'],
        'career_events' => ['growth' => 'EVENT_LINEAR', 'consumers' => ['Decisions, Career History, Pulse/news'], 'retention' => 'PLAYER_HISTORY_REQUIRED'],
        'career_awards' => ['growth' => 'SEASON_LINEAR', 'consumers' => ['Trophy Room, Career Legacy, awards'], 'retention' => 'PLAYER_HISTORY_REQUIRED'],
        'career_honours' => ['growth' => 'SEASON_LINEAR', 'consumers' => ['Trophy Room, Career Legacy, records'], 'retention' => 'PLAYER_HISTORY_REQUIRED'],
        'career_records' => ['growth' => 'SEASON_LINEAR', 'consumers' => ['Trophy Room, Career Legacy, Profile'], 'retention' => 'PLAYER_HISTORY_REQUIRED'],
        'career_milestones' => ['growth' => 'EVENT_LINEAR', 'consumers' => ['Career History and Legacy'], 'retention' => 'PLAYER_HISTORY_REQUIRED'],
        'transfer_records' => ['growth' => 'MOVEMENT_LINEAR', 'consumers' => ['Transfer Market and Career History'], 'retention' => 'WORLD_HISTORY_REQUIRED'],
        'loan_records' => ['growth' => 'MOVEMENT_LINEAR', 'consumers' => ['Active movement, contract and Career History'], 'retention' => 'PLAYER_HISTORY_REQUIRED'],
        'player_loans' => ['growth' => 'MOVEMENT_LINEAR', 'consumers' => ['Active loan and return logic'], 'retention' => 'ACTIVE_REQUIRED'],
        'player_population_generations' => ['growth' => 'WORLD_LINEAR', 'consumers' => ['Population/new-generation provenance'], 'retention' => 'WORLD_HISTORY_REQUIRED'],
        'club_competition_memberships' => ['growth' => 'SEASON_LINEAR', 'consumers' => ['Competition membership and fixtures'], 'retention' => 'ACTIVE_REQUIRED'],
        'season_records' => ['growth' => 'SEASON_LINEAR', 'consumers' => ['Season transition, review, and history'], 'retention' => 'WORLD_HISTORY_REQUIRED'],
        'world_records' => ['growth' => 'STATIC', 'consumers' => ['World clock and Season transition'], 'retention' => 'ACTIVE_REQUIRED'],
        'player_finance_transactions' => ['growth' => 'EVENT_LINEAR', 'consumers' => ['Finances and Career audit'], 'retention' => 'PLAYER_HISTORY_REQUIRED'],
        'player_social_history' => ['growth' => 'EVENT_LINEAR', 'consumers' => ['Relationships and social history'], 'retention' => 'PLAYER_HISTORY_REQUIRED'],
        'pulse_feed_sources' => ['growth' => 'EVENT_LINEAR', 'consumers' => ['Pulse/news deduplication and feed'], 'retention' => 'WORLD_HISTORY_REQUIRED'],
        'pulse_posts' => ['growth' => 'EVENT_LINEAR', 'consumers' => ['Pulse/news presentation'], 'retention' => 'WORLD_HISTORY_REQUIRED'],
        'pulse_thread_edges' => ['growth' => 'EVENT_LINEAR', 'consumers' => ['Pulse thread presentation'], 'retention' => 'WORLD_HISTORY_REQUIRED'],
        'pulse_player_states' => ['growth' => 'EVENT_LINEAR', 'consumers' => ['Pulse audience state'], 'retention' => 'ACTIVE_REQUIRED'],
        'pulse_response_states' => ['growth' => 'EVENT_LINEAR', 'consumers' => ['Pulse decisions and response state'], 'retention' => 'ACTIVE_REQUIRED'],
        'haya_simulation_mutation_audit' => ['growth' => 'DIAGNOSTIC', 'consumers' => ['Simulation diagnostics only'], 'retention' => 'EPHEMERAL'],
        'season_compaction_runs' => ['growth' => 'SEASON_LINEAR', 'consumers' => ['Idempotence guard for historical compaction'], 'retention' => 'ACTIVE_REQUIRED'],
    ];

    /** @return array<string, array{growth:string,consumers:list<string>,retention:string}> */
    public static function retentionMap(): array
    {
        return self::RETENTION_MAP;
    }

    /** @return array<string, mixed> */
    public function inspect(string $path): array
    {
        if ($path === '' || str_contains($path, "\0") || !is_file($path) || is_link($path)) {
            throw new PersistenceException('Storage attribution requires a canonical SQLite file.');
        }

        $database = new SqliteDatabase($path);
        try {
            return $this->inspectConnection($database->connection(), $path);
        } finally {
            unset($database);
        }
    }

    /** @return array<string, mixed> */
    public function inspectConnection(PDO $connection, string $path): array
    {
        clearstatcache(true, $path);
        $fileSize = filesize($path);
        if ($fileSize === false) {
            throw new PersistenceException('Unable to measure SQLite storage.');
        }
        $pageSize = (int) $connection->query('PRAGMA page_size')->fetchColumn();
        $pageCount = (int) $connection->query('PRAGMA page_count')->fetchColumn();
        $freelist = (int) $connection->query('PRAGMA freelist_count')->fetchColumn();
        $integrity = (string) $connection->query('PRAGMA integrity_check')->fetchColumn();
        $foreignKeyViolations = 0;
        foreach ($connection->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_NUM) as $_violation) {
            ++$foreignKeyViolations;
        }

        $dbstat = [];
        $dbstatAvailable = true;
        try {
            foreach ($connection->query('SELECT name, SUM(pgsize) AS bytes, SUM(payload) AS payload_bytes, SUM(unused) AS unused_bytes FROM dbstat GROUP BY name')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $dbstat[(string) $row['name']] = [
                    'bytes' => (int) ($row['bytes'] ?? 0),
                    'payload_bytes' => (int) ($row['payload_bytes'] ?? 0),
                    'unused_bytes' => (int) ($row['unused_bytes'] ?? 0),
                ];
            }
        } catch (Throwable) {
            $dbstatAvailable = false;
        }

        $objects = $connection->query("SELECT type, name, tbl_name FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
        $indexesByTable = [];
        $indexes = [];
        foreach ($objects as $object) {
            if (($object['type'] ?? '') !== 'index') {
                continue;
            }
            $name = (string) $object['name'];
            $table = (string) $object['tbl_name'];
            $bytes = $dbstat[$name]['bytes'] ?? null;
            $indexesByTable[$table] = ($indexesByTable[$table] ?? 0) + (int) ($bytes ?? 0);
            $indexes[] = [
                'index_name' => $name,
                'table_name' => $table,
                'storage_bytes' => $bytes,
                'dbstat' => $dbstatAvailable ? 'AVAILABLE' : 'UNAVAILABLE',
            ];
        }

        $databaseBytes = max(1, $pageSize * $pageCount);
        $tables = [];
        foreach ($objects as $object) {
            if (($object['type'] ?? '') !== 'table') {
                continue;
            }
            $name = (string) $object['name'];
            $identifier = '"' . str_replace('"', '""', $name) . '"';
            $rowCount = (int) $connection->query('SELECT COUNT(*) FROM ' . $identifier)->fetchColumn();
            $bytes = $dbstat[$name]['bytes'] ?? null;
            $classification = self::classification($name);
            $tables[] = [
                'table_name' => $name,
                'row_count' => $rowCount,
                'approx_storage_bytes' => $bytes,
                'percent_of_database' => $bytes === null ? null : round($bytes / $databaseBytes * 100, 3),
                'index_storage_bytes' => $indexesByTable[$name] ?? ($dbstatAvailable ? 0 : null),
                'growth_classification' => $classification['growth'],
                'known_consumers' => $classification['consumers'],
                'retention_class' => $classification['retention'],
                'dbstat' => $dbstatAvailable ? 'AVAILABLE' : 'UNAVAILABLE',
            ];
        }
        usort($tables, static fn (array $left, array $right): int => (($right['approx_storage_bytes'] ?? -1) <=> ($left['approx_storage_bytes'] ?? -1)) ?: strcmp((string) $left['table_name'], (string) $right['table_name']));
        usort($indexes, static fn (array $left, array $right): int => (($right['storage_bytes'] ?? -1) <=> ($left['storage_bytes'] ?? -1)) ?: strcmp((string) $left['index_name'], (string) $right['index_name']));

        return [
            'file_size_bytes' => (int) $fileSize,
            'page_size' => $pageSize,
            'page_count' => $pageCount,
            'freelist_count' => $freelist,
            'used_approx_bytes' => max(0, $pageCount - $freelist) * $pageSize,
            'free_approx_bytes' => $freelist * $pageSize,
            'journal_mode' => (string) $connection->query('PRAGMA journal_mode')->fetchColumn(),
            'auto_vacuum' => (string) $connection->query('PRAGMA auto_vacuum')->fetchColumn(),
            'integrity_check' => $integrity,
            'foreign_key_violations' => $foreignKeyViolations,
            'dbstat' => $dbstatAvailable ? 'AVAILABLE' : 'UNAVAILABLE',
            'tables' => $tables,
            'indexes' => $indexes,
        ];
    }

    /** @return array{growth:string,consumers:list<string>,retention:string} */
    private static function classification(string $table): array
    {
        if (isset(self::RETENTION_MAP[$table])) {
            return self::RETENTION_MAP[$table];
        }
        if (str_contains($table, 'match_') || str_contains($table, '_match_')) {
            return ['growth' => 'MATCH_LINEAR', 'consumers' => ['Match or historical projection; exact consumer requires follow-up tracing'], 'retention' => 'UNKNOWN_CONSUMER'];
        }
        if (str_contains($table, 'season') || str_contains($table, 'registration') || str_contains($table, 'membership')) {
            return ['growth' => 'SEASON_LINEAR', 'consumers' => ['Season/world service; exact consumer requires follow-up tracing'], 'retention' => 'UNKNOWN_CONSUMER'];
        }

        return ['growth' => 'UNKNOWN', 'consumers' => ['No P4-001 consumer mapping yet'], 'retention' => 'UNKNOWN_CONSUMER'];
    }
}
