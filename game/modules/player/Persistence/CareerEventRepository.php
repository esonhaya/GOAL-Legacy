<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use Goal\Legacy\Modules\Player\Domain\CareerEvent;
use Goal\Legacy\Modules\Player\Domain\CareerEventStatus;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PDO;

final class CareerEventRepository
{
    private const TABLE = 'career_events';

    public function __construct(private readonly DatabaseInterface $database)
    {
        SchemaInitializationGuard::run($this->database->connection(), self::class, function (): void {
            $connection = $this->database->connection();
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (id TEXT PRIMARY KEY, player_id TEXT NOT NULL, season_id TEXT NOT NULL, event_date TEXT NOT NULL, source_key TEXT NOT NULL UNIQUE, category TEXT NOT NULL, definition TEXT NOT NULL, title TEXT NOT NULL, description TEXT NOT NULL, choices_json TEXT NOT NULL, status TEXT NOT NULL, selected_choice TEXT NULL, context_json TEXT NOT NULL, consequence_json TEXT NULL)');
            $connection->exec('CREATE INDEX IF NOT EXISTS idx_career_events_player_date ON ' . self::TABLE . ' (player_id, event_date, id)');
            $connection->exec('CREATE INDEX IF NOT EXISTS idx_career_events_player_status ON ' . self::TABLE . ' (player_id, status, event_date, id)');
        });
    }

    public function bySourceKey(string $sourceKey): ?CareerEvent
    {
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE source_key = :source_key');
        $statement->execute(['source_key' => $sourceKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function get(string $id): ?CareerEvent
    {
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return list<CareerEvent> */
    public function pendingForPlayer(PlayerId $playerId): array
    {
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE player_id = :player_id AND status = :status ORDER BY event_date ASC, id ASC');
        $statement->execute(['player_id' => $playerId->value(), 'status' => CareerEventStatus::Pending->value]);

        return array_map(fn (array $row): CareerEvent => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<CareerEvent> */
    public function resolvedForPlayer(PlayerId $playerId, int $limit = 20): array
    {
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE player_id = :player_id AND status = :status ORDER BY event_date DESC, id DESC LIMIT :limit');
        $statement->bindValue(':player_id', $playerId->value());
        $statement->bindValue(':status', CareerEventStatus::Resolved->value);
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return array_map(fn (array $row): CareerEvent => $this->hydrate($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function saveInTransaction(CareerEvent $event): void
    {
        $values = $event->toArray();
        $statement = $this->database->connection()->prepare('INSERT INTO ' . self::TABLE . ' (id, player_id, season_id, event_date, source_key, category, definition, title, description, choices_json, status, selected_choice, context_json, consequence_json) VALUES (:id, :player_id, :season_id, :date, :source_key, :category, :definition, :title, :description, :choices_json, :status, :selected_choice, :context_json, :consequence_json) ON CONFLICT(source_key) DO NOTHING');
        $statement->execute([
            'id' => $values['id'],
            'player_id' => $values['player_id'],
            'season_id' => $values['season_id'],
            'date' => $values['date'],
            'source_key' => $values['source_key'],
            'category' => $values['category'],
            'definition' => $values['definition'],
            'title' => $values['title'],
            'description' => $values['description'],
            'choices_json' => json_encode($values['choices'], JSON_THROW_ON_ERROR),
            'status' => $values['status'],
            'selected_choice' => $values['selected_choice'],
            'context_json' => json_encode($values['context'], JSON_THROW_ON_ERROR),
            'consequence_json' => $values['consequence'] === null ? null : json_encode($values['consequence'], JSON_THROW_ON_ERROR),
        ]);
    }

    public function resolveInTransaction(CareerEvent $event): void
    {
        $values = $event->toArray();
        $this->database->connection()->prepare('UPDATE ' . self::TABLE . ' SET status = :status, selected_choice = :selected_choice, consequence_json = :consequence_json WHERE id = :id AND status = :pending')->execute([
            'status' => $values['status'],
            'selected_choice' => $values['selected_choice'],
            'consequence_json' => json_encode($values['consequence'], JSON_THROW_ON_ERROR),
            'id' => $values['id'],
            'pending' => CareerEventStatus::Pending->value,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): CareerEvent
    {
        $choices = json_decode((string) $row['choices_json'], true, 512, JSON_THROW_ON_ERROR);
        $context = json_decode((string) $row['context_json'], true, 512, JSON_THROW_ON_ERROR);
        $consequence = $row['consequence_json'] === null ? null : json_decode((string) $row['consequence_json'], true, 512, JSON_THROW_ON_ERROR);

        return new CareerEvent(
            (string) $row['id'],
            new PlayerId((string) $row['player_id']),
            new SeasonId((string) $row['season_id']),
            SimulationDate::fromIsoString((string) $row['event_date']),
            (string) $row['source_key'],
            (string) $row['category'],
            (string) $row['definition'],
            (string) $row['title'],
            (string) $row['description'],
            is_array($choices) ? array_values(array_filter($choices, 'is_array')) : [],
            CareerEventStatus::from((string) $row['status']),
            $row['selected_choice'] === null ? null : (string) $row['selected_choice'],
            is_array($context) ? $context : [],
            $consequence === null || is_array($consequence) ? $consequence : null,
        );
    }
}
