<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\CareerTransferRequestStatus;
use Goal\Legacy\Modules\Player\Domain\OnPitchRole;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\PlayerException;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\SeasonId;
final class CareerPlayerRepository
{
    private const TABLE = 'career_player_references';

    public function __construct(private readonly DatabaseInterface $database)
    {
        $this->database->connection()->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'career_id TEXT PRIMARY KEY, '
            . 'player_id TEXT NOT NULL UNIQUE, '
            . 'start_date TEXT NOT NULL, '
            . 'transfer_request_status TEXT NOT NULL DEFAULT \'none\', '
            . 'transfer_request_season_id TEXT NULL, '
            . 'preferred_on_pitch_role TEXT NULL'
            . ')'
        );
        $columns = $this->database->connection()->query('PRAGMA table_info(' . self::TABLE . ')')->fetchAll(\PDO::FETCH_ASSOC);
        if (!in_array('transfer_request_status', array_column($columns, 'name'), true)) {
            $this->database->connection()->exec("ALTER TABLE " . self::TABLE . " ADD COLUMN transfer_request_status TEXT NOT NULL DEFAULT 'none'");
        }
        if (!in_array('transfer_request_season_id', array_column($columns, 'name'), true)) {
            $this->database->connection()->exec('ALTER TABLE ' . self::TABLE . ' ADD COLUMN transfer_request_season_id TEXT NULL');
        }
        if (!in_array('preferred_on_pitch_role', array_column($columns, 'name'), true)) {
            $this->database->connection()->exec('ALTER TABLE ' . self::TABLE . ' ADD COLUMN preferred_on_pitch_role TEXT NULL');
        }
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_career_player_player ON ' . self::TABLE . ' (player_id)');
    }

    public function save(CareerPlayerReference $reference): void
    {
        $this->assertPlayer($reference);
        $values = $reference->toArray();
        $values['transfer_request_status'] = $reference->transferRequestStatus()->value;
        $values['transfer_request_season_id'] = $reference->transferRequestSeasonId()?->value();
        $values['preferred_on_pitch_role'] = $reference->preferredOnPitchRole()?->value;
        $statement = $this->database->connection()->prepare(
            'INSERT INTO ' . self::TABLE . ' (career_id, player_id, start_date, transfer_request_status, transfer_request_season_id, preferred_on_pitch_role) VALUES (:career_id, :player_id, :start_date, :transfer_request_status, :transfer_request_season_id, :preferred_on_pitch_role) '
            . 'ON CONFLICT(career_id) DO UPDATE SET player_id = excluded.player_id, start_date = excluded.start_date, transfer_request_status = excluded.transfer_request_status, transfer_request_season_id = excluded.transfer_request_season_id, preferred_on_pitch_role = excluded.preferred_on_pitch_role'
        );
        try {
            $statement->execute($values);
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
            CareerTransferRequestStatus::from((string) ($row['transfer_request_status'] ?? CareerTransferRequestStatus::None->value)),
            $row['transfer_request_season_id'] === null ? null : new SeasonId((string) $row['transfer_request_season_id']),
            OnPitchRole::tryFrom((string) ($row['preferred_on_pitch_role'] ?? '')),
        );
    }

    public function byPlayer(string|PlayerId $id): ?CareerPlayerReference
    {
        $playerId = $id instanceof PlayerId ? $id : new PlayerId($id);
        $statement = $this->database->connection()->prepare('SELECT career_id FROM ' . self::TABLE . ' WHERE player_id = :player_id');
        $statement->execute(['player_id' => $playerId->value()]);
        $careerId = $statement->fetchColumn();

        return $careerId === false ? null : $this->get((string) $careerId);
    }

    /** @return list<string> */
    public function requestedPlayerIds(SeasonId $seasonId): array
    {
        $statement = $this->database->connection()->prepare('SELECT player_id FROM ' . self::TABLE . ' WHERE transfer_request_status = :status AND transfer_request_season_id = :season_id ORDER BY player_id ASC');
        $statement->execute(['status' => CareerTransferRequestStatus::Requested->value, 'season_id' => $seasonId->value()]);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
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
