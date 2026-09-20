<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use PDO;

/** Compact current disciplinary state; Match facts remain the canonical card ledger. */
final class PlayerDisciplineRepository
{
    private const STATES = 'player_discipline_states';
    private const SOURCES = 'player_discipline_sources';

    public function __construct(private readonly DatabaseInterface $database)
    {
        SchemaInitializationGuard::run($this->database->connection(), self::class, function (): void {
            $this->database->connection()->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::STATES . ' ('
                . 'player_id TEXT NOT NULL, scope TEXT NOT NULL, accumulation_cycle TEXT NOT NULL, '
                . 'yellow_count INTEGER NOT NULL DEFAULT 0, suspension_matches_remaining INTEGER NOT NULL DEFAULT 0, '
                . 'suspension_reason TEXT NULL, source_match_id TEXT NULL, source_competition_id TEXT NULL, '
                . 'updated_date TEXT NOT NULL, PRIMARY KEY (player_id, scope))'
            );
            $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_player_discipline_active ON ' . self::STATES . ' (player_id, suspension_matches_remaining)');
            $this->database->connection()->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::SOURCES . ' ('
                . 'player_id TEXT NOT NULL, match_id TEXT NOT NULL, scope TEXT NOT NULL, processed_date TEXT NOT NULL, '
                . 'PRIMARY KEY (player_id, match_id, scope))'
            );
        });
    }

    /** @return array<string, mixed>|null */
    public function find(string $playerId, string $scope): ?array
    {
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::STATES . ' WHERE player_id = :player_id AND scope = :scope');
        $statement->execute(['player_id' => $playerId, 'scope' => $scope]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function byPlayer(string $playerId): array
    {
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::STATES . ' WHERE player_id = :player_id ORDER BY scope ASC');
        $statement->execute(['player_id' => $playerId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<string> $playerIds @return array<string, array<string, mixed>> */
    public function activeByPlayers(string $scope, array $playerIds): array
    {
        $playerIds = array_values(array_unique(array_map('strval', $playerIds)));
        if ($playerIds === []) {
            return [];
        }
        $placeholders = [];
        $parameters = ['scope' => $scope];
        foreach ($playerIds as $index => $playerId) {
            $key = 'player_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $playerId;
        }
        $statement = $this->database->connection()->prepare(
            'SELECT * FROM ' . self::STATES . ' WHERE scope = :scope AND suspension_matches_remaining > 0 AND player_id IN (' . implode(', ', $placeholders) . ')'
        );
        $statement->execute($parameters);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['player_id']] = $row;
        }

        return $result;
    }

    /** @param array<string, mixed> $state */
    public function save(array $state): void
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO ' . self::STATES . ' '
            . '(player_id, scope, accumulation_cycle, yellow_count, suspension_matches_remaining, suspension_reason, source_match_id, source_competition_id, updated_date) '
            . 'VALUES (:player_id, :scope, :accumulation_cycle, :yellow_count, :suspension_matches_remaining, :suspension_reason, :source_match_id, :source_competition_id, :updated_date) '
            . 'ON CONFLICT(player_id, scope) DO UPDATE SET accumulation_cycle = excluded.accumulation_cycle, yellow_count = excluded.yellow_count, '
            . 'suspension_matches_remaining = excluded.suspension_matches_remaining, suspension_reason = excluded.suspension_reason, '
            . 'source_match_id = excluded.source_match_id, source_competition_id = excluded.source_competition_id, updated_date = excluded.updated_date'
        );
        $statement->execute([
            'player_id' => (string) $state['player_id'],
            'scope' => (string) $state['scope'],
            'accumulation_cycle' => (string) $state['accumulation_cycle'],
            'yellow_count' => (int) $state['yellow_count'],
            'suspension_matches_remaining' => (int) $state['suspension_matches_remaining'],
            'suspension_reason' => $state['suspension_reason'] ?? null,
            'source_match_id' => $state['source_match_id'] ?? null,
            'source_competition_id' => $state['source_competition_id'] ?? null,
            'updated_date' => (string) $state['updated_date'],
        ]);
    }

    public function processed(string $playerId, string $matchId, string $scope): bool
    {
        $statement = $this->database->connection()->prepare('SELECT 1 FROM ' . self::SOURCES . ' WHERE player_id = :player_id AND match_id = :match_id AND scope = :scope');
        $statement->execute(['player_id' => $playerId, 'match_id' => $matchId, 'scope' => $scope]);

        return $statement->fetchColumn() !== false;
    }

    public function markProcessed(string $playerId, string $matchId, string $scope, string $date): void
    {
        $statement = $this->database->connection()->prepare(
            'INSERT OR IGNORE INTO ' . self::SOURCES . ' (player_id, match_id, scope, processed_date) VALUES (:player_id, :match_id, :scope, :processed_date)'
        );
        $statement->execute(['player_id' => $playerId, 'match_id' => $matchId, 'scope' => $scope, 'processed_date' => $date]);
    }
}
