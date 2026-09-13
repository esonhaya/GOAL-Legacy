<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use PDO;

final class PlayerPopulationRepository
{
    private const TABLE = 'player_population_generations';

    public function __construct(private readonly DatabaseInterface $database)
    {
        $this->database->connection()->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'season_id TEXT NOT NULL, '
            . 'club_id TEXT NOT NULL, '
            . 'generation_version INTEGER NOT NULL, '
            . 'world_seed INTEGER NOT NULL, '
            . 'target_squad_size INTEGER NOT NULL, '
            . 'generated_count INTEGER NOT NULL, '
            . 'PRIMARY KEY (season_id, club_id)'
            . ')'
        );
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_player_population_club ON ' . self::TABLE . ' (club_id, season_id)');
    }

    /** @return array{generation_version:int,world_seed:int,target_squad_size:int,generated_count:int}|null */
    public function get(SeasonId $seasonId, ClubId $clubId): ?array
    {
        $statement = $this->database->connection()->prepare('SELECT generation_version, world_seed, target_squad_size, generated_count FROM ' . self::TABLE . ' WHERE season_id = :season_id AND club_id = :club_id');
        $statement->execute(['season_id' => $seasonId->value(), 'club_id' => $clubId->value()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'generation_version' => (int) $row['generation_version'],
            'world_seed' => (int) $row['world_seed'],
            'target_squad_size' => (int) $row['target_squad_size'],
            'generated_count' => (int) $row['generated_count'],
        ];
    }

    public function saveInTransaction(SeasonId $seasonId, ClubId $clubId, int $generationVersion, int $worldSeed, int $targetSquadSize, int $generatedCount): void
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO ' . self::TABLE . ' (season_id, club_id, generation_version, world_seed, target_squad_size, generated_count) '
            . 'VALUES (:season_id, :club_id, :generation_version, :world_seed, :target_squad_size, :generated_count) '
            . 'ON CONFLICT(season_id, club_id) DO UPDATE SET generation_version = excluded.generation_version, world_seed = excluded.world_seed, target_squad_size = excluded.target_squad_size, generated_count = excluded.generated_count'
        );
        $statement->execute([
            'season_id' => $seasonId->value(),
            'club_id' => $clubId->value(),
            'generation_version' => $generationVersion,
            'world_seed' => $worldSeed,
            'target_squad_size' => $targetSquadSize,
            'generated_count' => $generatedCount,
        ]);
    }
}
