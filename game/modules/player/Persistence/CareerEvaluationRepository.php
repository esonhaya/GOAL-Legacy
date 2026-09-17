<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use PDO;

final class CareerEvaluationRepository
{
    private const TABLE = 'career_match_evaluations';

    public function __construct(private readonly DatabaseInterface $database)
    {
        SchemaInitializationGuard::run($this->database->connection(), self::class, function (): void {
            $this->database->connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (match_id TEXT NOT NULL, player_id TEXT NOT NULL, club_id TEXT NOT NULL, occurred_date TEXT NOT NULL, evaluation_score INTEGER NOT NULL, expectation_status TEXT NOT NULL, PRIMARY KEY (match_id, player_id))');
            $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_career_evaluations_player ON ' . self::TABLE . ' (player_id, occurred_date, match_id)');
            $this->database->connection()->exec('CREATE TABLE IF NOT EXISTS player_form_summaries (player_id TEXT NOT NULL, club_id TEXT NOT NULL, evaluation_count INTEGER NOT NULL, evaluation_total INTEGER NOT NULL, latest_score INTEGER NULL, latest_started INTEGER NULL, previous_score INTEGER NULL, previous_started INTEGER NULL, PRIMARY KEY (player_id, club_id))');
            $columns = $this->database->connection()->query('PRAGMA table_info(player_form_summaries)')->fetchAll(PDO::FETCH_ASSOC);
            $names = array_fill_keys(array_map(static fn (array $column): string => (string) $column['name'], $columns), true);
            foreach (['latest_score' => 'INTEGER NULL', 'latest_started' => 'INTEGER NULL', 'previous_score' => 'INTEGER NULL', 'previous_started' => 'INTEGER NULL'] as $name => $definition) {
                if (!isset($names[$name])) {
                    $this->database->connection()->exec('ALTER TABLE player_form_summaries ADD COLUMN ' . $name . ' ' . $definition);
                }
            }
        });
    }

    public function exists(MatchId $matchId, PlayerId $playerId): bool
    {
        $statement = $this->database->connection()->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE match_id = :match_id AND player_id = :player_id'); $statement->execute(['match_id' => $matchId->value(), 'player_id' => $playerId->value()]);

        return $statement->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $values */
    public function saveInTransaction(array $values): void
    {
        $statement = $this->database->connection()->prepare('INSERT INTO ' . self::TABLE . ' (match_id, player_id, club_id, occurred_date, evaluation_score, expectation_status) VALUES (:match_id, :player_id, :club_id, :occurred_date, :evaluation_score, :expectation_status)');
        $statement->execute($values);
    }

    /** Store only the rolling evaluation signal needed by world selection and role progression. */
    public function saveSummaryInTransaction(array $values): void
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO player_form_summaries (player_id, club_id, evaluation_count, evaluation_total, latest_score, latest_started, previous_score, previous_started) '
            . 'VALUES (:player_id, :club_id, 1, :evaluation_score, :evaluation_score, :started, NULL, NULL) '
            . 'ON CONFLICT(player_id, club_id) DO UPDATE SET '
            . 'evaluation_count = evaluation_count + 1, evaluation_total = evaluation_total + excluded.evaluation_total, '
            . 'previous_score = latest_score, previous_started = latest_started, latest_score = excluded.latest_score, latest_started = excluded.latest_started'
        );
        $statement->execute([
            'player_id' => $values['player_id'],
            'club_id' => $values['club_id'],
            'evaluation_score' => $values['evaluation_score'],
            'started' => $values['started'] ?? 0,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function byPlayer(PlayerId $playerId, ?ClubId $clubId = null): array
    {
        $sql = 'SELECT * FROM ' . self::TABLE . ' WHERE player_id = :player_id'; $params = ['player_id' => $playerId->value()];
        if ($clubId !== null) { $sql .= ' AND club_id = :club_id'; $params['club_id'] = $clubId->value(); }
        $sql .= ' ORDER BY occurred_date DESC, match_id DESC'; $statement = $this->database->connection()->prepare($sql); $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function latest(PlayerId $playerId, ClubId $clubId): ?array
    {
        $rows = $this->byPlayer($playerId, $clubId);
        if ($rows !== []) {
            return $rows[0];
        }
        $statement = $this->database->connection()->prepare('SELECT * FROM player_form_summaries WHERE player_id = :player_id AND club_id = :club_id');
        $statement->execute(['player_id' => $playerId->value(), 'club_id' => $clubId->value()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['latest_score'] === null) {
            return null;
        }
        $row['evaluation_score'] = (int) $row['latest_score'];
        $row['expectation_status'] = 'summary';

        return $row;
    }

    /** @return list<array<string, mixed>> */
    public function recentForPlayer(PlayerId $playerId, ClubId $clubId): array
    {
        $rows = array_slice($this->byPlayer($playerId, $clubId), 0, 2);
        if (count($rows) >= 2) {
            return $rows;
        }
        $statement = $this->database->connection()->prepare('SELECT * FROM player_form_summaries WHERE player_id = :player_id AND club_id = :club_id');
        $statement->execute(['player_id' => $playerId->value(), 'club_id' => $clubId->value()]);
        $summary = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($summary)) {
            return $rows;
        }
        $fallback = [];
        foreach ([['score' => 'latest_score', 'started' => 'latest_started'], ['score' => 'previous_score', 'started' => 'previous_started']] as $entry) {
            if ($summary[$entry['score']] === null) {
                continue;
            }
            $fallback[] = ['evaluation_score' => (int) $summary[$entry['score']], 'started' => (int) ($summary[$entry['started']] ?? 0), 'match_id' => null, 'club_id' => $clubId->value()];
        }

        return array_slice(array_merge($rows, $fallback), 0, 2);
    }
}
