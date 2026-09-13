<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Nation\Domain\NationId;
use Goal\Legacy\Modules\Nation\Persistence\NationRepository;
use Goal\Legacy\Modules\Player\Domain\DevelopmentProfile;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerException;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\PlayerNotFoundException;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Domain\PlayerCareerState;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PDO;

final class PlayerRepository
{
    private const TABLE = 'player_records';

    public function __construct(private readonly DatabaseInterface $database)
    {
        $this->database->connection()->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'id TEXT PRIMARY KEY, '
            . 'first_name TEXT NOT NULL, '
            . 'last_name TEXT NOT NULL, '
            . 'preferred_name TEXT NOT NULL, '
            . 'birth_date TEXT NOT NULL, '
            . 'birth_nation_id TEXT NOT NULL, '
            . 'primary_nation_id TEXT NOT NULL, '
            . 'height_cm INTEGER NOT NULL, '
            . 'weight_kg INTEGER NOT NULL, '
            . 'primary_position TEXT NOT NULL, '
            . 'pace INTEGER NOT NULL, '
            . 'shooting INTEGER NOT NULL, '
            . 'passing INTEGER NOT NULL, '
            . 'dribbling INTEGER NOT NULL, '
            . 'defending INTEGER NOT NULL, '
            . 'physicality INTEGER NOT NULL, '
            . 'potential INTEGER NOT NULL, '
            . 'development_profile TEXT NOT NULL, '
            . 'creation_seed INTEGER NOT NULL, '
            . "career_state TEXT NOT NULL DEFAULT 'active'"
            . ')'
        );
        $columns = $this->database->connection()->query('PRAGMA table_info(' . self::TABLE . ')')->fetchAll(PDO::FETCH_ASSOC);
        if (!in_array('career_state', array_column($columns, 'name'), true)) {
            $this->database->connection()->exec("ALTER TABLE " . self::TABLE . " ADD COLUMN career_state TEXT NOT NULL DEFAULT 'active'");
        }
        $this->database->connection()->exec(
            'CREATE TABLE IF NOT EXISTS player_nationalities ('
            . 'player_id TEXT NOT NULL, '
            . 'nation_id TEXT NOT NULL, '
            . 'PRIMARY KEY (player_id, nation_id)'
            . ')'
        );
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_player_nationalities_nation ON player_nationalities (nation_id, player_id)');
        $this->database->connection()->exec(
            'CREATE TABLE IF NOT EXISTS player_eligibilities ('
            . 'player_id TEXT NOT NULL, '
            . 'nation_id TEXT NOT NULL, '
            . 'PRIMARY KEY (player_id, nation_id)'
            . ')'
        );
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_player_records_primary_nation ON ' . self::TABLE . ' (primary_nation_id, id)');
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_player_records_birth_nation ON ' . self::TABLE . ' (birth_nation_id, id)');
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_player_eligibilities_nation ON player_eligibilities (nation_id, player_id)');
    }

    public function save(Player $player): void
    {
        $this->database->transaction(function () use ($player): void {
            $this->saveInTransaction($player);
        });
    }

    public function saveInTransaction(Player $player): void
    {
        $this->assertNationReferences($player);
        $values = $player->toArray();
        $values['pace'] = $player->attributes()->pace();
        $values['shooting'] = $player->attributes()->shooting();
        $values['passing'] = $player->attributes()->passing();
        $values['dribbling'] = $player->attributes()->dribbling();
        $values['defending'] = $player->attributes()->defending();
        $values['physicality'] = $player->attributes()->physicality();
        $statement = $this->database->connection()->prepare(
            'INSERT INTO ' . self::TABLE . ' '
            . '(id, first_name, last_name, preferred_name, birth_date, birth_nation_id, primary_nation_id, height_cm, weight_kg, primary_position, pace, shooting, passing, dribbling, defending, physicality, potential, development_profile, creation_seed, career_state) '
            . 'VALUES (:id, :first_name, :last_name, :preferred_name, :birth_date, :birth_nation_id, :primary_nation_id, :height_cm, :weight_kg, :primary_position, :pace, :shooting, :passing, :dribbling, :defending, :physicality, :potential, :development_profile, :creation_seed, :career_state) '
            . 'ON CONFLICT(id) DO UPDATE SET '
            . 'first_name = excluded.first_name, last_name = excluded.last_name, preferred_name = excluded.preferred_name, '
            . 'birth_date = excluded.birth_date, birth_nation_id = excluded.birth_nation_id, primary_nation_id = excluded.primary_nation_id, '
            . 'height_cm = excluded.height_cm, weight_kg = excluded.weight_kg, primary_position = excluded.primary_position, '
            . 'pace = excluded.pace, shooting = excluded.shooting, passing = excluded.passing, dribbling = excluded.dribbling, '
            . 'defending = excluded.defending, physicality = excluded.physicality, potential = excluded.potential, '
            . 'development_profile = excluded.development_profile, creation_seed = excluded.creation_seed, career_state = excluded.career_state'
        );
        $statement->execute([
            'id' => $values['id'],
            'first_name' => $values['first_name'],
            'last_name' => $values['last_name'],
            'preferred_name' => $values['preferred_name'],
            'birth_date' => $values['birth_date'],
            'birth_nation_id' => $values['birth_nation_id'],
            'primary_nation_id' => $values['primary_nation_id'],
            'height_cm' => $values['height_cm'],
            'weight_kg' => $values['weight_kg'],
            'primary_position' => $values['primary_position'],
            'pace' => $values['pace'],
            'shooting' => $values['shooting'],
            'passing' => $values['passing'],
            'dribbling' => $values['dribbling'],
            'defending' => $values['defending'],
            'physicality' => $values['physicality'],
            'potential' => $values['potential'],
            'development_profile' => $values['development_profile'],
            'creation_seed' => $values['creation_seed'],
            'career_state' => $values['career_state'],
        ]);

        $this->database->connection()->prepare('DELETE FROM player_nationalities WHERE player_id = :player_id')->execute(['player_id' => $player->id()->value()]);
        $nationality = $this->database->connection()->prepare('INSERT INTO player_nationalities (player_id, nation_id) VALUES (:player_id, :nation_id)');
        foreach ($player->secondaryNationIds() as $nationId) {
            $nationality->execute(['player_id' => $player->id()->value(), 'nation_id' => $nationId->value()]);
        }
        $this->database->connection()->prepare('DELETE FROM player_eligibilities WHERE player_id = :player_id')->execute(['player_id' => $player->id()->value()]);
        $eligibility = $this->database->connection()->prepare('INSERT INTO player_eligibilities (player_id, nation_id) VALUES (:player_id, :nation_id)');
        foreach ($player->eligibilityNationIds() as $nationId) {
            $eligibility->execute(['player_id' => $player->id()->value(), 'nation_id' => $nationId->value()]);
        }
    }

    public function exists(string|PlayerId $id): bool
    {
        $playerId = $id instanceof PlayerId ? $id : new PlayerId($id);
        $statement = $this->database->connection()->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE id = :id');
        $statement->execute(['id' => $playerId->value()]);

        return $statement->fetchColumn() !== false;
    }

    public function get(string|PlayerId $id): Player
    {
        $playerId = $id instanceof PlayerId ? $id : new PlayerId($id);
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id');
        $statement->execute(['id' => $playerId->value()]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new PlayerNotFoundException($playerId->value());
        }

        $secondary = $this->database->connection()->prepare('SELECT nation_id FROM player_nationalities WHERE player_id = :player_id ORDER BY nation_id ASC');
        $secondary->execute(['player_id' => $playerId->value()]);
        $eligibility = $this->database->connection()->prepare('SELECT nation_id FROM player_eligibilities WHERE player_id = :player_id ORDER BY nation_id ASC');
        $eligibility->execute(['player_id' => $playerId->value()]);

        return new Player(
            new PlayerId((string) $row['id']),
            (string) $row['first_name'],
            (string) $row['last_name'],
            (string) $row['preferred_name'],
            SimulationDate::fromIsoString((string) $row['birth_date']),
            new NationId((string) $row['primary_nation_id']),
            array_map(static fn (array $value): NationId => new NationId((string) $value['nation_id']), $secondary->fetchAll(PDO::FETCH_ASSOC)),
            new NationId((string) $row['birth_nation_id']),
            array_map(static fn (array $value): NationId => new NationId((string) $value['nation_id']), $eligibility->fetchAll(PDO::FETCH_ASSOC)),
            (int) $row['height_cm'],
            (int) $row['weight_kg'],
            PlayerPosition::from((string) $row['primary_position']),
            new PlayerAttributeSet((int) $row['pace'], (int) $row['shooting'], (int) $row['passing'], (int) $row['dribbling'], (int) $row['defending'], (int) $row['physicality']),
            (int) $row['potential'],
            DevelopmentProfile::from((string) $row['development_profile']),
            (int) $row['creation_seed'],
            PlayerCareerState::from((string) ($row['career_state'] ?? PlayerCareerState::Active->value)),
        );
    }

    /** @return list<Player> */
    public function all(): array
    {
        $rows = $this->database->connection()->query('SELECT id FROM ' . self::TABLE . ' ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): Player => $this->get((string) $row['id']), $rows);
    }

    /** @return list<Player> */
    public function byNation(string|NationId $id): array
    {
        $nationId = $id instanceof NationId ? $id : new NationId($id);
        $statement = $this->database->connection()->prepare(
            'SELECT id FROM ' . self::TABLE . ' WHERE primary_nation_id = :nation_id '
            . 'OR EXISTS (SELECT 1 FROM player_nationalities n WHERE n.player_id = player_records.id AND n.nation_id = :secondary_nation_id) '
            . 'ORDER BY id ASC'
        );
        $statement->execute(['nation_id' => $nationId->value(), 'secondary_nation_id' => $nationId->value()]);

        return array_map(fn (array $row): Player => $this->get((string) $row['id']), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function assertNationReferences(Player $player): void
    {
        $repository = new NationRepository($this->database);
        foreach (array_merge([$player->primaryNationId(), $player->birthNationId()], $player->secondaryNationIds(), $player->eligibilityNationIds()) as $nationId) {
            if (!$repository->exists($nationId)) {
                throw new PlayerException(sprintf('Player "%s" references missing Nation "%s".', $player->id()->value(), $nationId->value()));
            }
        }
    }
}
