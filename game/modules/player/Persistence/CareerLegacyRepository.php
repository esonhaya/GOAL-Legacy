<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use PDO;

/** Compact durable facts for awards, honours, personal records, and milestones. */
final class CareerLegacyRepository
{
    private const AWARDS = 'career_awards';
    private const HONOURS = 'career_honours';
    private const RECORDS = 'career_legacy_records';
    private const MILESTONES = 'career_legacy_milestones';

    public function __construct(private readonly DatabaseInterface $database, bool $initialize = true)
    {
        if (!$initialize) {
            return;
        }
        SchemaInitializationGuard::run($this->database->connection(), self::class, function (): void {
            $connection = $this->database->connection();
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::AWARDS . ' (source_key TEXT PRIMARY KEY, season_id TEXT NOT NULL, competition_id TEXT NOT NULL, scope TEXT NOT NULL, award_type TEXT NOT NULL, winner_player_id TEXT NOT NULL, club_id TEXT NOT NULL, award_date TEXT NOT NULL, evidence_json TEXT NOT NULL)');
            $connection->exec('CREATE INDEX IF NOT EXISTS idx_career_awards_player ON ' . self::AWARDS . ' (winner_player_id, season_id, award_type)');
            $connection->exec('CREATE INDEX IF NOT EXISTS idx_career_awards_season ON ' . self::AWARDS . ' (season_id, competition_id, award_type)');
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::HONOURS . ' (source_key TEXT PRIMARY KEY, player_id TEXT NOT NULL, season_id TEXT NOT NULL, competition_id TEXT NOT NULL, honour_type TEXT NOT NULL, holder_id TEXT NOT NULL, label TEXT NOT NULL, earned_date TEXT NOT NULL, evidence_json TEXT NOT NULL)');
            $connection->exec('CREATE INDEX IF NOT EXISTS idx_career_honours_player ON ' . self::HONOURS . ' (player_id, season_id, honour_type)');
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::RECORDS . ' (player_id TEXT NOT NULL, metric TEXT NOT NULL, value REAL NOT NULL, season_id TEXT NOT NULL, club_id TEXT NULL, updated_date TEXT NOT NULL, evidence_json TEXT NOT NULL, PRIMARY KEY (player_id, metric))');
            $connection->exec('CREATE INDEX IF NOT EXISTS idx_career_records_player ON ' . self::RECORDS . ' (player_id, metric)');
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::MILESTONES . ' (source_key TEXT PRIMARY KEY, player_id TEXT NOT NULL, season_id TEXT NOT NULL, metric TEXT NOT NULL, threshold REAL NOT NULL, label TEXT NOT NULL, occurred_date TEXT NOT NULL, evidence_json TEXT NOT NULL)');
            $connection->exec('CREATE INDEX IF NOT EXISTS idx_career_milestones_player ON ' . self::MILESTONES . ' (player_id, occurred_date, source_key)');
        });
    }

    public function available(): bool
    {
        $statement = $this->database->connection()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table");
        $statement->execute(['table' => self::AWARDS]);

        return $statement->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $row */
    public function saveAwardInTransaction(array $row): bool
    {
        $statement = $this->database->connection()->prepare('INSERT OR IGNORE INTO ' . self::AWARDS . ' (source_key, season_id, competition_id, scope, award_type, winner_player_id, club_id, award_date, evidence_json) VALUES (:source_key, :season_id, :competition_id, :scope, :award_type, :winner_player_id, :club_id, :award_date, :evidence_json)');

        return $statement->execute($this->jsonRow($row)) && $statement->rowCount() > 0;
    }

    /** @return list<array<string, mixed>> */
    public function awardsForPlayer(string $playerId): array
    {
        if (!$this->available()) { return []; }
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::AWARDS . ' WHERE winner_player_id = :player_id ORDER BY season_id ASC, competition_id ASC, award_type ASC');
        $statement->execute(['player_id' => $playerId]);

        return $this->hydrate($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    public function awardsForSeason(string $seasonId): array
    {
        if (!$this->available()) { return []; }
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::AWARDS . ' WHERE season_id = :season_id ORDER BY competition_id ASC, award_type ASC, winner_player_id ASC');
        $statement->execute(['season_id' => $seasonId]);

        return $this->hydrate($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $row */
    public function saveHonourInTransaction(array $row): bool
    {
        $statement = $this->database->connection()->prepare('INSERT OR IGNORE INTO ' . self::HONOURS . ' (source_key, player_id, season_id, competition_id, honour_type, holder_id, label, earned_date, evidence_json) VALUES (:source_key, :player_id, :season_id, :competition_id, :honour_type, :holder_id, :label, :earned_date, :evidence_json)');

        return $statement->execute($this->jsonRow($row)) && $statement->rowCount() > 0;
    }

    /** @return list<array<string, mixed>> */
    public function honoursForPlayer(string $playerId): array
    {
        if (!$this->available()) { return []; }
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::HONOURS . ' WHERE player_id = :player_id ORDER BY season_id ASC, honour_type ASC, competition_id ASC');
        $statement->execute(['player_id' => $playerId]);

        return $this->hydrate($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function record(string $playerId, string $metric): ?array
    {
        if (!$this->available()) { return null; }
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::RECORDS . ' WHERE player_id = :player_id AND metric = :metric');
        $statement->execute(['player_id' => $playerId, 'metric' => $metric]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate([$row])[0] : null;
    }

    /** @return list<array<string, mixed>> */
    public function recordsForPlayer(string $playerId): array
    {
        if (!$this->available()) { return []; }
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::RECORDS . ' WHERE player_id = :player_id ORDER BY metric ASC');
        $statement->execute(['player_id' => $playerId]);

        return $this->hydrate($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $row */
    public function saveRecordInTransaction(array $row): void
    {
        $statement = $this->database->connection()->prepare('INSERT INTO ' . self::RECORDS . ' (player_id, metric, value, season_id, club_id, updated_date, evidence_json) VALUES (:player_id, :metric, :value, :season_id, :club_id, :updated_date, :evidence_json) ON CONFLICT(player_id, metric) DO UPDATE SET value = excluded.value, season_id = excluded.season_id, club_id = excluded.club_id, updated_date = excluded.updated_date, evidence_json = excluded.evidence_json');
        $statement->execute($this->jsonRow($row));
    }

    /** @param array<string, mixed> $row */
    public function saveMilestoneInTransaction(array $row): bool
    {
        $statement = $this->database->connection()->prepare('INSERT OR IGNORE INTO ' . self::MILESTONES . ' (source_key, player_id, season_id, metric, threshold, label, occurred_date, evidence_json) VALUES (:source_key, :player_id, :season_id, :metric, :threshold, :label, :occurred_date, :evidence_json)');

        return $statement->execute($this->jsonRow($row)) && $statement->rowCount() > 0;
    }

    /** @return list<array<string, mixed>> */
    public function milestonesForPlayer(string $playerId): array
    {
        if (!$this->available()) { return []; }
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::MILESTONES . ' WHERE player_id = :player_id ORDER BY occurred_date ASC, source_key ASC');
        $statement->execute(['player_id' => $playerId]);

        return $this->hydrate($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function jsonRow(array $row): array
    {
        $row['evidence_json'] = is_string($row['evidence_json'] ?? null)
            ? $row['evidence_json']
            : json_encode($row['evidence'] ?? [], JSON_THROW_ON_ERROR);
        unset($row['evidence']);

        return $row;
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function hydrate(array $rows): array
    {
        return array_map(static function (array $row): array {
            foreach (['value', 'threshold'] as $key) {
                if (array_key_exists($key, $row)) { $row[$key] = (float) $row[$key]; }
            }
            if (isset($row['evidence_json'])) {
                $decoded = json_decode((string) $row['evidence_json'], true);
                $row['evidence'] = is_array($decoded) ? $decoded : [];
                unset($row['evidence_json']);
            }

            return $row;
        }, $rows);
    }
}
