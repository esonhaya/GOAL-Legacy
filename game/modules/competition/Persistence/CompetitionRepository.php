<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Competition\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Competition\Domain\Competition;
use Goal\Legacy\Modules\Competition\Domain\CompetitionDefinition;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\CompetitionNotFoundException;
use Goal\Legacy\Modules\Competition\Domain\CompetitionStatus;
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
use Goal\Legacy\Modules\Nation\Domain\NationId;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use PDO;

final class CompetitionRepository
{
    private const TABLE = 'competition_records';

    public function __construct(private readonly DatabaseInterface $database)
    {
        $this->database->connection()->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'id TEXT PRIMARY KEY, '
            . 'name TEXT NOT NULL, '
            . 'short_name TEXT NOT NULL, '
            . 'type TEXT NOT NULL, '
            . 'nation_id TEXT NOT NULL, '
            . 'season_id TEXT NULL, '
            . 'status TEXT NOT NULL, '
            . 'source_package_id TEXT NOT NULL, '
            . 'source_package_version TEXT NOT NULL, '
            . 'source_schema_version INTEGER NOT NULL, '
            . 'maximum_substitutions INTEGER NOT NULL DEFAULT 5'
            . ')'
        );
        $columns = $this->database->connection()->query('PRAGMA table_info(' . self::TABLE . ')')->fetchAll(PDO::FETCH_ASSOC);
        if (!in_array('maximum_substitutions', array_column($columns, 'name'), true)) {
            $this->database->connection()->exec('ALTER TABLE ' . self::TABLE . ' ADD COLUMN maximum_substitutions INTEGER NOT NULL DEFAULT 5');
        }
    }

    public function save(Competition $competition): void
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO ' . self::TABLE . ' '
            . '(id, name, short_name, type, nation_id, season_id, status, source_package_id, source_package_version, source_schema_version, maximum_substitutions) '
            . 'VALUES (:id, :name, :short_name, :type, :nation_id, :season_id, :status, :source_package_id, :source_package_version, :source_schema_version, :maximum_substitutions) '
            . 'ON CONFLICT(id) DO UPDATE SET '
            . 'name = excluded.name, short_name = excluded.short_name, type = excluded.type, '
            . 'nation_id = excluded.nation_id, season_id = excluded.season_id, status = excluded.status, '
            . 'source_package_id = excluded.source_package_id, source_package_version = excluded.source_package_version, '
            . 'source_schema_version = excluded.source_schema_version, maximum_substitutions = excluded.maximum_substitutions'
        );
        $statement->execute($competition->toArray());
    }

    public function exists(string|CompetitionId $id): bool
    {
        $competitionId = $id instanceof CompetitionId ? $id : new CompetitionId($id);
        $statement = $this->database->connection()->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE id = :id');
        $statement->execute(['id' => $competitionId->value()]);

        return $statement->fetchColumn() !== false;
    }

    public function get(string|CompetitionId $id): Competition
    {
        $competitionId = $id instanceof CompetitionId ? $id : new CompetitionId($id);
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id');
        $statement->execute(['id' => $competitionId->value()]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new CompetitionNotFoundException($competitionId->value());
        }

        return $this->hydrate($row);
    }

    /** @return list<Competition> */
    public function all(): array
    {
        return $this->hydrateRows($this->database->connection()->query('SELECT * FROM ' . self::TABLE . ' ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<Competition> */
    public function byNation(string|NationId $id): array
    {
        $nationId = $id instanceof NationId ? $id : new NationId($id);
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE nation_id = :nation_id ORDER BY id ASC');
        $statement->execute(['nation_id' => $nationId->value()]);

        return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<Competition> */
    public function bySeason(string|SeasonId $id): array
    {
        $seasonId = $id instanceof SeasonId ? $id : new SeasonId($id);
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE season_id = :season_id ORDER BY id ASC');
        $statement->execute(['season_id' => $seasonId->value()]);

        return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<array<string, mixed>> $rows @return list<Competition> */
    private function hydrateRows(array $rows): array
    {
        return array_map(fn (array $row): Competition => $this->hydrate($row), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Competition
    {
        $definition = new CompetitionDefinition(
            new CompetitionId((string) $row['id']),
            (string) $row['name'],
            (string) $row['short_name'],
            CompetitionType::from((string) $row['type']),
            new NationId((string) $row['nation_id']),
            (string) $row['source_package_id'],
            (string) $row['source_package_version'],
            (int) $row['source_schema_version'],
            (int) ($row['maximum_substitutions'] ?? 5),
        );

        return new Competition(
            $definition,
            CompetitionStatus::from((string) $row['status']),
            $row['season_id'] === null ? null : new SeasonId((string) $row['season_id']),
        );
    }
}
