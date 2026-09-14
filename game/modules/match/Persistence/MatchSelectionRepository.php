<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\PlayerSelection;
use Goal\Legacy\Modules\Match\Domain\SelectionStatus;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use PDO;

final class MatchSelectionRepository
{
    private const TABLE = 'match_player_selections';

    public function __construct(private readonly DatabaseInterface $database)
    {
        SchemaInitializationGuard::run($this->database->connection(), self::class, function (): void {
            $this->database->connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (match_id TEXT NOT NULL, player_id TEXT NOT NULL, club_id TEXT NOT NULL, status TEXT NOT NULL, PRIMARY KEY (match_id, player_id))');
            $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_match_selection_player ON ' . self::TABLE . ' (player_id, match_id)');
            $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_match_selection_club_status ON ' . self::TABLE . ' (club_id, status, match_id)');
            $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_match_selection_match_order ON ' . self::TABLE . ' (match_id, club_id, status, player_id)');
        });
    }

    /** @param list<PlayerSelection> $selections */
    public function replaceForMatchInTransaction(array $selections): void
    {
        if ($selections === []) { return; }
        $matchId = $selections[0]->matchId()->value();
        $this->database->connection()->prepare('DELETE FROM ' . self::TABLE . ' WHERE match_id = :match_id')->execute(['match_id' => $matchId]);
        $statement = $this->database->connection()->prepare('INSERT INTO ' . self::TABLE . ' (match_id, player_id, club_id, status) VALUES (:match_id, :player_id, :club_id, :status)');
        foreach ($selections as $selection) { $statement->execute($selection->toArray()); }
    }

    /** @return list<PlayerSelection> */
    public function byMatch(string|MatchId $id): array
    {
        $matchId = $id instanceof MatchId ? $id : new MatchId($id);
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE match_id = :match_id ORDER BY club_id ASC, status ASC, player_id ASC'); $statement->execute(['match_id' => $matchId->value()]);

        return $this->hydrate($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<PlayerSelection> */
    public function byPlayer(string|PlayerId $id): array
    {
        $playerId = $id instanceof PlayerId ? $id : new PlayerId($id);
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE player_id = :player_id ORDER BY match_id ASC'); $statement->execute(['player_id' => $playerId->value()]);

        return $this->hydrate($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<array<string, mixed>> $rows @return list<PlayerSelection> */
    private function hydrate(array $rows): array
    {
        return array_map(static fn (array $row): PlayerSelection => new PlayerSelection(new MatchId((string) $row['match_id']), new PlayerId((string) $row['player_id']), new ClubId((string) $row['club_id']), SelectionStatus::from((string) $row['status'])), $rows);
    }
}
