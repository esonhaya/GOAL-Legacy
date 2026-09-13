<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Competition\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistrationException;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use PDO;
use PDOException;

final class PlayerRegistrationRepository
{
    private const TABLE = 'player_competition_registrations';

    public function __construct(private readonly DatabaseInterface $database)
    {
        $this->database->connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (season_id TEXT NOT NULL, competition_id TEXT NOT NULL, club_id TEXT NOT NULL, player_id TEXT NOT NULL, PRIMARY KEY (season_id, competition_id, club_id, player_id))');
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_player_registration_player ON ' . self::TABLE . ' (player_id, season_id, competition_id, club_id)');
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_player_registration_club ON ' . self::TABLE . ' (club_id, season_id, competition_id, player_id)');
        $this->database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_player_registration_competition ON ' . self::TABLE . ' (competition_id, season_id, club_id, player_id)');
    }

    public function register(PlayerRegistration $registration): void
    {
        $this->database->transaction(function () use ($registration): void {
            $this->registerInTransaction($registration);
        });
    }

    public function registerInTransaction(PlayerRegistration $registration): void
    {
        $this->assertReferences($registration);
        try {
            $statement = $this->database->connection()->prepare('INSERT INTO ' . self::TABLE . ' (season_id, competition_id, club_id, player_id) VALUES (:season_id, :competition_id, :club_id, :player_id)');
            $statement->execute($registration->toArray());
        } catch (PDOException $exception) {
            throw new PlayerRegistrationException(sprintf('Player registration "%s" already exists.', $registration->key()), 0, $exception);
        }
    }

    public function unregister(PlayerRegistration $registration): void
    {
        $statement = $this->database->connection()->prepare('DELETE FROM ' . self::TABLE . ' WHERE season_id = :season_id AND competition_id = :competition_id AND club_id = :club_id AND player_id = :player_id');
        $statement->execute($registration->toArray());
    }

    public function unregisterByPlayerClubSeason(PlayerId $playerId, ClubId $clubId, SeasonId $seasonId): void
    {
        $statement = $this->database->connection()->prepare('DELETE FROM ' . self::TABLE . ' WHERE player_id = :player_id AND club_id = :club_id AND season_id = :season_id');
        $statement->execute(['player_id' => $playerId->value(), 'club_id' => $clubId->value(), 'season_id' => $seasonId->value()]);
    }

    public function exists(PlayerRegistration $registration): bool
    {
        $statement = $this->database->connection()->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE season_id = :season_id AND competition_id = :competition_id AND club_id = :club_id AND player_id = :player_id');
        $statement->execute($registration->toArray());
        return $statement->fetchColumn() !== false;
    }

    /** @return list<PlayerRegistration> */
    public function all(): array { return $this->hydrateRows($this->database->connection()->query('SELECT * FROM ' . self::TABLE . ' ORDER BY season_id ASC, competition_id ASC, club_id ASC, player_id ASC')->fetchAll(PDO::FETCH_ASSOC)); }

    /** @return list<PlayerRegistration> */
    public function byPlayer(string|PlayerId $id): array { return $this->byColumn('player_id', ($id instanceof PlayerId ? $id : new PlayerId($id))->value(), 'season_id ASC, competition_id ASC, club_id ASC'); }
    /** @return list<PlayerRegistration> */
    public function byClub(string|ClubId $id): array { return $this->byColumn('club_id', ($id instanceof ClubId ? $id : new ClubId($id))->value(), 'season_id ASC, competition_id ASC, player_id ASC'); }
    /** @return list<PlayerRegistration> */
    public function byCompetition(string|CompetitionId $id, ?SeasonId $seasonId = null): array
    {
        $competitionId = $id instanceof CompetitionId ? $id : new CompetitionId($id);
        $sql = 'SELECT * FROM ' . self::TABLE . ' WHERE competition_id = :competition_id';
        $parameters = ['competition_id' => $competitionId->value()];
        if ($seasonId !== null) { $sql .= ' AND season_id = :season_id'; $parameters['season_id'] = $seasonId->value(); }
        $sql .= ' ORDER BY season_id ASC, club_id ASC, player_id ASC';
        $statement = $this->database->connection()->prepare($sql); $statement->execute($parameters);
        return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<array<string, mixed>> $rows @return list<PlayerRegistration> */
    private function hydrateRows(array $rows): array { return array_map(static fn (array $row): PlayerRegistration => new PlayerRegistration(new SeasonId((string) $row['season_id']), new CompetitionId((string) $row['competition_id']), new ClubId((string) $row['club_id']), new PlayerId((string) $row['player_id'])), $rows); }

    /** @return list<PlayerRegistration> */
    private function byColumn(string $column, string $value, string $order): array
    {
        $statement = $this->database->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE ' . $column . ' = :value ORDER BY ' . $order);
        $statement->execute(['value' => $value]);
        return $this->hydrateRows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function assertReferences(PlayerRegistration $registration): void
    {
        foreach ([['season_records', 'id', $registration->seasonId()->value(), 'Season'], ['competition_records', 'id', $registration->competitionId()->value(), 'Competition'], ['club_records', 'id', $registration->clubId()->value(), 'Club'], ['player_records', 'id', $registration->playerId()->value(), 'Player']] as [$table, $column, $value, $label]) {
            $statement = $this->database->connection()->prepare('SELECT 1 FROM ' . $table . ' WHERE ' . $column . ' = :value');
            $statement->execute(['value' => $value]);
            if ($statement->fetchColumn() === false) { throw new PlayerRegistrationException(sprintf('Registration references missing %s "%s".', $label, $value)); }
        }
        $membership = $this->database->connection()->prepare('SELECT 1 FROM club_competition_memberships WHERE season_id = :season_id AND competition_id = :competition_id AND club_id = :club_id');
        $membership->execute(['season_id' => $registration->seasonId()->value(), 'competition_id' => $registration->competitionId()->value(), 'club_id' => $registration->clubId()->value()]);
        if ($membership->fetchColumn() === false) { throw new PlayerRegistrationException('Club does not participate in the requested Competition and Season.'); }
        $squad = $this->database->connection()->prepare('SELECT 1 FROM club_squad_memberships WHERE season_id = :season_id AND club_id = :club_id AND player_id = :player_id');
        $squad->execute(['season_id' => $registration->seasonId()->value(), 'club_id' => $registration->clubId()->value(), 'player_id' => $registration->playerId()->value()]);
        if ($squad->fetchColumn() === false) { throw new PlayerRegistrationException('Player must belong to the Club squad before registration.'); }
        $careerState = $this->database->connection()->prepare("SELECT career_state FROM player_records WHERE id = :player_id");
        $careerState->execute(['player_id' => $registration->playerId()->value()]);
        if ((string) $careerState->fetchColumn() === 'retired') { throw new PlayerRegistrationException('Retired Players cannot be registered.'); }
        $contract = $this->database->connection()->prepare('SELECT 1 FROM contract_records WHERE player_id = :player_id AND club_id = :club_id AND status = :status LIMIT 1');
        $contract->execute(['player_id' => $registration->playerId()->value(), 'club_id' => $registration->clubId()->value(), 'status' => 'active']);
        if ($contract->fetchColumn() === false) { throw new PlayerRegistrationException('Player must have an active Contract with the Club before registration.'); }
    }
}
