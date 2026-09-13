<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Club\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\SquadMembershipException;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use PDO;
use PDOException;

final class ClubSquadRepository
{
    private const TABLE = 'club_squad_memberships';

    public function __construct(private readonly DatabaseInterface $database)
    {
        $this->database->connection()->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'club_id TEXT NOT NULL, '
            . 'player_id TEXT NOT NULL, '
            . 'season_id TEXT NOT NULL, '
            . 'PRIMARY KEY (season_id, club_id, player_id), '
            . 'UNIQUE (season_id, player_id)'
            . ')'
        );
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_club_squad_club ON ' . self::TABLE . ' (club_id, season_id, player_id)');
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_club_squad_player ON ' . self::TABLE . ' (player_id, season_id, club_id)');
    }

    public function save(ClubSquadMembership $membership): void
    {
        $this->assertReferences($membership);
        try {
            $statement = $this->database->connection()->prepare(
                'INSERT INTO ' . self::TABLE . ' (club_id, player_id, season_id) VALUES (:club_id, :player_id, :season_id)'
            );
            $statement->execute($membership->toArray());
        } catch (PDOException $exception) {
            throw new SquadMembershipException(sprintf('Squad membership "%s" already exists or conflicts with another Club.', $membership->key()), 0, $exception);
        }
    }

    public function exists(ClubSquadMembership $membership): bool
    {
        $statement = $this->database->connection()->prepare(
            'SELECT 1 FROM ' . self::TABLE . ' WHERE club_id = :club_id AND player_id = :player_id AND season_id = :season_id'
        );
        $statement->execute($membership->toArray());

        return $statement->fetchColumn() !== false;
    }

    /** @return list<ClubSquadMembership> */
    public function all(): array
    {
        $rows = $this->database->connection()->query('SELECT * FROM ' . self::TABLE . ' ORDER BY season_id ASC, club_id ASC, player_id ASC')->fetchAll(PDO::FETCH_ASSOC);

        return $this->hydrateRows($rows);
    }

    /** @return list<ClubSquadMembership> */
    public function byClub(string|ClubId $id, ?SeasonId $seasonId = null): array
    {
        $clubId = $id instanceof ClubId ? $id : new ClubId($id);
        return $this->byColumn('club_id', $clubId->value(), $seasonId, 'player_id');
    }

    /** @return list<ClubSquadMembership> */
    public function byPlayer(string|PlayerId $id, ?SeasonId $seasonId = null): array
    {
        $playerId = $id instanceof PlayerId ? $id : new PlayerId($id);
        return $this->byColumn('player_id', $playerId->value(), $seasonId, 'club_id');
    }

    /** @return list<ClubSquadMembership> */
    private function byColumn(string $column, string $value, ?SeasonId $seasonId, string $orderColumn): array
    {
        $sql = 'SELECT * FROM ' . self::TABLE . ' WHERE ' . $column . ' = :value';
        $parameters = ['value' => $value];
        if ($seasonId !== null) {
            $sql .= ' AND season_id = :season_id';
            $parameters['season_id'] = $seasonId->value();
        }
        $sql .= ' ORDER BY season_id ASC, ' . $orderColumn . ' ASC';
        $statement = $this->database->connection()->prepare($sql);
        $statement->execute($parameters);

        return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<array<string, mixed>> $rows @return list<ClubSquadMembership> */
    private function hydrateRows(array $rows): array
    {
        return array_map(static fn (array $row): ClubSquadMembership => new ClubSquadMembership(
            new ClubId((string) $row['club_id']),
            new PlayerId((string) $row['player_id']),
            new SeasonId((string) $row['season_id']),
        ), $rows);
    }

    private function assertReferences(ClubSquadMembership $membership): void
    {
        foreach ([
            ['club_records', 'id', $membership->clubId()->value(), 'Club'],
            ['player_records', 'id', $membership->playerId()->value(), 'Player'],
            ['season_records', 'id', $membership->seasonId()->value(), 'Season'],
        ] as [$table, $column, $value, $label]) {
            $statement = $this->database->connection()->prepare('SELECT 1 FROM ' . $table . ' WHERE ' . $column . ' = :value');
            $statement->execute(['value' => $value]);
            if ($statement->fetchColumn() === false) {
                throw new SquadMembershipException(sprintf('Squad membership references missing %s "%s".', $label, $value));
            }
        }
    }
}
