<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\World;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use PDO;
use RuntimeException;

/**
 * Archives the operational residue of a completed Season after its successor
 * is active. SeasonCompactionService owns replay-only Match evidence; this
 * boundary owns the smaller, row-aware lifecycle families whose active
 * consumers have already moved to the successor.
 */
final class SeasonArchiveService
{
    private const RUN_TABLE = 'save_archival_seasons';
    private const FAMILIES = [
        'registrations' => 'player_competition_registrations',
        'memberships' => 'club_squad_memberships',
        'role_history' => 'club_squad_role_history',
        'contracts' => 'contract_records',
    ];
    private const MIN_PHYSICAL_RECLAIM_BYTES = 1024 * 1024;
    private const MIN_PHYSICAL_RECLAIM_RATIO = 0.10;

    /** @return array<string, mixed> */
    public function archive(DatabaseInterface $database, SeasonId|string $seasonId, SeasonId|string $activeSeasonId, string $archivedAt): array
    {
        $season = $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId);
        $active = $activeSeasonId instanceof SeasonId ? $activeSeasonId : new SeasonId($activeSeasonId);
        if ($season->value() === $active->value()) {
            throw new RuntimeException('Season archival requires a distinct active successor.');
        }

        $connection = $database->connection();
        $this->assertArchiveSafe($connection, $season, $active);
        $this->ensureSchema($connection);

        // P4-002 is deliberately part of the same canonical lifecycle owner:
        // the archive boundary never assumes its durable NPC Season summary
        // has already been materialized by a caller.
        $historicalCompaction = (new SeasonCompactionService())->compact(
            $database,
            $season,
            $this->activeSeasonStart($connection, $active),
        );

        $already = $connection->prepare('SELECT 1 FROM ' . self::RUN_TABLE . ' WHERE season_id = :season_id');
        $already->execute(['season_id' => $season->value()]);
        if ($already->fetchColumn() !== false) {
            return [
                'archived' => false,
                'idempotent' => true,
                'historical_compaction' => $historicalCompaction,
                'registrations' => 0,
                'memberships' => 0,
                'role_history' => 0,
                'contracts' => 0,
                'logical_rows_removed' => 0,
                'physical_compaction_recommended' => false,
                'family_metrics_before' => $this->familyMetrics($connection),
                'family_metrics_after' => $this->familyMetrics($connection),
            ];
        }

        $controlled = $this->controlledPlayers($connection);
        $before = $this->familyMetrics($connection);
        $result = $database->transaction(function () use ($connection, $season, $active, $archivedAt, $controlled): array {
            $registrations = $this->deleteObsoleteRegistrations($connection, $season, $controlled);
            $memberships = $this->deleteObsoleteMemberships($connection, $season, $active, $controlled);
            $roleHistory = $this->deleteNpcRoleHistory($connection, $season, $controlled);
            $contracts = $this->deleteExpiredNpcContracts($connection, $active, $controlled);

            $statement = $connection->prepare(
                'INSERT INTO ' . self::RUN_TABLE . ' '
                . '(season_id, active_season_id, archived_at, registration_rows, membership_rows, role_history_rows, contract_rows) '
                . 'VALUES (:season_id, :active_season_id, :archived_at, :registration_rows, :membership_rows, :role_history_rows, :contract_rows)'
            );
            $statement->execute([
                'season_id' => $season->value(),
                'active_season_id' => $active->value(),
                'archived_at' => $archivedAt,
                'registration_rows' => $registrations,
                'membership_rows' => $memberships,
                'role_history_rows' => $roleHistory,
                'contract_rows' => $contracts,
            ]);

            return [
                'archived' => true,
                'idempotent' => false,
                'registrations' => $registrations,
                'memberships' => $memberships,
                'role_history' => $roleHistory,
                'contracts' => $contracts,
            ];
        });

        $after = $this->familyMetrics($connection);
        $logicalRows = (int) $result['registrations']
            + (int) $result['memberships']
            + (int) $result['role_history']
            + (int) $result['contracts'];
        $pageSize = (int) $connection->query('PRAGMA page_size')->fetchColumn();
        $pageCount = (int) $connection->query('PRAGMA page_count')->fetchColumn();
        $freelist = (int) $connection->query('PRAGMA freelist_count')->fetchColumn();
        $freeBytes = $freelist * $pageSize;
        $freelistRatio = $pageCount === 0 ? 0.0 : $freelist / $pageCount;

        return $result + [
            'historical_compaction' => $historicalCompaction,
            'logical_rows_removed' => $logicalRows,
            'family_metrics_before' => $before,
            'family_metrics_after' => $after,
            'page_size' => $pageSize,
            'page_count' => $pageCount,
            'freelist_count' => $freelist,
            'physical_compaction_recommended' => $freeBytes >= self::MIN_PHYSICAL_RECLAIM_BYTES
                || $freelistRatio >= self::MIN_PHYSICAL_RECLAIM_RATIO,
        ];
    }

    private function assertArchiveSafe(PDO $connection, SeasonId $season, SeasonId $active): void
    {
        foreach (['season_records', 'world_records'] as $table) {
            if (!$this->tableExists($connection, $table)) {
                throw new RuntimeException(sprintf('Season archival requires the persisted %s lifecycle table.', $table));
            }
        }
        $status = $connection->prepare('SELECT status FROM season_records WHERE id = :season_id');
        $status->execute(['season_id' => $season->value()]);
        if ((string) $status->fetchColumn() !== 'completed') {
            throw new RuntimeException(sprintf('Season archival requires completed Season "%s".', $season->value()));
        }
        $status->execute(['season_id' => $active->value()]);
        if ((string) $status->fetchColumn() !== 'active') {
            throw new RuntimeException(sprintf('Season archival requires active successor "%s".', $active->value()));
        }
        $current = $connection->query('SELECT current_season_id FROM world_records LIMIT 1')->fetchColumn();
        if ((string) $current !== $active->value()) {
            throw new RuntimeException('Season archival requires the active successor to be the current World Season.');
        }
        if ($this->tableExists($connection, 'match_records')) {
            $unresolved = $connection->prepare('SELECT COUNT(*) FROM match_records WHERE season_id = :season_id AND status <> :status');
            $unresolved->execute(['season_id' => $season->value(), 'status' => 'completed']);
            if ((int) $unresolved->fetchColumn() > 0) {
                throw new RuntimeException('Season archival requires all historical Matches to be completed.');
            }
        }
        if ($this->tableExists($connection, 'competition_records')) {
            $unresolved = $connection->prepare('SELECT COUNT(*) FROM competition_records WHERE season_id = :season_id AND status <> :status');
            $unresolved->execute(['season_id' => $season->value(), 'status' => 'completed']);
            if ((int) $unresolved->fetchColumn() > 0) {
                throw new RuntimeException('Season archival requires all historical Competitions to be completed.');
            }
        }
        if ($this->tableExists($connection, 'club_squad_memberships')) {
            $successorSquad = $connection->prepare('SELECT COUNT(*) FROM club_squad_memberships WHERE season_id = :season_id');
            $successorSquad->execute(['season_id' => $active->value()]);
            if ((int) $successorSquad->fetchColumn() === 0) {
                throw new RuntimeException('Season archival requires successor squad continuity to be materialized.');
            }
        }
        if (!$this->tableExists($connection, 'career_player_references')) {
            throw new RuntimeException('Season archival requires a canonical controlled-player reference.');
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

    /** @param array<string, bool> $controlled */
    private function deleteObsoleteRegistrations(PDO $connection, SeasonId $season, array $controlled): int
    {
        if (!$this->tableExists($connection, self::FAMILIES['registrations'])) {
            return 0;
        }
        $notControlled = $controlled === []
            ? '1 = 1'
            : 'NOT EXISTS (SELECT 1 FROM career_player_references careers WHERE careers.player_id = rows.player_id)';
        $statement = $connection->prepare('DELETE FROM player_competition_registrations AS rows WHERE rows.season_id = :season_id AND ' . $notControlled);
        $statement->execute(['season_id' => $season->value()]);

        return $statement->rowCount();
    }

    /** @param array<string, bool> $controlled */
    private function deleteObsoleteMemberships(PDO $connection, SeasonId $season, SeasonId $active, array $controlled): int
    {
        if (!$this->tableExists($connection, self::FAMILIES['memberships'])) {
            return 0;
        }
        $notControlled = $controlled === []
            ? '1 = 1'
            : 'NOT EXISTS (SELECT 1 FROM career_player_references careers WHERE careers.player_id = rows.player_id)';
        // Same-Club historical rows remain for current NPC squad members:
        // ClubCaptaincyService and SetPieceResponsibilityService use the
        // existing membership count as a gameplay tenure input.
        $statement = $connection->prepare(
            'DELETE FROM club_squad_memberships AS rows WHERE rows.season_id = :season_id AND ' . $notControlled
            . ' AND NOT EXISTS (SELECT 1 FROM club_squad_memberships current_rows '
            . 'WHERE current_rows.season_id = :active_season_id AND current_rows.player_id = rows.player_id AND current_rows.club_id = rows.club_id)'
        );
        $statement->execute(['season_id' => $season->value(), 'active_season_id' => $active->value()]);

        return $statement->rowCount();
    }

    /** @param array<string, bool> $controlled */
    private function deleteNpcRoleHistory(PDO $connection, SeasonId $season, array $controlled): int
    {
        if (!$this->tableExists($connection, self::FAMILIES['role_history'])) {
            return 0;
        }
        $notControlled = $controlled === []
            ? '1 = 1'
            : 'NOT EXISTS (SELECT 1 FROM career_player_references careers WHERE careers.player_id = rows.player_id)';
        $statement = $connection->prepare('DELETE FROM club_squad_role_history AS rows WHERE rows.season_id = :season_id AND ' . $notControlled);
        $statement->execute(['season_id' => $season->value()]);

        return $statement->rowCount();
    }

    /** @param array<string, bool> $controlled */
    private function deleteExpiredNpcContracts(PDO $connection, SeasonId $active, array $controlled): int
    {
        if (!$this->tableExists($connection, self::FAMILIES['contracts'])) {
            return 0;
        }
        $activeStart = $this->activeSeasonStart($connection, $active);
        $notControlled = $controlled === []
            ? '1 = 1'
            : 'NOT EXISTS (SELECT 1 FROM career_player_references careers WHERE careers.player_id = contracts.player_id)';
        $transferGuard = $this->tableExists($connection, 'transfer_records')
            ? 'AND NOT EXISTS (SELECT 1 FROM transfer_records transfers WHERE transfers.source_contract_id = contracts.id OR transfers.destination_contract_id = contracts.id)'
            : '';
        $loanGuard = $this->tableExists($connection, 'player_loans')
            ? 'AND NOT EXISTS (SELECT 1 FROM player_loans loans WHERE loans.player_id = contracts.player_id AND loans.status = :loan_status)'
            : '';
        $statement = $connection->prepare(
            'DELETE FROM contract_records AS contracts WHERE ' . $notControlled
            . ' AND contracts.status IN (:expired, :terminated) AND contracts.end_date < :active_start '
            . $transferGuard . ' ' . $loanGuard
        );
        $parameters = [
            'expired' => 'expired',
            'terminated' => 'terminated',
            'active_start' => $activeStart,
        ];
        if ($loanGuard !== '') {
            $parameters['loan_status'] = 'active';
        }
        $statement->execute($parameters);

        return $statement->rowCount();
    }

    private function activeSeasonStart(PDO $connection, SeasonId $active): string
    {
        $statement = $connection->prepare('SELECT start_date FROM season_records WHERE id = :season_id');
        $statement->execute(['season_id' => $active->value()]);
        $date = $statement->fetchColumn();
        if (!is_string($date) || $date === '') {
            throw new RuntimeException(sprintf('Active successor "%s" has no start date.', $active->value()));
        }

        return $date;
    }

    private function ensureSchema(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::RUN_TABLE . ' ('
            . 'season_id TEXT PRIMARY KEY, active_season_id TEXT NOT NULL, archived_at TEXT NOT NULL, '
            . 'registration_rows INTEGER NOT NULL, membership_rows INTEGER NOT NULL, '
            . 'role_history_rows INTEGER NOT NULL, contract_rows INTEGER NOT NULL)'
        );
    }

    /** @return array<string, array{rows:int,bytes:?int,index_bytes:?int}> */
    private function familyMetrics(PDO $connection): array
    {
        $dbstat = [];
        $dbstatAvailable = true;
        try {
            foreach ($connection->query('SELECT name, SUM(pgsize) AS bytes FROM dbstat GROUP BY name')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $dbstat[(string) $row['name']] = (int) ($row['bytes'] ?? 0);
            }
        } catch (\Throwable) {
            $dbstatAvailable = false;
        }
        $result = [];
        foreach (self::FAMILIES as $family => $table) {
            if (!$this->tableExists($connection, $table)) {
                continue;
            }
            $identifier = '"' . str_replace('"', '""', $table) . '"';
            $rows = (int) $connection->query('SELECT COUNT(*) FROM ' . $identifier)->fetchColumn();
            $indexBytes = null;
            $bytes = null;
            if ($dbstatAvailable) {
                $bytes = $dbstat[$table] ?? 0;
                $indexBytes = 0;
                $indexes = $connection->prepare("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = :table");
                $indexes->execute(['table' => $table]);
                foreach ($indexes->fetchAll(PDO::FETCH_COLUMN) as $index) {
                    $indexBytes += $dbstat[(string) $index] ?? 0;
                }
            }
            $result[$family] = ['rows' => $rows, 'bytes' => $bytes, 'index_bytes' => $indexBytes];
        }

        return $result;
    }

    private function tableExists(PDO $connection, string $table): bool
    {
        $statement = $connection->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table");
        $statement->execute(['table' => $table]);

        return $statement->fetchColumn() !== false;
    }
}
