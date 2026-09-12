<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\World\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SeasonNotFoundException;
use Goal\Legacy\Modules\World\Domain\SeasonStatus;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PDO;

final class SeasonRepository
{
    private const TABLE = 'season_records';

    public function __construct(private readonly DatabaseInterface $database)
    {
        $this->database->connection()->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'id TEXT PRIMARY KEY, '
            . 'label TEXT NOT NULL, '
            . 'start_date TEXT NOT NULL, '
            . 'end_date TEXT NOT NULL, '
            . 'status TEXT NOT NULL'
            . ')'
        );
    }

    public function save(Season $season): void
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO ' . self::TABLE . ' (id, label, start_date, end_date, status) '
            . 'VALUES (:id, :label, :start_date, :end_date, :status) '
            . 'ON CONFLICT(id) DO UPDATE SET '
            . 'label = excluded.label, start_date = excluded.start_date, '
            . 'end_date = excluded.end_date, status = excluded.status'
        );
        $statement->execute($season->toArray());
    }

    public function exists(string|SeasonId $id): bool
    {
        $seasonId = $id instanceof SeasonId ? $id : new SeasonId($id);
        $statement = $this->database->connection()->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE id = :id');
        $statement->execute(['id' => $seasonId->value()]);

        return $statement->fetchColumn() !== false;
    }

    public function get(string|SeasonId $id): Season
    {
        $seasonId = $id instanceof SeasonId ? $id : new SeasonId($id);
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id');
        $statement->execute(['id' => $seasonId->value()]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new SeasonNotFoundException($seasonId->value());
        }

        return new Season(
            new SeasonId((string) $row['id']),
            (string) $row['label'],
            SimulationDate::fromIsoString((string) $row['start_date']),
            SimulationDate::fromIsoString((string) $row['end_date']),
            SeasonStatus::from((string) $row['status']),
        );
    }

    /** @return list<Season> */
    public function all(): array
    {
        $rows = $this->database->connection()->query('SELECT * FROM ' . self::TABLE . ' ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): Season => new Season(
            new SeasonId((string) $row['id']),
            (string) $row['label'],
            SimulationDate::fromIsoString((string) $row['start_date']),
            SimulationDate::fromIsoString((string) $row['end_date']),
            SeasonStatus::from((string) $row['status']),
        ), $rows);
    }
}
