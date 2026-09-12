<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Nation\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Nation\Domain\Nation;
use Goal\Legacy\Modules\Nation\Domain\NationId;
use Goal\Legacy\Modules\Nation\Domain\NationNotFoundException;
use PDO;

final class NationRepository
{
    private const TABLE = 'nation_records';

    public function __construct(private readonly DatabaseInterface $database)
    {
        $this->database->connection()->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'id TEXT PRIMARY KEY, '
            . 'canonical_name TEXT NOT NULL, '
            . 'display_name TEXT NOT NULL, '
            . 'code TEXT NULL, '
            . 'region_id TEXT NULL, '
            . 'geography_reference_id TEXT NULL, '
            . 'association_id TEXT NULL, '
            . 'source_package_id TEXT NOT NULL, '
            . 'source_package_version TEXT NOT NULL, '
            . 'source_schema_version INTEGER NOT NULL'
            . ')'
        );
    }

    public function save(Nation $nation): void
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO ' . self::TABLE . ' '
            . '(id, canonical_name, display_name, code, region_id, geography_reference_id, association_id, source_package_id, source_package_version, source_schema_version) '
            . 'VALUES (:id, :canonical_name, :display_name, :code, :region_id, :geography_reference_id, :association_id, :source_package_id, :source_package_version, :source_schema_version) '
            . 'ON CONFLICT(id) DO UPDATE SET '
            . 'canonical_name = excluded.canonical_name, '
            . 'display_name = excluded.display_name, '
            . 'code = excluded.code, '
            . 'region_id = excluded.region_id, '
            . 'geography_reference_id = excluded.geography_reference_id, '
            . 'association_id = excluded.association_id, '
            . 'source_package_id = excluded.source_package_id, '
            . 'source_package_version = excluded.source_package_version, '
            . 'source_schema_version = excluded.source_schema_version'
        );
        $statement->execute($nation->toArray());
    }

    public function find(string|NationId $id): ?Nation
    {
        $nationId = $id instanceof NationId ? $id : new NationId($id);
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id');
        $statement->execute(['id' => $nationId->value()]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function get(string|NationId $id): Nation
    {
        $nationId = $id instanceof NationId ? $id : new NationId($id);
        $nation = $this->find($nationId);
        if ($nation === null) {
            throw new NationNotFoundException($nationId->value());
        }

        return $nation;
    }

    public function exists(string|NationId $id): bool
    {
        $nationId = $id instanceof NationId ? $id : new NationId($id);
        $statement = $this->database->connection()->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE id = :id');
        $statement->execute(['id' => $nationId->value()]);

        return $statement->fetchColumn() !== false;
    }

    /** @return list<Nation> */
    public function all(): array
    {
        $rows = $this->database->connection()->query('SELECT * FROM ' . self::TABLE . ' ORDER BY id ASC')->fetchAll();

        return array_map(fn (array $row): Nation => $this->hydrate($row), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Nation
    {
        return new Nation(
            new NationId((string) $row['id']),
            (string) $row['canonical_name'],
            (string) $row['display_name'],
            $row['code'] === null ? null : (string) $row['code'],
            $row['region_id'] === null ? null : (string) $row['region_id'],
            $row['geography_reference_id'] === null ? null : (string) $row['geography_reference_id'],
            $row['association_id'] === null ? null : (string) $row['association_id'],
            (string) $row['source_package_id'],
            (string) $row['source_package_version'],
            (int) $row['source_schema_version'],
        );
    }
}
