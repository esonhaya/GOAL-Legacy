<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Player\Domain\PlayerAppearance;
use PDO;

/** Stores a tiny cosmetic specification; rendered portraits never enter saves. */
final class PlayerAppearanceRepository
{
    private const TABLE = 'player_appearances';

    public function __construct(private readonly DatabaseInterface $database)
    {
        $this->database->connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (player_id TEXT PRIMARY KEY, schema_version INTEGER NOT NULL, appearance_json TEXT NOT NULL)');
    }

    public function get(string $playerId): ?PlayerAppearance
    {
        $statement = $this->database->connection()->prepare('SELECT appearance_json FROM ' . self::TABLE . ' WHERE player_id = :player_id');
        $statement->execute(['player_id' => $playerId]);
        $json = $statement->fetchColumn();
        if (!is_string($json)) { return null; }
        $values = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($values) ? PlayerAppearance::fromArray($values) : null;
    }

    public function save(string $playerId, PlayerAppearance $appearance): void
    {
        $this->database->transaction(fn (): int => $this->saveInTransaction($playerId, $appearance));
    }

    public function saveInTransaction(string $playerId, PlayerAppearance $appearance): int
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO ' . self::TABLE . ' (player_id, schema_version, appearance_json) VALUES (:player_id, :schema_version, :appearance_json) '
            . 'ON CONFLICT(player_id) DO UPDATE SET schema_version = excluded.schema_version, appearance_json = excluded.appearance_json'
        );
        return $statement->execute([
            'player_id' => $playerId,
            'schema_version' => PlayerAppearance::SCHEMA_VERSION,
            'appearance_json' => json_encode($appearance->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]) ? 1 : 0;
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->database->connection()->query('SELECT player_id, schema_version, appearance_json FROM ' . self::TABLE . ' ORDER BY player_id')->fetchAll(PDO::FETCH_ASSOC);
    }
}
