<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\World\Persistence;

use DateTimeImmutable;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\PersistenceException;
use Goal\Legacy\Core\Persistence\SerializerInterface;
use Goal\Legacy\Core\Time\SimulationTime;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldException;
use Goal\Legacy\Modules\World\Domain\WorldId;
use Goal\Legacy\Modules\World\Domain\WorldNotFoundException;
use Goal\Legacy\Modules\World\Domain\WorldSimulationState;

final class WorldRepository
{
    private const TABLE = 'world_records';

    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly SerializerInterface $serializer = new JsonSerializer(),
    ) {
        $this->database->connection()->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'id TEXT PRIMARY KEY, '
            . 'label TEXT NOT NULL, '
            . 'universe_seed INTEGER NOT NULL, '
            . 'created_at TEXT NOT NULL, '
            . 'current_time INTEGER NOT NULL, '
            . 'current_season_id TEXT NULL, '
            . 'nation_ids TEXT NOT NULL, '
            . 'competition_ids TEXT NOT NULL, '
            . 'content_package_ids TEXT NOT NULL, '
            . 'simulation_state TEXT NOT NULL'
            . ')'
        );
    }

    public function save(World $world): void
    {
        $existing = $this->database->connection()->query('SELECT id FROM ' . self::TABLE . ' LIMIT 1')->fetchColumn();
        if ($existing !== false && $existing !== $world->id()->value()) {
            throw new WorldException(sprintf('Only one World root is allowed per save; "%s" already exists.', $existing));
        }

        $data = $world->toArray();
        $statement = $this->database->connection()->prepare(
            'INSERT INTO ' . self::TABLE . ' '
            . '(id, label, universe_seed, created_at, current_time, current_season_id, nation_ids, competition_ids, content_package_ids, simulation_state) '
            . 'VALUES (:id, :label, :universe_seed, :created_at, :current_time, :current_season_id, :nation_ids, :competition_ids, :content_package_ids, :simulation_state) '
            . 'ON CONFLICT(id) DO UPDATE SET '
            . 'label = excluded.label, '
            . 'universe_seed = excluded.universe_seed, '
            . 'created_at = excluded.created_at, '
            . 'current_time = excluded.current_time, '
            . 'current_season_id = excluded.current_season_id, '
            . 'nation_ids = excluded.nation_ids, '
            . 'competition_ids = excluded.competition_ids, '
            . 'content_package_ids = excluded.content_package_ids, '
            . 'simulation_state = excluded.simulation_state'
        );
        $statement->execute([
            'competition_ids' => $this->serializer->encode($data['competition_ids']),
            'content_package_ids' => $this->serializer->encode($data['content_package_ids']),
            'created_at' => $data['created_at'],
            'current_season_id' => $data['current_season_id'],
            'current_time' => $data['current_time'],
            'id' => $data['id'],
            'label' => $data['label'],
            'nation_ids' => $this->serializer->encode($data['nation_ids']),
            'simulation_state' => $data['simulation_state'],
            'universe_seed' => $data['universe_seed'],
        ]);
    }

    public function updateTimeline(World $world): void
    {
        $this->save($world);
    }

    public function exists(string|WorldId $id): bool
    {
        $worldId = $id instanceof WorldId ? $id : new WorldId($id);
        $statement = $this->database->connection()->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE id = :id');
        $statement->execute(['id' => $worldId->value()]);

        return $statement->fetchColumn() !== false;
    }

    public function get(string|WorldId $id): World
    {
        $worldId = $id instanceof WorldId ? $id : new WorldId($id);
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id');
        $statement->execute(['id' => $worldId->value()]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new WorldNotFoundException($worldId->value());
        }

        return $this->hydrate($row);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): World
    {
        foreach (['id', 'label', 'created_at', 'current_time', 'nation_ids', 'competition_ids', 'content_package_ids', 'simulation_state'] as $field) {
            if (!is_string($row[$field] ?? null) && !($field === 'current_time' && is_int($row[$field] ?? null))) {
                throw new PersistenceException(sprintf('World record field "%s" is malformed.', $field));
            }
        }
        if (!is_int($row['universe_seed'] ?? null) && !is_string($row['universe_seed'] ?? null)) {
            throw new PersistenceException('World universe seed is malformed.');
        }

        $nationIds = $this->decodeStringList((string) $row['nation_ids'], 'Nation IDs');
        $competitionIds = $this->decodeStringList((string) $row['competition_ids'], 'Competition IDs');
        $contentPackageIds = $this->decodeStringList((string) $row['content_package_ids'], 'Content package IDs');
        $currentSeasonId = $row['current_season_id'] === null ? null : new SeasonId((string) $row['current_season_id']);

        try {
            $createdAt = new DateTimeImmutable((string) $row['created_at']);
            $state = WorldSimulationState::from((string) $row['simulation_state']);
        } catch (\Throwable $exception) {
            throw new PersistenceException('World record contains invalid state.', 0, $exception);
        }

        return new World(
            new WorldId((string) $row['id']),
            (string) $row['label'],
            (int) $row['universe_seed'],
            $createdAt,
            new SimulationTime((int) $row['current_time']),
            $currentSeasonId,
            $nationIds,
            $competitionIds,
            $contentPackageIds,
            $state,
        );
    }

    /** @return list<string> */
    private function decodeStringList(string $payload, string $label): array
    {
        $values = $this->serializer->decode($payload);
        if (!array_is_list($values)) {
            throw new PersistenceException(sprintf('World %s must be a list.', $label));
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new PersistenceException(sprintf('World %s must contain strings.', $label));
            }
        }

        return array_values($values);
    }
}
