<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Club\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use PDO;

/**
 * Stores only durable objective outcomes for controlled Careers.
 *
 * The current Club objective is a read model derived by ClubSeasonObjectiveService.
 * This table exists so a completed Season keeps its original expectation after
 * standings and squad detail are compacted. NPC Clubs never write here.
 */
final class ClubSeasonObjectiveRepository
{
    private const TABLE = 'career_club_season_objectives';

    public function __construct(private readonly DatabaseInterface $database)
    {
        SchemaInitializationGuard::run($this->database->connection(), self::class, function (): void {
            $this->database->connection()->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                . 'player_id TEXT NOT NULL, season_id TEXT NOT NULL, club_id TEXT NOT NULL, '
                . 'competition_id TEXT NOT NULL, objective TEXT NOT NULL, cup_objective TEXT NULL, '
                . 'europe_objective TEXT NULL, outcome TEXT NULL, resolved_date TEXT NULL, '
                . 'evidence_json TEXT NOT NULL, PRIMARY KEY (player_id, season_id, club_id))'
            );
            $this->database->connection()->exec(
                'CREATE INDEX IF NOT EXISTS idx_career_club_objectives_player '
                . 'ON ' . self::TABLE . ' (player_id, season_id, club_id)'
            );
        });
    }

    /** @return array<string, mixed>|null */
    public function find(string $playerId, string $seasonId, string $clubId): ?array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE player_id = :player_id AND season_id = :season_id AND club_id = :club_id'
        );
        $statement->execute(['player_id' => $playerId, 'season_id' => $seasonId, 'club_id' => $clubId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        $evidence = json_decode((string) ($row['evidence_json'] ?? '{}'), true);
        $row['evidence'] = is_array($evidence) ? $evidence : [];
        unset($row['evidence_json']);

        return $row;
    }

    /** @param array<string, mixed> $evidence */
    public function saveOutcome(
        string $playerId,
        string $seasonId,
        string $clubId,
        string $competitionId,
        string $objective,
        ?string $cupObjective,
        ?string $europeObjective,
        ?string $outcome,
        ?string $resolvedDate,
        array $evidence,
    ): void {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO ' . self::TABLE . ' '
            . '(player_id, season_id, club_id, competition_id, objective, cup_objective, europe_objective, outcome, resolved_date, evidence_json) '
            . 'VALUES (:player_id, :season_id, :club_id, :competition_id, :objective, :cup_objective, :europe_objective, :outcome, :resolved_date, :evidence_json) '
            . 'ON CONFLICT(player_id, season_id, club_id) DO UPDATE SET '
            . 'competition_id = excluded.competition_id, objective = excluded.objective, '
            . 'cup_objective = COALESCE(excluded.cup_objective, ' . self::TABLE . '.cup_objective), '
            . 'europe_objective = COALESCE(excluded.europe_objective, ' . self::TABLE . '.europe_objective), '
            . 'outcome = COALESCE(excluded.outcome, ' . self::TABLE . '.outcome), '
            . 'resolved_date = COALESCE(excluded.resolved_date, ' . self::TABLE . '.resolved_date), '
            . 'evidence_json = excluded.evidence_json'
        );
        $statement->execute([
            'player_id' => $playerId,
            'season_id' => $seasonId,
            'club_id' => $clubId,
            'competition_id' => $competitionId,
            'objective' => $objective,
            'cup_objective' => $cupObjective,
            'europe_objective' => $europeObjective,
            'outcome' => $outcome,
            'resolved_date' => $resolvedDate,
            'evidence_json' => json_encode($evidence, JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function byPlayer(string $playerId): array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE player_id = :player_id ORDER BY season_id ASC, club_id ASC'
        );
        $statement->execute(['player_id' => $playerId]);

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $evidence = json_decode((string) ($row['evidence_json'] ?? '{}'), true);
            $row['evidence'] = is_array($evidence) ? $evidence : [];
            unset($row['evidence_json']);
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return list<string> */
    public function integrity(): array
    {
        $issues = [];
        $duplicates = (int) $this->database->connection()->query(
            'SELECT COUNT(*) FROM (SELECT player_id, season_id, club_id, COUNT(*) AS c FROM ' . self::TABLE
            . ' GROUP BY player_id, season_id, club_id HAVING c > 1)'
        )->fetchColumn();
        if ($duplicates > 0) {
            $issues[] = 'duplicate controlled Club Season objective rows';
        }

        return $issues;
    }
}
