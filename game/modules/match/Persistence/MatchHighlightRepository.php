<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Match\Domain\MatchHighlight;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use PDO;

final class MatchHighlightRepository
{
    private const TABLE = 'match_highlights';
    public function __construct(private readonly DatabaseInterface $database)
    { $this->database->connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (match_id TEXT NOT NULL, sequence_number INTEGER NOT NULL, minute INTEGER NOT NULL, type TEXT NOT NULL, club_id TEXT NULL, player_id TEXT NULL, data_json TEXT NOT NULL, PRIMARY KEY (match_id, sequence_number))'); $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_match_highlights_player ON ' . self::TABLE . ' (player_id, match_id, sequence_number)'); }
    /** @param list<MatchHighlight> $highlights */
    public function replaceForMatch(array $highlights): void { $this->database->transaction(function () use ($highlights): void { $this->replaceForMatchInTransaction($highlights); }); }
    /** @param list<MatchHighlight> $highlights */
    public function replaceForMatchInTransaction(array $highlights): void { if ($highlights === []) { return; } $matchId = $highlights[0]->matchId()->value(); $this->database->connection()->prepare('DELETE FROM ' . self::TABLE . ' WHERE match_id = :match_id')->execute(['match_id' => $matchId]); $statement = $this->database->connection()->prepare('INSERT INTO ' . self::TABLE . ' (match_id, sequence_number, minute, type, club_id, player_id, data_json) VALUES (:match_id, :sequence_number, :minute, :type, :club_id, :player_id, :data_json)'); foreach ($highlights as $highlight) { $values = $highlight->toArray(); $values['data_json'] = json_encode($values['data'], JSON_THROW_ON_ERROR); unset($values['data']); $statement->execute($values); } }
    /** @return list<MatchHighlight> */
    public function byMatch(string|MatchId $id): array { $matchId = $id instanceof MatchId ? $id : new MatchId($id); $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE match_id = :match_id ORDER BY sequence_number ASC'); $statement->execute(['match_id' => $matchId->value()]); return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC)); }
    /** @param list<array<string, mixed>> $rows @return list<MatchHighlight> */
    private function hydrateRows(array $rows): array { return array_map(static fn (array $row): MatchHighlight => new MatchHighlight(new MatchId((string) $row['match_id']), (int) $row['sequence_number'], (int) $row['minute'], (string) $row['type'], $row['club_id'] === null ? null : new ClubId((string) $row['club_id']), $row['player_id'] === null ? null : new PlayerId((string) $row['player_id']), json_decode((string) $row['data_json'], true, 512, JSON_THROW_ON_ERROR)), $rows); }
}
