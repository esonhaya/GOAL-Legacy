<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Club;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Club\Persistence\ClubCaptaincyRepository;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

/**
 * Owns only Club captaincy appointments. It is intentionally not a leadership
 * attribute or a Match modifier. Appointment writes happen on explicit game
 * lifecycle paths; read methods never create a row.
 */
final class ClubCaptaincyService
{
    public const CAPTAIN = 'captain';
    public const VICE_CAPTAIN = 'vice_captain';
    public const NONE = 'none';

    public function __construct(private readonly ClubService $clubs)
    {
    }

    public function repository(DatabaseInterface $database, bool $initialize = false): ClubCaptaincyRepository
    {
        return new ClubCaptaincyRepository($database, $initialize);
    }

    /** Explicit initialization/review path. No page or read-model caller should use this. */
    public function ensureClub(DatabaseInterface $database, string $clubId, SeasonId|string $seasonId, SimulationDate|string $appointedDate, SeasonId|string|null $previousSeasonId = null): void
    {
        $season = $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId);
        $date = $appointedDate instanceof SimulationDate ? $appointedDate : SimulationDate::fromIsoString($appointedDate);
        $repository = $this->repository($database, true);
        $memberships = $this->clubs->squadRepository($database)->byClub($clubId, $season);
        $players = $this->playersByMembership($database, $memberships);
        $current = $repository->get($clubId, $season->value());
        $previous = $current === null && $previousSeasonId !== null
            ? $repository->get($clubId, $previousSeasonId instanceof SeasonId ? $previousSeasonId->value() : $previousSeasonId)
            : null;
        $activeIds = array_fill_keys(array_keys($players), true);
        $captain = is_array($current) && isset($activeIds[(string) ($current['captain_player_id'] ?? '')])
            ? (string) $current['captain_player_id']
            : (is_array($previous) && isset($activeIds[(string) ($previous['captain_player_id'] ?? '')]) ? (string) $previous['captain_player_id'] : null);
        $vice = is_array($current) && isset($activeIds[(string) ($current['vice_captain_player_id'] ?? '')])
            ? (string) $current['vice_captain_player_id']
            : (is_array($previous) && isset($activeIds[(string) ($previous['vice_captain_player_id'] ?? '')]) ? (string) $previous['vice_captain_player_id'] : null);
        $ordered = $this->orderedCandidates($database, $clubId, $season, $date, $memberships, $players);
        if ($captain === null) {
            $captain = $ordered[0]['player_id'] ?? null;
        }
        if ($vice === null || $vice === $captain) {
            $vice = null;
            foreach ($ordered as $candidate) {
                if ($candidate['player_id'] !== $captain) {
                    $vice = $candidate['player_id'];
                    break;
                }
            }
        }
        $repository->save([
            'club_id' => $clubId,
            'season_id' => $season->value(),
            'captain_player_id' => $captain,
            'vice_captain_player_id' => $vice,
            'appointed_date' => $current['appointed_date'] ?? $date->toIsoString(),
        ]);
    }

    /** Explicit world initialization/review path. */
    public function ensureSeason(DatabaseInterface $database, Season $season, SimulationDate|string|null $appointedDate = null, SeasonId|string|null $previousSeasonId = null): void
    {
        $date = $appointedDate instanceof SimulationDate
            ? $appointedDate
            : ($appointedDate === null ? $season->startDate() : SimulationDate::fromIsoString($appointedDate));
        if ($previousSeasonId === null && $this->tableExists($database, 'season_records')) {
            $statement = $database->connection()->prepare('SELECT id FROM season_records WHERE end_date < :start_date ORDER BY end_date DESC, id DESC LIMIT 1');
            $statement->execute(['start_date' => $season->startDate()->toIsoString()]);
            $previousSeasonId = $statement->fetchColumn() ?: null;
        }
        foreach ($this->clubs->repository($database)->all() as $club) {
            $this->ensureClub($database, $club->id()->value(), $season->id(), $date, $previousSeasonId);
        }
    }

    /** Repair Club-scoped appointments after an explicit movement/free-agent write. */
    public function reconcileClubs(DatabaseInterface $database, array $clubIds, SeasonId|string $seasonId, SimulationDate|string $date): void
    {
        $season = $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId);
        $appointmentDate = $date instanceof SimulationDate ? $date : SimulationDate::fromIsoString($date);
        foreach (array_values(array_unique(array_map('strval', $clubIds))) as $clubId) {
            $this->ensureClub($database, $clubId, $season, $appointmentDate);
        }
    }

    /** Read-only current Club appointment, with a deterministic legacy fallback in memory. */
    public function current(DatabaseInterface $database, string $clubId, SeasonId|string $seasonId, ?SimulationDate $date = null): array
    {
        $season = $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId);
        $repository = $this->repository($database, false);
        $row = $repository->get($clubId, $season->value());
        if (is_array($row)) {
            $memberships = $this->clubs->squadRepository($database)->byClub($clubId, $season);
            $players = $this->playersByMembership($database, $memberships);
            $activeIds = array_fill_keys(array_keys($players), true);
            $captain = $row['captain_player_id'] === null ? null : (string) $row['captain_player_id'];
            $vice = $row['vice_captain_player_id'] === null ? null : (string) $row['vice_captain_player_id'];
            if (($captain === null || isset($activeIds[$captain])) && ($vice === null || ($vice !== $captain && isset($activeIds[$vice])))) {
                return $this->normalize($row);
            }
            $ordered = $this->orderedCandidates($database, $clubId, $season, $date, $memberships, $players);
            $captain = isset($activeIds[$captain ?? '']) ? $captain : ($ordered[0]['player_id'] ?? null);
            $vice = isset($activeIds[$vice ?? '']) && $vice !== $captain ? $vice : null;
            if ($vice === null) {
                foreach ($ordered as $candidate) {
                    if ($candidate['player_id'] !== $captain) { $vice = $candidate['player_id']; break; }
                }
            }

            return ['club_id' => $clubId, 'season_id' => $season->value(), 'captain_player_id' => $captain, 'vice_captain_player_id' => $vice, 'appointed_date' => $row['appointed_date'] === null ? null : (string) $row['appointed_date'], 'legacy_fallback' => true];
        }
        $memberships = $this->clubs->squadRepository($database)->byClub($clubId, $season);
        $players = $this->playersByMembership($database, $memberships);
        $ordered = $this->orderedCandidates($database, $clubId, $season, $date, $memberships, $players);

        return [
            'club_id' => $clubId,
            'season_id' => $season->value(),
            'captain_player_id' => $ordered[0]['player_id'] ?? null,
            'vice_captain_player_id' => $ordered[1]['player_id'] ?? null,
            'appointed_date' => null,
            'legacy_fallback' => true,
        ];
    }

    /** @return array{status:string,label:string,club_id:?string,season_id:?string,appointed_date:?string,history:list<array<string,mixed>>,reasons:list<string>} */
    public function contextForPlayer(DatabaseInterface $database, string $playerId, ?string $clubId, SeasonId|string|null $seasonId, ?SimulationDate $date = null): array
    {
        $history = $this->repository($database, false)->byPlayer($playerId);
        if ($clubId === null || $seasonId === null) {
            return ['status' => self::NONE, 'label' => 'No current Club captaincy', 'club_id' => null, 'season_id' => null, 'appointed_date' => null, 'history' => $history, 'reasons' => []];
        }
        $current = $this->current($database, $clubId, $seasonId, $date);
        $status = (string) ($current['captain_player_id'] ?? '') === $playerId
            ? self::CAPTAIN
            : ((string) ($current['vice_captain_player_id'] ?? '') === $playerId ? self::VICE_CAPTAIN : self::NONE);
        $label = match ($status) {
            self::CAPTAIN => 'Club Captain',
            self::VICE_CAPTAIN => 'Vice-Captain',
            default => 'No current captaincy appointment',
        };
        $reasons = [];
        if ($status !== self::NONE) {
            $reasons[] = 'Established Club standing';
            $reasons[] = 'Club appointment is stable at meaningful review points';
        }

        return ['status' => $status, 'label' => $label, 'club_id' => $clubId, 'season_id' => $current['season_id'], 'appointed_date' => $current['appointed_date'], 'history' => $history, 'reasons' => $reasons];
    }

    /** @param list<string> $selectedPlayerIds */
    public function matchCaptain(DatabaseInterface $database, string $clubId, SeasonId|string $seasonId, array $selectedPlayerIds, ?SimulationDate $date = null): ?string
    {
        $selected = array_fill_keys(array_values(array_unique(array_map('strval', $selectedPlayerIds))), true);
        if ($selected === []) {
            return null;
        }
        $appointment = $this->current($database, $clubId, $seasonId, $date);
        foreach (['captain_player_id', 'vice_captain_player_id'] as $field) {
            $candidate = $appointment[$field] ?? null;
            if (is_string($candidate) && isset($selected[$candidate])) {
                return $candidate;
            }
        }
        $season = $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId);
        $memberships = $this->clubs->squadRepository($database)->byClub($clubId, $season);
        $players = $this->playersByMembership($database, $memberships);
        $ordered = $this->orderedCandidates($database, $clubId, $season, $date, $memberships, $players);
        foreach ($ordered as $candidate) {
            if (isset($selected[$candidate['player_id']])) {
                return $candidate['player_id'];
            }
        }

        sort($selectedPlayerIds, SORT_STRING);

        return isset($selectedPlayerIds[0]) ? (string) $selectedPlayerIds[0] : null;
    }

    /** @return list<array<string,mixed>> */
    public function historyForPlayer(DatabaseInterface $database, string $playerId): array
    {
        return $this->repository($database, false)->byPlayer($playerId);
    }

    /** @param list<\Goal\Legacy\Modules\Club\Domain\ClubSquadMembership> $memberships @param array<string,Player> $players @return list<array{player_id:string}> */
    private function orderedCandidates(DatabaseInterface $database, string $clubId, SeasonId $season, ?SimulationDate $date, array $memberships, array $players): array
    {
        if ($players === []) {
            return [];
        }
        $tenure = [];
        $statement = $database->connection()->prepare('SELECT player_id, COUNT(DISTINCT season_id) FROM club_squad_memberships WHERE club_id = :club_id GROUP BY player_id');
        $statement->execute(['club_id' => $clubId]);
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            $tenure[(string) $row[0]] = (int) $row[1];
        }
        $evidence = [];
        if ($this->tableExists($database, 'match_player_stats')) {
            $statement = $database->connection()->prepare('SELECT player_id, SUM(appeared), SUM(minutes) FROM match_player_stats WHERE club_id = :club_id GROUP BY player_id');
            $statement->execute(['club_id' => $clubId]);
            foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
                $evidence[(string) $row[0]] = ['appearances' => (int) $row[1], 'minutes' => (int) $row[2]];
            }
        }
        $roles = [];
        foreach ($memberships as $membership) {
            $roles[$membership->playerId()->value()] = $membership->role();
        }
        $rows = [];
        foreach ($players as $playerId => $player) {
            if ($player->isRetired()) {
                continue;
            }
            $role = $roles[$playerId] ?? SquadRole::Prospect;
            $stats = $evidence[$playerId] ?? ['appearances' => 0, 'minutes' => 0];
            $age = $date === null ? 0 : $player->ageAt($date);
            $seniorEvidence = $role !== SquadRole::Prospect || $age >= 23 || $stats['appearances'] >= 10 || ($tenure[$playerId] ?? 0) >= 2;
            $rows[] = ['player_id' => $playerId, 'eligible' => $seniorEvidence, 'role' => $role->weight(), 'tenure' => $tenure[$playerId] ?? 0, 'appearances' => $stats['appearances'], 'minutes' => $stats['minutes'], 'age' => $age, 'overall' => $player->overallRating()];
        }
        usort($rows, static fn (array $left, array $right): int => (($right['eligible'] <=> $left['eligible']) ?: ($right['role'] <=> $left['role']) ?: ($right['tenure'] <=> $left['tenure']) ?: ($right['appearances'] <=> $left['appearances']) ?: ($right['minutes'] <=> $left['minutes']) ?: ($right['age'] <=> $left['age']) ?: ($right['overall'] <=> $left['overall']) ?: strcmp($left['player_id'], $right['player_id'])));

        return array_map(static fn (array $row): array => ['player_id' => (string) $row['player_id']], $rows);
    }

    /** @param list<\Goal\Legacy\Modules\Club\Domain\ClubSquadMembership> $memberships @return array<string,Player> */
    private function playersByMembership(DatabaseInterface $database, array $memberships): array
    {
        $players = [];
        foreach ((new PlayerRepository($database))->byIds(array_map(static fn ($membership): string => $membership->playerId()->value(), $memberships)) as $player) {
            $players[$player->id()->value()] = $player;
        }

        return $players;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalize(array $row): array
    {
        return ['club_id' => (string) $row['club_id'], 'season_id' => (string) $row['season_id'], 'captain_player_id' => $row['captain_player_id'] === null ? null : (string) $row['captain_player_id'], 'vice_captain_player_id' => $row['vice_captain_player_id'] === null ? null : (string) $row['vice_captain_player_id'], 'appointed_date' => $row['appointed_date'] === null ? null : (string) $row['appointed_date'], 'legacy_fallback' => false];
    }

    private function tableExists(DatabaseInterface $database, string $table): bool
    {
        $statement = $database->connection()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table");
        $statement->execute(['table' => $table]);

        return $statement->fetchColumn() !== false;
    }
}
