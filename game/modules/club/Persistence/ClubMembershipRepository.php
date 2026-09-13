<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Club\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Domain\ClubCompetitionMembership;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\ClubMembershipException;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use PDO;
use PDOException;

final class ClubMembershipRepository
{
    private const TABLE = 'club_competition_memberships';

    public function __construct(private readonly DatabaseInterface $database)
    {
        $this->database->connection()->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'club_id TEXT NOT NULL, '
            . 'competition_id TEXT NOT NULL, '
            . 'season_id TEXT NOT NULL, '
            . 'PRIMARY KEY (season_id, competition_id, club_id)'
            . ')'
        );
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_club_memberships_club ON ' . self::TABLE . ' (club_id, season_id, competition_id)');
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_club_memberships_competition ON ' . self::TABLE . ' (competition_id, season_id, club_id)');
    }

    public function save(ClubCompetitionMembership $membership): void
    {
        $this->assertReferences($membership);
        try {
            $statement = $this->database->connection()->prepare(
                'INSERT INTO ' . self::TABLE . ' (club_id, competition_id, season_id) VALUES (:club_id, :competition_id, :season_id)'
            );
            $statement->execute($membership->toArray());
        } catch (PDOException $exception) {
            throw new ClubMembershipException(sprintf('Club membership "%s" already exists or could not be saved.', $membership->key()), 0, $exception);
        }
    }

    public function exists(ClubCompetitionMembership $membership): bool
    {
        $statement = $this->database->connection()->prepare(
            'SELECT 1 FROM ' . self::TABLE . ' WHERE club_id = :club_id AND competition_id = :competition_id AND season_id = :season_id'
        );
        $statement->execute($membership->toArray());

        return $statement->fetchColumn() !== false;
    }

    /** @return list<ClubCompetitionMembership> */
    public function all(): array
    {
        $rows = $this->database->connection()->query('SELECT * FROM ' . self::TABLE . ' ORDER BY season_id ASC, competition_id ASC, club_id ASC')->fetchAll(PDO::FETCH_ASSOC);

        return $this->hydrateRows($rows);
    }

    /** @return list<ClubCompetitionMembership> */
    public function byClub(string|ClubId $id): array
    {
        $clubId = $id instanceof ClubId ? $id : new ClubId($id);
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE club_id = :club_id ORDER BY season_id ASC, competition_id ASC');
        $statement->execute(['club_id' => $clubId->value()]);

        return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<ClubCompetitionMembership> */
    public function byCompetition(string|CompetitionId $id, ?SeasonId $seasonId = null): array
    {
        $competitionId = $id instanceof CompetitionId ? $id : new CompetitionId($id);
        $sql = 'SELECT * FROM ' . self::TABLE . ' WHERE competition_id = :competition_id';
        $parameters = ['competition_id' => $competitionId->value()];
        if ($seasonId !== null) {
            $sql .= ' AND season_id = :season_id';
            $parameters['season_id'] = $seasonId->value();
        }
        $sql .= ' ORDER BY season_id ASC, club_id ASC';
        $statement = $this->database->connection()->prepare($sql);
        $statement->execute($parameters);

        return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<ClubCompetitionMembership> */
    public function bySeason(string|SeasonId $id): array
    {
        $seasonId = $id instanceof SeasonId ? $id : new SeasonId($id);
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE season_id = :season_id ORDER BY competition_id ASC, club_id ASC');
        $statement->execute(['season_id' => $seasonId->value()]);

        return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<array<string, mixed>> $rows @return list<ClubCompetitionMembership> */
    private function hydrateRows(array $rows): array
    {
        return array_map(static fn (array $row): ClubCompetitionMembership => new ClubCompetitionMembership(
            new ClubId((string) $row['club_id']),
            new CompetitionId((string) $row['competition_id']),
            new SeasonId((string) $row['season_id']),
        ), $rows);
    }

    private function assertReferences(ClubCompetitionMembership $membership): void
    {
        foreach ([
            ['club_records', 'id', $membership->clubId()->value(), 'Club'],
            ['competition_records', 'id', $membership->competitionId()->value(), 'Competition'],
            ['season_records', 'id', $membership->seasonId()->value(), 'Season'],
        ] as [$table, $column, $value, $label]) {
            $statement = $this->database->connection()->prepare('SELECT 1 FROM ' . $table . ' WHERE ' . $column . ' = :value');
            $statement->execute(['value' => $value]);
            if ($statement->fetchColumn() === false) {
                throw new ClubMembershipException(sprintf('Club membership references missing %s "%s".', $label, $value));
            }
        }
    }
}
