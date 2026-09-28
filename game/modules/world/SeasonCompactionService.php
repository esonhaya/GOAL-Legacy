<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\World;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\PlayerMatchRatingService;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use PDO;
use RuntimeException;

/**
 * Archives completed-season NPC evidence and removes replay-only detail.
 *
 * Match results, highlights, substitutions, controlled-player evidence,
 * movement, contracts, and Season aggregates remain authoritative. The
 * source tables are deliberately compacted only after a Season is complete.
 */
final class SeasonCompactionService
{
    private const ARCHIVE_TABLE = 'player_season_statistics';
    private const FORM_TABLE = 'player_form_summaries';
    private const RUN_TABLE = 'save_compaction_seasons';
    private const TARGET_TABLES = [
        'match_player_stats',
        'match_player_selections',
        'match_highlights',
        'match_substitutions',
        'career_match_evaluations',
        'player_development_history',
        'player_availability_sources',
    ];
    private const MIN_PHYSICAL_RECLAIM_BYTES = 1024 * 1024;
    private const MIN_PHYSICAL_RECLAIM_RATIO = 0.10;

    /** @return array<string, mixed> */
    public function compact(DatabaseInterface $database, SeasonId|string $seasonId, string $nextSeasonStart): array
    {
        $season = $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId);
        $connection = $database->connection();
        $this->assertSeasonFinalized($connection, $season);
        $this->ensureSchema($connection);

        $already = $connection->prepare('SELECT 1 FROM ' . self::RUN_TABLE . ' WHERE season_id = :season_id');
        $already->execute(['season_id' => $season->value()]);
        if ($already->fetchColumn() !== false) {
            return [
                'compacted' => false,
                'idempotent' => true,
                'stats' => 0,
                'selections' => 0,
                'highlights' => 0,
                'substitutions' => 0,
                'evaluations' => 0,
                'development' => 0,
                'availability' => 0,
                'logical_rows_removed' => 0,
                'physical_compaction_recommended' => false,
            ];
        }

        $controlled = $this->controlledPlayers($connection);
        $before = $this->storageMetrics($connection);
        $result = $database->transaction(function () use ($connection, $database, $season, $nextSeasonStart, $controlled): array {
            $aggregates = $this->seasonAggregates($connection, $season, $controlled);
            $this->insertAggregates($connection, $aggregates);

            $stats = $this->deleteNpcMatchRows($connection, 'match_player_stats', $season, $controlled);
            $selections = $this->deleteNpcMatchRows($connection, 'match_player_selections', $season, $controlled);
            $details = $this->deleteBackgroundMatchDetails($connection, $season);
            $evaluations = $this->compactEvaluations($connection, $season);
            $development = $this->deleteNpcDevelopment($connection, $nextSeasonStart, $controlled);
            $availability = $this->deleteOldAvailability($connection, $nextSeasonStart);

            $statement = $connection->prepare(
                'INSERT INTO ' . self::RUN_TABLE . ' (season_id, compacted_at, stats_rows, selection_rows, evaluation_rows, development_rows, availability_rows) '
                . 'VALUES (:season_id, :compacted_at, :stats_rows, :selection_rows, :evaluation_rows, :development_rows, :availability_rows)'
            );
            $statement->execute([
                'season_id' => $season->value(),
                'compacted_at' => $nextSeasonStart,
                'stats_rows' => $stats,
                'selection_rows' => $selections,
                'evaluation_rows' => $evaluations,
                'development_rows' => $development,
                'availability_rows' => $availability,
            ]);

            return [
                'compacted' => true,
                'idempotent' => false,
                'stats' => $stats,
                'selections' => $selections,
                'highlights' => $details['highlights'],
                'substitutions' => $details['substitutions'],
                'evaluations' => $evaluations,
                'development' => $development,
                'availability' => $availability,
            ];
        });

        // Physical reclamation is deliberately separate. The logical
        // transaction above is the canonical Season boundary; callers may
        // invoke SaveStore::compact when this bounded threshold says it is
        // worthwhile. VACUUM is never hidden inside Season processing.
        $after = $this->storageMetrics($connection);
        $logicalRows = (int) $result['stats']
            + (int) $result['selections']
            + (int) $result['highlights']
            + (int) $result['substitutions']
            + (int) $result['evaluations']
            + (int) $result['development']
            + (int) $result['availability'];
        $freeBytes = (int) $after['freelist_count'] * (int) $after['page_size'];
        $freelistRatio = (int) $after['page_count'] === 0
            ? 0.0
            : (int) $after['freelist_count'] / (int) $after['page_count'];

        $result['logical_rows_removed'] = $logicalRows;
        $result['target_rows_before'] = $before['target_rows'];
        $result['target_rows_after'] = $after['target_rows'];
        $result['target_bytes_before'] = $before['target_bytes'];
        $result['target_bytes_after'] = $after['target_bytes'];
        $result['page_size'] = $after['page_size'];
        $result['page_count_before'] = $before['page_count'];
        $result['page_count_after'] = $after['page_count'];
        $result['freelist_before'] = $before['freelist_count'];
        $result['freelist_after'] = $after['freelist_count'];
        $result['physical_compaction_recommended'] = $freeBytes >= self::MIN_PHYSICAL_RECLAIM_BYTES
            || $freelistRatio >= self::MIN_PHYSICAL_RECLAIM_RATIO;

        return $result;
    }

    private function assertSeasonFinalized(PDO $connection, SeasonId $season): void
    {
        if (!$this->tableExists($connection, 'season_records')) {
            throw new RuntimeException('Historical compaction requires a persisted Season lifecycle record.');
        }
        $statement = $connection->prepare('SELECT status FROM season_records WHERE id = :season_id');
        $statement->execute(['season_id' => $season->value()]);
        if ((string) $statement->fetchColumn() !== 'completed') {
            throw new RuntimeException(sprintf('Historical compaction requires completed Season "%s".', $season->value()));
        }
        if ($this->tableExists($connection, 'match_records')) {
            $unresolved = $connection->prepare('SELECT COUNT(*) FROM match_records WHERE season_id = :season_id AND status <> :status');
            $unresolved->execute(['season_id' => $season->value(), 'status' => 'completed']);
            if ((int) $unresolved->fetchColumn() > 0) {
                throw new RuntimeException(sprintf('Historical compaction requires all Season "%s" Matches to be finalized.', $season->value()));
            }
        }
        if ($this->tableExists($connection, 'competition_records')) {
            $unresolved = $connection->prepare('SELECT COUNT(*) FROM competition_records WHERE season_id = :season_id AND status <> :status');
            $unresolved->execute(['season_id' => $season->value(), 'status' => 'completed']);
            if ((int) $unresolved->fetchColumn() > 0) {
                throw new RuntimeException(sprintf('Historical compaction requires all Season "%s" Competitions to be finalized.', $season->value()));
            }
        }
    }

    /** @return array<string, bool> */
    private function controlledPlayers(PDO $connection): array
    {
        $result = [];
        foreach ($connection->query('SELECT player_id FROM career_player_references')->fetchAll(PDO::FETCH_COLUMN) as $playerId) {
            $result[(string) $playerId] = true;
        }

        return $result;
    }

    /** @return array<string, array<string, int|float|string>> */
    private function seasonAggregates(PDO $connection, SeasonId $season, array $controlled): array
    {
        $statement = $connection->prepare(
            'SELECT stats.*, players.primary_position FROM match_player_stats stats '
            . 'JOIN match_records matches ON matches.id = stats.match_id '
            . 'JOIN player_records players ON players.id = stats.player_id '
            . 'WHERE matches.season_id = :season_id AND matches.status = :status '
            . 'ORDER BY stats.player_id ASC, stats.club_id ASC, stats.match_id ASC'
        );
        $statement->execute(['season_id' => $season->value(), 'status' => 'completed']);
        $ratings = new PlayerMatchRatingService();
        $aggregates = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $playerId = (string) $row['player_id'];
            if (isset($controlled[$playerId])) {
                continue;
            }
            $key = $playerId . '|' . (string) $row['club_id'];
            $aggregates[$key] ??= [
                'player_id' => $playerId,
                'season_id' => $season->value(),
                'club_id' => (string) $row['club_id'],
                'appearances' => 0,
                'starts' => 0,
                'minutes' => 0,
                'goals' => 0,
                'assists' => 0,
                'shots' => 0,
                'shots_on_target' => 0,
                'saves' => 0,
                'clean_sheets' => 0,
                'tackles' => 0,
                'interceptions' => 0,
                'blocks' => 0,
                'passes_attempted' => 0,
                'passes_completed' => 0,
                'fouls_committed' => 0,
                'yellow_cards' => 0,
                'red_cards' => 0,
                'rated_appearances' => 0,
                'rating_total' => 0.0,
            ];
            $aggregate =& $aggregates[$key];
            $stat = new PlayerMatchStat(
                new MatchId((string) $row['match_id']),
                new PlayerId($playerId),
                new ClubId((string) $row['club_id']),
                (bool) $row['appeared'],
                (bool) $row['started'],
                (int) $row['minutes'],
                (int) $row['goals'],
                (int) ($row['assists'] ?? 0),
                (int) ($row['shots'] ?? 0),
                (int) ($row['shots_on_target'] ?? 0),
                (int) ($row['saves'] ?? 0),
                (int) ($row['clean_sheets'] ?? 0),
                (int) ($row['tackles'] ?? 0),
                (int) ($row['interceptions'] ?? 0),
                (int) ($row['blocks'] ?? 0),
                (int) ($row['passes_attempted'] ?? 0),
                (int) ($row['passes_completed'] ?? 0),
                (int) ($row['fouls_committed'] ?? 0),
                (int) ($row['yellow_cards'] ?? 0),
                (int) ($row['red_cards'] ?? 0),
            );
            if ($stat->appeared()) {
                ++$aggregate['appearances'];
                $aggregate['starts'] += $stat->started() ? 1 : 0;
                $aggregate['minutes'] += $stat->minutes();
                foreach ([
                    'goals' => 'goals',
                    'assists' => 'assists',
                    'shots' => 'shots',
                    'shots_on_target' => 'shotsOnTarget',
                    'saves' => 'saves',
                    'clean_sheets' => 'cleanSheets',
                    'tackles' => 'tackles',
                    'interceptions' => 'interceptions',
                    'blocks' => 'blocks',
                    'passes_attempted' => 'passesAttempted',
                    'passes_completed' => 'passesCompleted',
                    'fouls_committed' => 'foulsCommitted',
                    'yellow_cards' => 'yellowCards',
                    'red_cards' => 'redCards',
                ] as $field => $method) {
                    $aggregate[$field] += $stat->{$method}();
                }
                $rating = $ratings->rate($stat, PlayerPosition::from((string) $row['primary_position']));
                if ($rating !== null) {
                    ++$aggregate['rated_appearances'];
                    $aggregate['rating_total'] += $rating;
                }
            }
            unset($aggregate);
        }

        return $aggregates;
    }

    /** @param array<string, array<string, int|float|string>> $aggregates */
    private function insertAggregates(PDO $connection, array $aggregates): void
    {
        if ($aggregates === []) {
            return;
        }
        $statement = $connection->prepare(
            'INSERT OR IGNORE INTO ' . self::ARCHIVE_TABLE . ' '
            . '(player_id, season_id, club_id, appearances, starts, minutes, goals, assists, shots, shots_on_target, saves, clean_sheets, tackles, interceptions, blocks, passes_attempted, passes_completed, fouls_committed, yellow_cards, red_cards, rated_appearances, rating_total) '
            . 'VALUES (:player_id, :season_id, :club_id, :appearances, :starts, :minutes, :goals, :assists, :shots, :shots_on_target, :saves, :clean_sheets, :tackles, :interceptions, :blocks, :passes_attempted, :passes_completed, :fouls_committed, :yellow_cards, :red_cards, :rated_appearances, :rating_total)'
        );
        foreach ($aggregates as $aggregate) {
            $statement->execute($aggregate);
        }
    }

    private function deleteNpcMatchRows(PDO $connection, string $table, SeasonId $season, array $controlled): int
    {
        $notControlled = $controlled === [] ? '1 = 1' : 'NOT EXISTS (SELECT 1 FROM career_player_references careers WHERE careers.player_id = rows.player_id)';
        $protected = $this->protectedMatchCondition($connection, 'matches');
        $statement = $connection->prepare(
            'DELETE FROM ' . $table . ' AS rows WHERE ' . $notControlled
            . ' AND EXISTS (SELECT 1 FROM match_records matches WHERE matches.id = rows.match_id AND matches.season_id = :season_id AND matches.status = :status AND NOT (' . $protected . '))'
        );
        $statement->execute(['season_id' => $season->value(), 'status' => 'completed']);

        return $statement->rowCount();
    }

    /** @return array{highlights:int,substitutions:int} */
    private function deleteBackgroundMatchDetails(PDO $connection, SeasonId $season): array
    {
        $result = ['highlights' => 0, 'substitutions' => 0];
        $protected = $this->protectedMatchCondition($connection, 'matches');
        foreach (['match_highlights' => 'highlights', 'match_substitutions' => 'substitutions'] as $table => $key) {
            if (!$this->tableExists($connection, $table)) {
                continue;
            }
            $statement = $connection->prepare(
                'DELETE FROM ' . $table . ' WHERE match_id IN ('
                . 'SELECT matches.id FROM match_records matches WHERE matches.season_id = :season_id AND matches.status = :status AND NOT (' . $protected . '))'
            );
            $statement->execute(['season_id' => $season->value(), 'status' => 'completed']);
            $result[$key] = $statement->rowCount();
        }

        return $result;
    }

    private function compactEvaluations(PDO $connection, SeasonId $season): int
    {
        if (!$this->tableExists($connection, 'career_match_evaluations')) {
            return 0;
        }
        $connection->exec('DROP TABLE IF EXISTS p2_compact_evaluation_rows');
        $connection->exec('CREATE TEMP TABLE p2_compact_evaluation_rows (row_id INTEGER PRIMARY KEY, player_id TEXT NOT NULL, club_id TEXT NOT NULL, evaluation_score INTEGER NOT NULL)');
        $connection->prepare(
            'INSERT INTO p2_compact_evaluation_rows (row_id, player_id, club_id, evaluation_score) '
            . 'SELECT row_id, player_id, club_id, evaluation_score FROM ('
            . 'SELECT evaluations.rowid AS row_id, evaluations.player_id, evaluations.club_id, evaluations.evaluation_score, matches.season_id, '
            . 'ROW_NUMBER() OVER (PARTITION BY evaluations.player_id, evaluations.club_id ORDER BY evaluations.occurred_date DESC, evaluations.match_id DESC) AS row_number '
            . 'FROM career_match_evaluations evaluations JOIN match_records matches ON matches.id = evaluations.match_id '
            . 'WHERE NOT EXISTS (SELECT 1 FROM career_player_references careers WHERE careers.player_id = evaluations.player_id)'
            . ') ranked WHERE season_id = :season_id AND row_number > 2'
        )->execute(['season_id' => $season->value()]);
        $connection->exec(
            'INSERT INTO ' . self::FORM_TABLE . ' (player_id, club_id, evaluation_count, evaluation_total) '
            . 'SELECT player_id, club_id, COUNT(*), SUM(evaluation_score) FROM p2_compact_evaluation_rows '
            . 'GROUP BY player_id, club_id '
            . 'ON CONFLICT(player_id, club_id) DO UPDATE SET evaluation_count = evaluation_count + excluded.evaluation_count, evaluation_total = evaluation_total + excluded.evaluation_total'
        );
        $count = (int) $connection->query('SELECT COUNT(*) FROM p2_compact_evaluation_rows')->fetchColumn();
        $connection->exec('DELETE FROM career_match_evaluations WHERE rowid IN (SELECT row_id FROM p2_compact_evaluation_rows)');
        $connection->exec('DROP TABLE p2_compact_evaluation_rows');

        return $count;
    }

    private function deleteNpcDevelopment(PDO $connection, string $nextSeasonStart, array $controlled): int
    {
        if (!$this->tableExists($connection, 'player_development_history')) {
            return 0;
        }
        $condition = $controlled === [] ? '1 = 1' : 'NOT EXISTS (SELECT 1 FROM career_player_references careers WHERE careers.player_id = history.player_id)';
        $statement = $connection->prepare('DELETE FROM player_development_history AS history WHERE ' . $condition . ' AND history.occurred_date < :date');
        $statement->execute(['date' => $nextSeasonStart]);

        return $statement->rowCount();
    }

    private function deleteOldAvailability(PDO $connection, string $nextSeasonStart): int
    {
        if (!$this->tableExists($connection, 'player_availability_sources')) {
            return 0;
        }
        $statement = $connection->prepare(
            'DELETE FROM player_availability_sources AS sources WHERE sources.occurred_date < :date '
            . 'AND NOT EXISTS (SELECT 1 FROM career_player_references careers WHERE careers.player_id = sources.player_id)'
        );
        $statement->execute(['date' => $nextSeasonStart]);

        return $statement->rowCount();
    }

    private function ensureSchema(PDO $connection): void
    {
        $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::ARCHIVE_TABLE . ' (player_id TEXT NOT NULL, season_id TEXT NOT NULL, club_id TEXT NOT NULL, appearances INTEGER NOT NULL, starts INTEGER NOT NULL, minutes INTEGER NOT NULL, goals INTEGER NOT NULL, assists INTEGER NOT NULL, shots INTEGER NOT NULL, shots_on_target INTEGER NOT NULL, saves INTEGER NOT NULL, clean_sheets INTEGER NOT NULL, tackles INTEGER NOT NULL, interceptions INTEGER NOT NULL, blocks INTEGER NOT NULL, passes_attempted INTEGER NOT NULL, passes_completed INTEGER NOT NULL, fouls_committed INTEGER NOT NULL, yellow_cards INTEGER NOT NULL, red_cards INTEGER NOT NULL, rated_appearances INTEGER NOT NULL, rating_total REAL NOT NULL, PRIMARY KEY (player_id, season_id, club_id))');
        $connection->exec('CREATE INDEX IF NOT EXISTS idx_player_season_statistics_player ON ' . self::ARCHIVE_TABLE . ' (player_id, season_id, club_id)');
        $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::FORM_TABLE . ' (player_id TEXT NOT NULL, club_id TEXT NOT NULL, evaluation_count INTEGER NOT NULL, evaluation_total INTEGER NOT NULL, PRIMARY KEY (player_id, club_id))');
        $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::RUN_TABLE . ' (season_id TEXT PRIMARY KEY, compacted_at TEXT NOT NULL, stats_rows INTEGER NOT NULL, selection_rows INTEGER NOT NULL, evaluation_rows INTEGER NOT NULL, development_rows INTEGER NOT NULL, availability_rows INTEGER NOT NULL)');
    }

    private function protectedMatchCondition(PDO $connection, string $matchAlias): string
    {
        if (!$this->tableExists($connection, 'club_squad_memberships') || !$this->tableExists($connection, 'career_player_references')) {
            return '0 = 1';
        }

        return 'EXISTS (SELECT 1 FROM club_squad_memberships protected_memberships '
            . 'JOIN career_player_references controlled_players ON controlled_players.player_id = protected_memberships.player_id '
            . 'WHERE protected_memberships.season_id = ' . $matchAlias . '.season_id '
            . 'AND (protected_memberships.club_id = ' . $matchAlias . '.home_club_id OR protected_memberships.club_id = ' . $matchAlias . '.away_club_id))';
    }

    private function tableExists(PDO $connection, string $table): bool
    {
        $statement = $connection->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table");
        $statement->execute(['table' => $table]);

        return $statement->fetchColumn() !== false;
    }

    /** @return array{page_size:int,page_count:int,freelist_count:int,target_rows:array<string,int>,target_bytes:?int} */
    private function storageMetrics(PDO $connection): array
    {
        $pageSize = (int) $connection->query('PRAGMA page_size')->fetchColumn();
        $pageCount = (int) $connection->query('PRAGMA page_count')->fetchColumn();
        $freelist = (int) $connection->query('PRAGMA freelist_count')->fetchColumn();
        $rows = [];
        foreach (self::TARGET_TABLES as $table) {
            if (!$this->tableExists($connection, $table)) {
                continue;
            }
            $rows[$table] = (int) $connection->query('SELECT COUNT(*) FROM "' . str_replace('"', '""', $table) . '"')->fetchColumn();
        }

        $dbstat = [];
        try {
            foreach ($connection->query('SELECT name, SUM(pgsize) AS bytes FROM dbstat GROUP BY name')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $dbstat[(string) $row['name']] = (int) ($row['bytes'] ?? 0);
            }
        } catch (\Throwable) {
            $dbstat = [];
        }
        $targetBytes = $dbstat === [] ? null : 0;
        if ($targetBytes !== null) {
            foreach (self::TARGET_TABLES as $table) {
                $targetBytes += $dbstat[$table] ?? 0;
            }
            $indexes = $connection->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name IN ('match_player_stats', 'match_player_selections', 'match_highlights', 'match_substitutions', 'career_match_evaluations', 'player_development_history', 'player_availability_sources')")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($indexes as $index) {
                $targetBytes += $dbstat[(string) $index] ?? 0;
            }
        }

        return [
            'page_size' => $pageSize,
            'page_count' => $pageCount,
            'freelist_count' => $freelist,
            'target_rows' => $rows,
            'target_bytes' => $targetBytes,
        ];
    }
}
