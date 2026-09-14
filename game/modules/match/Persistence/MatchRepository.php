<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchException;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\MatchNotFoundException;
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PDO;

final class MatchRepository
{
    private const TABLE = 'match_records';
    public function __construct(private readonly DatabaseInterface $database)
    {
        SchemaInitializationGuard::run($this->database->connection(), self::class, function (): void {
            $this->database->connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (id TEXT PRIMARY KEY, competition_id TEXT NOT NULL, season_id TEXT NOT NULL, round_number INTEGER NOT NULL, scheduled_date TEXT NOT NULL, home_club_id TEXT NOT NULL, away_club_id TEXT NOT NULL, status TEXT NOT NULL, home_goals INTEGER NULL, away_goals INTEGER NULL)');
            $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_match_competition_season ON ' . self::TABLE . ' (competition_id, season_id, scheduled_date, id)');
            $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_match_home_club ON ' . self::TABLE . ' (home_club_id, season_id, scheduled_date, id)');
            $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_match_away_club ON ' . self::TABLE . ' (away_club_id, season_id, scheduled_date, id)');
            $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_match_due ON ' . self::TABLE . ' (status, scheduled_date, id)');
        });
    }

    public function save(GameMatch $match): void { $this->database->transaction(function () use ($match): void { $this->saveInTransaction($match); }); }

    public function saveInTransaction(GameMatch $match): void
    {
        $this->assertReferences($match);
        $existing = $this->findRow($match->id());
        if ($existing !== null && (string) $existing['status'] === MatchStatus::Completed->value) {
            $stored = $this->hydrate($existing);
            if ($stored->toArray() !== $match->toArray()) { throw new MatchException('Completed Matches are immutable.'); }
            return;
        }
        $statement = $this->database->connection()->prepare('INSERT INTO ' . self::TABLE . ' (id, competition_id, season_id, round_number, scheduled_date, home_club_id, away_club_id, status, home_goals, away_goals) VALUES (:id, :competition_id, :season_id, :round_number, :scheduled_date, :home_club_id, :away_club_id, :status, :home_goals, :away_goals) ON CONFLICT(id) DO UPDATE SET competition_id = excluded.competition_id, season_id = excluded.season_id, round_number = excluded.round_number, scheduled_date = excluded.scheduled_date, home_club_id = excluded.home_club_id, away_club_id = excluded.away_club_id, status = excluded.status, home_goals = excluded.home_goals, away_goals = excluded.away_goals');
        $statement->execute($match->toArray());
    }

    public function exists(string|MatchId $id): bool { $matchId = $id instanceof MatchId ? $id : new MatchId($id); return $this->findRow($matchId) !== null; }
    public function get(string|MatchId $id): GameMatch { $matchId = $id instanceof MatchId ? $id : new MatchId($id); $row = $this->findRow($matchId); if ($row === null) { throw new MatchNotFoundException($matchId->value()); } return $this->hydrate($row); }
    /** @return list<GameMatch> */
    public function all(): array { return $this->hydrateRows($this->database->connection()->query('SELECT * FROM ' . self::TABLE . ' ORDER BY scheduled_date ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC)); }
    /** @return list<GameMatch> */
    public function byCompetition(string|CompetitionId $id, ?SeasonId $seasonId = null): array { $competitionId = $id instanceof CompetitionId ? $id : new CompetitionId($id); $sql = 'SELECT * FROM ' . self::TABLE . ' WHERE competition_id = :competition_id'; $params = ['competition_id' => $competitionId->value()]; if ($seasonId !== null) { $sql .= ' AND season_id = :season_id'; $params['season_id'] = $seasonId->value(); } $sql .= ' ORDER BY scheduled_date ASC, round_number ASC, id ASC'; $statement = $this->database->connection()->prepare($sql); $statement->execute($params); return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC)); }
    /** @return list<GameMatch> */
    public function byClub(string|ClubId $id, ?SeasonId $seasonId = null): array { $clubId = $id instanceof ClubId ? $id : new ClubId($id); $sql = 'SELECT * FROM ' . self::TABLE . ' WHERE (home_club_id = :club_id OR away_club_id = :club_id)'; $params = ['club_id' => $clubId->value()]; if ($seasonId !== null) { $sql .= ' AND season_id = :season_id'; $params['season_id'] = $seasonId->value(); } $sql .= ' ORDER BY scheduled_date ASC, round_number ASC, id ASC'; $statement = $this->database->connection()->prepare($sql); $statement->execute($params); return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC)); }
    /** @return list<GameMatch> */
    public function byScheduledDate(SimulationDate $date): array { $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE scheduled_date = :scheduled_date ORDER BY id ASC'); $statement->execute(['scheduled_date' => $date->toIsoString()]); return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC)); }
    /** @return list<GameMatch> */
    public function byScheduledRange(SimulationDate $from, SimulationDate $to): array { if ($to->isBefore($from)) { throw new \InvalidArgumentException('Match date ranges cannot run backwards.'); } $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE scheduled_date BETWEEN :from_date AND :to_date ORDER BY scheduled_date ASC, id ASC'); $statement->execute(['from_date' => $from->toIsoString(), 'to_date' => $to->toIsoString()]); return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC)); }
    /** @return list<GameMatch> */
    public function dueScheduled(SimulationDate $date): array { $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE status = :status AND scheduled_date <= :scheduled_date ORDER BY scheduled_date ASC, id ASC'); $statement->execute(['status' => MatchStatus::Scheduled->value, 'scheduled_date' => $date->toIsoString()]); return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC)); }
    /** @return list<GameMatch> */
    public function byStatus(MatchStatus $status): array { $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE status = :status ORDER BY scheduled_date ASC, id ASC'); $statement->execute(['status' => $status->value]); return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC)); }
    /** @return list<GameMatch> */
    public function completedByCompetition(string|CompetitionId $id, SeasonId $seasonId): array { $matches = $this->byCompetition($id, $seasonId); return array_values(array_filter($matches, static fn (GameMatch $match): bool => $match->status() === MatchStatus::Completed)); }

    /** @return array<string, mixed>|null */
    private function findRow(MatchId $id): ?array { $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id'); $statement->execute(['id' => $id->value()]); $row = $statement->fetch(); return is_array($row) ? $row : null; }
    /** @param list<array<string, mixed>> $rows @return list<GameMatch> */
    private function hydrateRows(array $rows): array { return array_map(fn (array $row): GameMatch => $this->hydrate($row), $rows); }
    /** @param array<string, mixed> $row */
    private function hydrate(array $row): GameMatch { $result = $row['home_goals'] === null ? null : new MatchResult((int) $row['home_goals'], (int) $row['away_goals']); return new GameMatch(new MatchId((string) $row['id']), new CompetitionId((string) $row['competition_id']), new SeasonId((string) $row['season_id']), (int) $row['round_number'], SimulationDate::fromIsoString((string) $row['scheduled_date']), new ClubId((string) $row['home_club_id']), new ClubId((string) $row['away_club_id']), MatchStatus::from((string) $row['status']), $result); }
    private function assertReferences(GameMatch $match): void
    {
        foreach ([['season_records', 'id', $match->seasonId()->value(), 'Season'], ['competition_records', 'id', $match->competitionId()->value(), 'Competition'], ['club_records', 'id', $match->homeClubId()->value(), 'home Club'], ['club_records', 'id', $match->awayClubId()->value(), 'away Club']] as [$table, $column, $value, $label]) { $statement = $this->database->connection()->prepare('SELECT 1 FROM ' . $table . ' WHERE ' . $column . ' = :value'); $statement->execute(['value' => $value]); if ($statement->fetchColumn() === false) { throw new MatchException(sprintf('Match references missing %s "%s".', $label, $value)); } }
        $season = $this->database->connection()->prepare('SELECT start_date, end_date FROM season_records WHERE id = :id'); $season->execute(['id' => $match->seasonId()->value()]); $seasonRow = $season->fetch(); if (!is_array($seasonRow) || $match->scheduledDate()->isBefore(SimulationDate::fromIsoString((string) $seasonRow['start_date'])) || $match->scheduledDate()->isAfter(SimulationDate::fromIsoString((string) $seasonRow['end_date']))) { throw new MatchException('Match scheduled date must be inside the Season.'); }
        foreach ([['home_club_id', $match->homeClubId()->value()], ['away_club_id', $match->awayClubId()->value()]] as [$column, $clubId]) { $membership = $this->database->connection()->prepare('SELECT 1 FROM club_competition_memberships WHERE season_id = :season_id AND competition_id = :competition_id AND club_id = :club_id'); $membership->execute(['season_id' => $match->seasonId()->value(), 'competition_id' => $match->competitionId()->value(), 'club_id' => $clubId]); if ($membership->fetchColumn() === false) { throw new MatchException(sprintf('Match %s Club does not participate in the Competition and Season.', $column === 'home_club_id' ? 'home' : 'away')); } }
    }
}
