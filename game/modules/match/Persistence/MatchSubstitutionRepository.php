<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\MatchSubstitution;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use PDO;

final class MatchSubstitutionRepository
{
    private const TABLE = 'match_substitutions';

    public function __construct(private readonly DatabaseInterface $database)
    {
        SchemaInitializationGuard::run($this->database->connection(), self::class, function (): void {
            $connection = $this->database->connection();
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (match_id TEXT NOT NULL, club_id TEXT NOT NULL, sequence_number INTEGER NOT NULL, outgoing_player_id TEXT NOT NULL, incoming_player_id TEXT NOT NULL, minute INTEGER NOT NULL, reason TEXT NULL, PRIMARY KEY (match_id, club_id, sequence_number))');
            $columns = $connection->query('PRAGMA table_info(' . self::TABLE . ')')->fetchAll(PDO::FETCH_ASSOC);
            $names = array_fill_keys(array_map(static fn (array $column): string => (string) $column['name'], $columns), true);
            if (!isset($names['reason'])) {
                $connection->exec('ALTER TABLE ' . self::TABLE . ' ADD COLUMN reason TEXT NULL');
            }
        });
    }

    /** @param list<MatchSubstitution> $substitutions */
    public function replaceForMatchInTransaction(array $substitutions): void
    {
        if ($substitutions === []) {
            return;
        }
        $matchId = $substitutions[0]->matchId()->value();
        $this->database->connection()->prepare('DELETE FROM ' . self::TABLE . ' WHERE match_id = :match_id')->execute(['match_id' => $matchId]);
        $statement = $this->database->connection()->prepare('INSERT INTO ' . self::TABLE . ' (match_id, club_id, sequence_number, outgoing_player_id, incoming_player_id, minute, reason) VALUES (:match_id, :club_id, :sequence_number, :outgoing_player_id, :incoming_player_id, :minute, :reason)');
        foreach ($substitutions as $substitution) {
            $statement->execute($substitution->toArray());
        }
    }

    /** @return list<MatchSubstitution> */
    public function byMatch(string|MatchId $id): array
    {
        $matchId = $id instanceof MatchId ? $id : new MatchId($id);
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE match_id = :match_id ORDER BY sequence_number ASC');
        $statement->execute(['match_id' => $matchId->value()]);

        return array_map(static fn (array $row): MatchSubstitution => new MatchSubstitution(
            new MatchId((string) $row['match_id']),
            new ClubId((string) $row['club_id']),
            (int) $row['sequence_number'],
            new PlayerId((string) $row['outgoing_player_id']),
            new PlayerId((string) $row['incoming_player_id']),
            (int) $row['minute'],
            isset($row['reason']) && $row['reason'] !== null && (string) $row['reason'] !== '' ? (string) $row['reason'] : null,
        ), $statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
