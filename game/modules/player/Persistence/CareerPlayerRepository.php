<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\PlayerException;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
final class CareerPlayerRepository
{
    private const TABLE = 'career_player_references';

    public function __construct(private readonly DatabaseInterface $database)
    {
        $this->database->connection()->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'career_id TEXT PRIMARY KEY, '
            . 'player_id TEXT NOT NULL UNIQUE, '
            . 'start_date TEXT NOT NULL'
            . ')'
        );
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_career_player_player ON ' . self::TABLE . ' (player_id)');
    }

    public function save(CareerPlayerReference $reference): void
    {
        $this->assertPlayer($reference);
        $statement = $this->database->connection()->prepare(
            'INSERT INTO ' . self::TABLE . ' (career_id, player_id, start_date) VALUES (:career_id, :player_id, :start_date) '
            . 'ON CONFLICT(career_id) DO UPDATE SET player_id = excluded.player_id, start_date = excluded.start_date'
        );
        try {
            $statement->execute($reference->toArray());
        } catch (\PDOException $exception) {
            throw new PlayerException(sprintf('Career "%s" already controls another Player.', $reference->careerId()->value()), 0, $exception);
        }
    }

    public function get(string|CareerId $id): CareerPlayerReference
    {
        $careerId = $id instanceof CareerId ? $id : new CareerId($id);
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE career_id = :career_id');
        $statement->execute(['career_id' => $careerId->value()]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new PlayerException(sprintf('Career "%s" has no controlled Player.', $careerId->value()));
        }

        return new CareerPlayerReference(
            $careerId,
            new PlayerId((string) $row['player_id']),
            SimulationDate::fromIsoString((string) $row['start_date']),
        );
    }

    public function exists(string|CareerId $id): bool
    {
        $careerId = $id instanceof CareerId ? $id : new CareerId($id);
        $statement = $this->database->connection()->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE career_id = :career_id');
        $statement->execute(['career_id' => $careerId->value()]);

        return $statement->fetchColumn() !== false;
    }

    /** @return list<string> */
    public function playerIds(): array
    {
        return array_map('strval', $this->database->connection()->query('SELECT player_id FROM ' . self::TABLE . ' ORDER BY player_id ASC')->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function assertPlayer(CareerPlayerReference $reference): void
    {
        $statement = $this->database->connection()->prepare('SELECT 1 FROM player_records WHERE id = :player_id');
        $statement->execute(['player_id' => $reference->playerId()->value()]);
        if ($statement->fetchColumn() === false) {
            throw new PlayerException(sprintf('Career reference points to missing Player "%s".', $reference->playerId()->value()));
        }
    }
}
