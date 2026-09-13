<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Club\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Domain\Club;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\ClubNotFoundException;
use Goal\Legacy\Modules\Nation\Domain\NationId;
use PDO;

final class ClubRepository
{
    private const TABLE = 'club_records';

    public function __construct(private readonly DatabaseInterface $database)
    {
        $this->database->connection()->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'id TEXT PRIMARY KEY, '
            . 'canonical_name TEXT NOT NULL, '
            . 'short_name TEXT NOT NULL, '
            . 'nickname TEXT NULL, '
            . 'nation_id TEXT NOT NULL, '
            . 'city TEXT NOT NULL, '
            . 'founded_year INTEGER NOT NULL, '
            . 'stadium_name TEXT NOT NULL, '
            . 'club_colors TEXT NOT NULL, '
            . 'core_philosophy TEXT NOT NULL, '
            . 'football_identity TEXT NOT NULL, '
            . 'current_style TEXT NOT NULL, '
            . 'reputation INTEGER NOT NULL, '
            . 'facilities_level INTEGER NOT NULL, '
            . 'source_package_id TEXT NOT NULL, '
            . 'source_package_version TEXT NOT NULL, '
            . 'source_schema_version INTEGER NOT NULL'
            . ')'
        );
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_club_records_nation_id ON ' . self::TABLE . ' (nation_id, id)');
    }

    public function save(Club $club): void
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO ' . self::TABLE . ' '
            . '(id, canonical_name, short_name, nickname, nation_id, city, founded_year, stadium_name, club_colors, core_philosophy, football_identity, current_style, reputation, facilities_level, source_package_id, source_package_version, source_schema_version) '
            . 'VALUES (:id, :canonical_name, :short_name, :nickname, :nation_id, :city, :founded_year, :stadium_name, :club_colors, :core_philosophy, :football_identity, :current_style, :reputation, :facilities_level, :source_package_id, :source_package_version, :source_schema_version) '
            . 'ON CONFLICT(id) DO UPDATE SET '
            . 'canonical_name = excluded.canonical_name, short_name = excluded.short_name, nickname = excluded.nickname, '
            . 'nation_id = excluded.nation_id, city = excluded.city, founded_year = excluded.founded_year, '
            . 'stadium_name = excluded.stadium_name, club_colors = excluded.club_colors, core_philosophy = excluded.core_philosophy, '
            . 'football_identity = excluded.football_identity, current_style = excluded.current_style, reputation = excluded.reputation, '
            . 'facilities_level = excluded.facilities_level, source_package_id = excluded.source_package_id, '
            . 'source_package_version = excluded.source_package_version, source_schema_version = excluded.source_schema_version'
        );
        $statement->execute($club->toArray());
    }

    public function exists(string|ClubId $id): bool
    {
        $clubId = $id instanceof ClubId ? $id : new ClubId($id);
        $statement = $this->database->connection()->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE id = :id');
        $statement->execute(['id' => $clubId->value()]);

        return $statement->fetchColumn() !== false;
    }

    public function get(string|ClubId $id): Club
    {
        $clubId = $id instanceof ClubId ? $id : new ClubId($id);
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id');
        $statement->execute(['id' => $clubId->value()]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ClubNotFoundException($clubId->value());
        }

        return $this->hydrate($row);
    }

    /** @return list<Club> */
    public function all(): array
    {
        return $this->hydrateRows($this->database->connection()->query('SELECT * FROM ' . self::TABLE . ' ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<Club> */
    public function byNation(string|NationId $id): array
    {
        $nationId = $id instanceof NationId ? $id : new NationId($id);
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE nation_id = :nation_id ORDER BY id ASC');
        $statement->execute(['nation_id' => $nationId->value()]);

        return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<array<string, mixed>> $rows @return list<Club> */
    private function hydrateRows(array $rows): array
    {
        return array_map(fn (array $row): Club => $this->hydrate($row), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Club
    {
        return new Club(
            new ClubId((string) $row['id']),
            (string) $row['canonical_name'],
            (string) $row['short_name'],
            $row['nickname'] === null ? null : (string) $row['nickname'],
            new NationId((string) $row['nation_id']),
            (string) $row['city'],
            (int) $row['founded_year'],
            (string) $row['stadium_name'],
            (string) $row['club_colors'],
            (string) $row['core_philosophy'],
            (string) $row['football_identity'],
            (string) $row['current_style'],
            (int) $row['reputation'],
            (int) $row['facilities_level'],
            (string) $row['source_package_id'],
            (string) $row['source_package_version'],
            (int) $row['source_schema_version'],
        );
    }
}
