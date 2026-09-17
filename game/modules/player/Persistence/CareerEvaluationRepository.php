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

        return $rows[0] ?? null;
    }
}
