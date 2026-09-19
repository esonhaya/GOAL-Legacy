<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\International;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Nation\NationService;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\CareerEvent;
use Goal\Legacy\Modules\Player\Persistence\CareerEventRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\PlayerAvailabilityService;
use Goal\Legacy\Modules\Player\Persistence\PlayerAvailabilityRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\PulseService;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PDO;

/**
 * Owns national-team identity, eligibility and bounded selection windows.
 * Football remains owned by MatchService and the canonical Match simulator.
 */
final class NationalTeamService
{
    public const MIN_PLAYER_POOL = 18;
    public const SQUAD_SIZE = 23;

    private const TEAMS = 'national_team_records';
    private const SQUADS = 'international_team_squads';
    private const STATS = 'international_player_statistics';

    public function __construct(private readonly NationService $nations, private readonly ?PulseService $pulse = null)
    {
    }

    public function initializeSchema(DatabaseInterface $database): void
    {
        SchemaInitializationGuard::run($database->connection(), self::class, function () use ($database): void {
            $database->connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::TEAMS . ' (id TEXT PRIMARY KEY, nation_id TEXT NOT NULL UNIQUE, display_name TEXT NOT NULL, strength INTEGER NOT NULL DEFAULT 0, player_pool_count INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1)');
            $database->connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::SQUADS . ' (season_id TEXT NOT NULL, national_team_id TEXT NOT NULL, player_id TEXT NOT NULL, role TEXT NOT NULL, selection_score INTEGER NOT NULL, status TEXT NOT NULL, PRIMARY KEY (season_id, national_team_id, player_id))');
            $database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_intl_squad_team ON ' . self::SQUADS . ' (season_id, national_team_id, status, player_id)');
            $database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_intl_squad_player ON ' . self::SQUADS . ' (player_id, season_id, national_team_id)');
            $database->connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::STATS . ' (player_id TEXT NOT NULL, season_id TEXT NOT NULL, competition_id TEXT NOT NULL, caps INTEGER NOT NULL DEFAULT 0, starts INTEGER NOT NULL DEFAULT 0, minutes INTEGER NOT NULL DEFAULT 0, goals INTEGER NOT NULL DEFAULT 0, assists INTEGER NOT NULL DEFAULT 0, yellow_cards INTEGER NOT NULL DEFAULT 0, red_cards INTEGER NOT NULL DEFAULT 0, rated_appearances INTEGER NOT NULL DEFAULT 0, rating_total REAL NOT NULL DEFAULT 0, PRIMARY KEY (player_id, season_id, competition_id))');
            $database->connection()->exec('CREATE INDEX IF NOT EXISTS idx_intl_stats_player ON ' . self::STATS . ' (player_id, season_id, competition_id)');
        });
    }

    public function teamId(string $nationId): string
    {
        return 'national-team-' . $nationId;
    }

    public function nationId(string $teamId): string
    {
        return str_starts_with($teamId, 'national-team-') ? substr($teamId, 14) : $teamId;
    }

    public function isNationalTeam(string $teamId): bool
    {
        return str_starts_with($teamId, 'national-team-');
    }

    public function displayName(DatabaseInterface $database, string $teamId): string
    {
        $this->initializeSchema($database);
        $statement = $database->connection()->prepare('SELECT display_name FROM ' . self::TEAMS . ' WHERE id = :id');
        $statement->execute(['id' => $teamId]);
        $name = $statement->fetchColumn();
        if (is_string($name) && $name !== '') {
            return $name;
        }
        $nation = $this->nations->repository($database)->find($this->nationId($teamId));

        return $nation?->displayName() ?? $this->nationId($teamId) . ' National Team';
    }

    /** @return list<array{id:string,nation_id:string,name:string,strength:int,pool:int}> */
    public function teams(DatabaseInterface $database): array
    {
        $this->initializeSchema($database);
        $rows = $database->connection()->query('SELECT id, nation_id, display_name, strength, player_pool_count FROM ' . self::TEAMS . ' WHERE active = 1 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'nation_id' => (string) $row['nation_id'], 'name' => (string) $row['display_name'], 'strength' => (int) $row['strength'], 'pool' => (int) $row['player_pool_count']], $rows);
    }

    /**
     * Creates identity rows and a stable squad for each supported country.
     * The method is intentionally safe before Player population: legacy saves
     * can initialize schemas first and populate players afterwards.
     */
    public function ensureSeason(DatabaseInterface $database, Season $season): void
    {
        $this->initializeSchema($database);
        $this->pulse?->initializeSchema($database);
        $playerTable = (int) $database->connection()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'player_records'")->fetchColumn();
        $allPlayers = $playerTable > 0 ? (new PlayerRepository($database))->all() : [];
        foreach ($this->nations->loadSelected() as $nation) {
            $teamId = $this->teamId($nation->id()->value());
            $pool = 0;
            if ($playerTable > 0) {
                $poolStatement = $database->connection()->prepare("SELECT COUNT(*) FROM player_records WHERE primary_nation_id = :nation_id AND career_state = 'active'");
                $poolStatement->execute(['nation_id' => $nation->id()->value()]);
                $pool = (int) $poolStatement->fetchColumn();
            }
            $strengthStatement = $database->connection()->prepare('SELECT strength FROM ' . self::TEAMS . ' WHERE id = :id');
            $strengthStatement->execute(['id' => $teamId]);
            $strength = (int) ($strengthStatement->fetchColumn() ?: 0);
            $saveTeam = $database->connection()->prepare('INSERT INTO ' . self::TEAMS . ' (id, nation_id, display_name, strength, player_pool_count, active) VALUES (:id, :nation_id, :display_name, :strength, :pool, 1) ON CONFLICT(id) DO UPDATE SET display_name = excluded.display_name, strength = excluded.strength, player_pool_count = excluded.player_pool_count, active = 1');
            $saveTeam->execute(['id' => $teamId, 'nation_id' => $nation->id()->value(), 'display_name' => $nation->displayName() . ' National Team', 'strength' => $strength, 'pool' => $pool]);
            if ($playerTable > 0 && $pool >= self::MIN_PLAYER_POOL) {
                $this->selectSquad($database, $season, $teamId, $nation->id()->value(), $allPlayers);
            }
        }
    }

    public function supports(DatabaseInterface $database, string $nationId): bool
    {
        $this->initializeSchema($database);
        $statement = $database->connection()->prepare('SELECT player_pool_count FROM ' . self::TEAMS . ' WHERE nation_id = :nation_id AND active = 1');
        $statement->execute(['nation_id' => $nationId]);

        return (int) $statement->fetchColumn() >= self::MIN_PLAYER_POOL;
    }

    /** @return list<array<string,mixed>> */
    public function squad(DatabaseInterface $database, string $teamId, SeasonId|string $seasonId): array
    {
        $this->initializeSchema($database);
        $season = $seasonId instanceof SeasonId ? $seasonId->value() : $seasonId;
        $statement = $database->connection()->prepare('SELECT s.player_id, s.role, s.selection_score, s.status, p.first_name, p.last_name, p.preferred_name, p.primary_position, p.primary_nation_id, p.pace, p.shooting, p.passing, p.dribbling, p.defending, p.physicality, p.potential, p.birth_date, p.birth_nation_id, p.height_cm, p.weight_kg, p.development_profile, p.creation_seed, p.career_state FROM ' . self::SQUADS . ' s JOIN player_records p ON p.id = s.player_id WHERE s.season_id = :season_id AND s.national_team_id = :team_id ORDER BY s.status ASC, s.selection_score DESC, s.player_id ASC');
        $statement->execute(['season_id' => $season, 'team_id' => $teamId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<string> */
    public function selectedPlayerIds(DatabaseInterface $database, string $teamId, SeasonId|string $seasonId): array
    {
        $season = $seasonId instanceof SeasonId ? $seasonId->value() : $seasonId;
        $statement = $database->connection()->prepare('SELECT player_id FROM ' . self::SQUADS . ' WHERE season_id = :season_id AND national_team_id = :team_id AND status = \'selected\' ORDER BY player_id ASC');
        $statement->execute(['season_id' => $season, 'team_id' => $teamId]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function selectionStatus(DatabaseInterface $database, string $playerId, SeasonId|string $seasonId): string
    {
        $season = $seasonId instanceof SeasonId ? $seasonId->value() : $seasonId;
        $statement = $database->connection()->prepare('SELECT status FROM ' . self::SQUADS . ' WHERE player_id = :player_id AND season_id = :season_id ORDER BY status ASC LIMIT 1');
        $statement->execute(['player_id' => $playerId, 'season_id' => $season]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : 'not_selected';
    }

    public function strength(DatabaseInterface $database, string $teamId): int
    {
        $this->initializeSchema($database);
        $statement = $database->connection()->prepare('SELECT strength FROM ' . self::TEAMS . ' WHERE id = :id');
        $statement->execute(['id' => $teamId]);

        return (int) ($statement->fetchColumn() ?: 0);
    }

    /** @return array<string,int|float> */
    public function playerStats(DatabaseInterface $database, string $playerId, SeasonId|string|null $seasonId = null, ?string $competitionId = null): array
    {
        $this->initializeSchema($database);
        $sql = 'SELECT COALESCE(SUM(caps),0) caps, COALESCE(SUM(starts),0) starts, COALESCE(SUM(minutes),0) minutes, COALESCE(SUM(goals),0) goals, COALESCE(SUM(assists),0) assists, COALESCE(SUM(yellow_cards),0) yellow_cards, COALESCE(SUM(red_cards),0) red_cards, COALESCE(SUM(rated_appearances),0) rated_appearances, COALESCE(SUM(rating_total),0) rating_total FROM ' . self::STATS . ' WHERE player_id = :player_id';
        $params = ['player_id' => $playerId];
        if ($seasonId !== null) { $sql .= ' AND season_id = :season_id'; $params['season_id'] = $seasonId instanceof SeasonId ? $seasonId->value() : $seasonId; }
        if ($competitionId !== null) { $sql .= ' AND competition_id = :competition_id'; $params['competition_id'] = $competitionId; }
        $statement = $database->connection()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $appearances = (int) ($row['caps'] ?? 0);

        return ['caps' => $appearances, 'starts' => (int) ($row['starts'] ?? 0), 'minutes' => (int) ($row['minutes'] ?? 0), 'goals' => (int) ($row['goals'] ?? 0), 'assists' => (int) ($row['assists'] ?? 0), 'yellow_cards' => (int) ($row['yellow_cards'] ?? 0), 'red_cards' => (int) ($row['red_cards'] ?? 0), 'rated_appearances' => (int) ($row['rated_appearances'] ?? 0), 'average_rating' => $appearances === 0 ? null : round((float) ($row['rating_total'] ?? 0) / $appearances, 2)];
    }

    /** @return list<array<string,mixed>> */
    public function history(DatabaseInterface $database, string $playerId): array
    {
        $this->initializeSchema($database);
        $statement = $database->connection()->prepare('SELECT season_id, competition_id, caps, goals, starts, minutes FROM ' . self::STATS . ' WHERE player_id = :player_id ORDER BY season_id ASC, competition_id ASC');
        $statement->execute(['player_id' => $playerId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addPlayerMatchStats(DatabaseInterface $database, string $competitionId, SeasonId|string $seasonId, array $stats, string $playerId, int $rating): void
    {
        $this->initializeSchema($database);
        $season = $seasonId instanceof SeasonId ? $seasonId->value() : $seasonId;
        $selected = null;
        foreach ($stats as $stat) { if ($stat->playerId()->value() === $playerId) { $selected = $stat; break; } }
        if ($selected === null || !$selected->appeared()) { return; }
        $statement = $database->connection()->prepare('INSERT INTO ' . self::STATS . ' (player_id, season_id, competition_id, caps, starts, minutes, goals, assists, yellow_cards, red_cards, rated_appearances, rating_total) VALUES (:player_id, :season_id, :competition_id, 1, :starts, :minutes, :goals, :assists, :yellow, :red, 1, :rating) ON CONFLICT(player_id, season_id, competition_id) DO UPDATE SET caps = caps + 1, starts = starts + excluded.starts, minutes = minutes + excluded.minutes, goals = goals + excluded.goals, assists = assists + excluded.assists, yellow_cards = yellow_cards + excluded.yellow_cards, red_cards = red_cards + excluded.red_cards, rated_appearances = rated_appearances + 1, rating_total = rating_total + excluded.rating_total');
        $statement->execute(['player_id' => $playerId, 'season_id' => $season, 'competition_id' => $competitionId, 'starts' => $selected->started() ? 1 : 0, 'minutes' => $selected->minutes(), 'goals' => $selected->goals(), 'assists' => $selected->assists(), 'yellow' => $selected->yellowCards(), 'red' => $selected->redCards(), 'rating' => $rating]);
    }

    /** @param list<Player> $allPlayers */
    private function selectSquad(DatabaseInterface $database, Season $season, string $teamId, string $nationId, array $allPlayers): void
    {
        $exists = $database->connection()->prepare('SELECT COUNT(*) FROM ' . self::SQUADS . ' WHERE season_id = :season_id AND national_team_id = :team_id');
        $exists->execute(['season_id' => $season->id()->value(), 'team_id' => $teamId]);
        if ((int) $exists->fetchColumn() > 0) { return; }
        $players = array_values(array_filter($allPlayers, static fn (Player $player): bool => !$player->isRetired() && $player->primaryNationId()->value() === $nationId));
        if (count($players) < self::MIN_PLAYER_POOL) { return; }
        $unavailable = $this->unavailablePlayerIds($database, $season->startDate());
        $form = $this->formScores($database);
        $roleWeights = $this->clubRoleWeights($database, $season->id()->value());
        $ranked = [];
        foreach ($players as $player) {
            if (isset($unavailable[$player->id()->value()])) { continue; }
            $score = ($player->overallRating() * 100) + (($form[$player->id()->value()] ?? 60) * 2) + ($roleWeights[$player->id()->value()] ?? 0);
            $ranked[] = ['player' => $player, 'score' => $score, 'tie' => hash('sha256', 'international-selection:v1|' . $season->id()->value() . '|' . $teamId . '|' . $player->id()->value())];
        }
        $groups = ['goalkeeper' => [], 'defensive' => [], 'midfield' => [], 'attacking' => []];
        foreach ($ranked as $candidate) { $groups[$this->positionGroup($candidate['player'])][] = $candidate; }
        foreach ($groups as &$group) { usort($group, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp($a['tie'], $b['tie'])); } unset($group);
        $quota = ['goalkeeper' => 2, 'defensive' => 7, 'midfield' => 8, 'attacking' => 6];
        $chosen = [];
        foreach ($quota as $group => $count) { foreach (array_slice($groups[$group], 0, $count) as $candidate) { $chosen[$candidate['player']->id()->value()] = $candidate; } }
        usort($ranked, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp($a['tie'], $b['tie']));
        foreach ($ranked as $candidate) { if (count($chosen) >= self::SQUAD_SIZE) { break; } $chosen[$candidate['player']->id()->value()] = $candidate; }
        if (count($chosen) < self::MIN_PLAYER_POOL) { return; }
        $save = $database->connection()->prepare('INSERT INTO ' . self::SQUADS . ' (season_id, national_team_id, player_id, role, selection_score, status) VALUES (:season_id, :team_id, :player_id, :role, :score, \'selected\')');
        $total = 0;
        $careerPlayers = new CareerPlayerRepository($database);
        $events = new CareerEventRepository($database);
        foreach ($chosen as $candidate) {
            $player = $candidate['player'];
            $save->execute(['season_id' => $season->id()->value(), 'team_id' => $teamId, 'player_id' => $player->id()->value(), 'role' => $player->primaryPosition()->value, 'score' => $candidate['score']]);
            $total += $player->overallRating();
            if ($careerPlayers->byPlayer($player->id()) !== null) {
                $sourceKey = 'international|' . $player->id()->value() . '|first_call_up';
                if ($events->bySourceKey($sourceKey) === null) {
                    $event = CareerEvent::pending('career-' . hash('sha256', $sourceKey), $player->id(), $season->id(), $season->startDate(), $sourceKey, 'international', 'first_call_up', 'First senior call-up', 'A first senior national-team call-up opened a new international chapter of the Career.', [], ['historyworthy' => true, 'newsworthy' => true, 'competition' => 'international'])->resolved('record', ['milestone' => 'first_call_up', 'history' => 'Received a first senior international call-up.']);
                    $events->saveInTransaction($event);
                    $this->pulse?->recordAchievement($database, $player->id(), $season->startDate(), $sourceKey, 'First senior international call-up', 'major');
                }
            }
        }
        $coverage = count(array_filter($groups, static fn (array $group): bool => $group !== [])) * 2;
        $strength = (int) round($total / count($chosen)) + $coverage;
        $database->connection()->prepare('UPDATE ' . self::TEAMS . ' SET strength = :strength WHERE id = :id')->execute(['strength' => $strength, 'id' => $teamId]);
    }

    /** @return array<string,true> */
    private function unavailablePlayerIds(DatabaseInterface $database, SimulationDate $date): array
    {
        new PlayerAvailabilityRepository($database);
        $result = [];
        $injuries = $database->connection()->prepare("SELECT player_id FROM player_injuries WHERE status = 'active' AND start_date <= :date AND recovery_date > :date");
        $injuries->execute(['date' => $date->toIsoString()]);
        foreach ($injuries->fetchAll(PDO::FETCH_COLUMN) as $id) { $result[(string) $id] = true; }
        $fatigue = $database->connection()->query('SELECT player_id FROM player_availability_state WHERE fatigue >= 85')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($fatigue as $id) { $result[(string) $id] = true; }

        return $result;
    }

    /** @return array<string,float> */
    private function formScores(DatabaseInterface $database): array
    {
        try {
            $rows = $database->connection()->query('SELECT player_id, AVG(evaluation_score) score FROM career_match_evaluations GROUP BY player_id')->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) { return []; }
        $result = [];
        foreach ($rows as $row) { $result[(string) $row['player_id']] = (float) $row['score']; }

        return $result;
    }

    /** @return array<string,int> */
    private function clubRoleWeights(DatabaseInterface $database, string $seasonId): array
    {
        try { $rows = $database->connection()->query("SELECT player_id, role FROM club_squad_memberships WHERE season_id = " . $database->connection()->quote($seasonId))->fetchAll(PDO::FETCH_ASSOC); } catch (\PDOException) { return []; }
        $weights = [];
        foreach ($rows as $row) { $weights[(string) $row['player_id']] = max($weights[(string) $row['player_id']] ?? 0, SquadRole::from((string) $row['role'])->weight()); }

        return $weights;
    }

    private function positionGroup(Player $player): string
    {
        return match ($player->primaryPosition()->value) { 'GK' => 'goalkeeper', 'CB', 'LB', 'RB' => 'defensive', 'DM', 'CM', 'AM' => 'midfield', 'LW', 'RW', 'ST' => 'attacking', default => 'midfield' };
    }
}
