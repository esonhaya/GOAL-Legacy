<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Club;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Club\Persistence\SetPieceResponsibilityRepository;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

/**
 * Owns Club-scoped responsibility for the supported penalty category.
 * Direct free kicks and corners remain deferred until their Match event
 * architecture can consume them without fabricated football.
 */
final class SetPieceResponsibilityService
{
    public const PENALTY = 'penalty';
    public const PRIMARY = 'primary';
    public const FALLBACK = 'fallback';
    public const NONE = 'none';

    public function __construct(private readonly ClubService $clubs)
    {
    }

    public function repository(DatabaseInterface $database, bool $initialize = false): SetPieceResponsibilityRepository
    {
        return new SetPieceResponsibilityRepository($database, $initialize);
    }

    /** Explicit controlled-career initialization/review path. */
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
        $active = $this->activePlayerIds($players);
        $primary = $this->validOrNull($current, 'penalty_primary_player_id', $active)
            ?? $this->validOrNull($previous, 'penalty_primary_player_id', $active);
        $fallback = $this->validOrNull($current, 'penalty_fallback_player_id', $active)
            ?? $this->validOrNull($previous, 'penalty_fallback_player_id', $active);
        $ordered = $this->orderedPenaltyCandidates($database, $clubId, $memberships, $players);
        if ($primary === null) {
            $primary = $ordered[0]['player_id'] ?? null;
        }
        if ($fallback === null || $fallback === $primary) {
            $fallback = $this->firstOther($ordered, $primary);
        }

        $repository->save([
            'club_id' => $clubId,
            'season_id' => $season->value(),
            'penalty_primary_player_id' => $primary,
            'penalty_fallback_player_id' => $fallback,
            'appointed_date' => is_array($current) ? (string) $current['appointed_date'] : $date->toIsoString(),
        ]);
    }

    /** Explicit controlled-career Season review. This does not scan or persist NPC Clubs. */
    public function ensureControlledClub(DatabaseInterface $database, string $clubId, Season $season, SimulationDate|string|null $appointedDate = null, SeasonId|string|null $previousSeasonId = null): void
    {
        $date = $appointedDate instanceof SimulationDate
            ? $appointedDate
            : ($appointedDate === null ? $season->startDate() : SimulationDate::fromIsoString($appointedDate));
        $this->ensureClub($database, $clubId, $season->id(), $date, $previousSeasonId);
    }

    /** Explicit movement path; the old Club becomes invalid by membership and the new Club is reviewed. */
    public function reconcileClubs(DatabaseInterface $database, array $clubIds, SeasonId|string $seasonId, SimulationDate|string $date): void
    {
        $season = $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId);
        $appointmentDate = $date instanceof SimulationDate ? $date : SimulationDate::fromIsoString($date);
        foreach (array_values(array_unique(array_map('strval', $clubIds))) as $clubId) {
            $this->ensureClub($database, $clubId, $season, $appointmentDate);
        }
    }

    /** Read-only responsibility context. */
    public function contextForPlayer(DatabaseInterface $database, string $playerId, ?string $clubId, SeasonId|string|null $seasonId, ?SimulationDate $date = null): array
    {
        $history = $this->repository($database, false)->byPlayer($playerId);
        if ($clubId === null || $seasonId === null) {
            return $this->context(self::NONE, null, null, $history, []);
        }
        $current = $this->current($database, $clubId, $seasonId, $date);
        $status = $current['penalty_primary_player_id'] === $playerId
            ? self::PRIMARY
            : ($current['penalty_fallback_player_id'] === $playerId ? self::FALLBACK : self::NONE);
        $reasons = $status === self::NONE ? [] : ['Shooting is the canonical penalty execution evidence', 'Club responsibility is reviewed at stable lifecycle points'];

        return $this->context($status, $clubId, $current['season_id'], $history, $reasons, $current['appointed_date']);
    }

    /** @param list<string> $eligiblePlayerIds */
    public function matchTaker(DatabaseInterface $database, string $clubId, SeasonId|string $seasonId, array $eligiblePlayerIds, ?SimulationDate $date = null): ?string
    {
        $eligible = array_fill_keys(array_values(array_unique(array_map('strval', $eligiblePlayerIds))), true);
        if ($eligible === []) {
            return null;
        }
        $season = $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId);
        // Match execution is a hot path. A persisted appointment already
        // contains the stable Club decision, so do not rebuild the complete
        // squad candidate list for every ordinary Match.
        $row = $this->repository($database, false)->get($clubId, $season->value());
        if (is_array($row)) {
            foreach (['penalty_primary_player_id', 'penalty_fallback_player_id'] as $field) {
                $candidate = $row[$field] ?? null;
                if (is_string($candidate) && isset($eligible[$candidate])) {
                    return $candidate;
                }
            }
            $players = (new PlayerRepository($database))->byIds(array_keys($eligible));
            usort($players, static fn (Player $left, Player $right): int => (($right->attributes()->shooting() <=> $left->attributes()->shooting()) ?: ($right->overallRating() <=> $left->overallRating()) ?: strcmp($left->id()->value(), $right->id()->value())));
            $fallback = $players[0] ?? null;

            return $fallback?->id()->value();
        }
        $current = $this->current($database, $clubId, $season, $date);
        foreach (['penalty_primary_player_id', 'penalty_fallback_player_id'] as $field) {
            $candidate = $current[$field] ?? null;
            if (is_string($candidate) && isset($eligible[$candidate])) {
                return $candidate;
            }
        }

        $memberships = $this->clubs->squadRepository($database)->byClub($clubId, $season);
        $players = $this->playersByMembership($database, $memberships);
        foreach ($this->orderedPenaltyCandidates($database, $clubId, $memberships, $players) as $candidate) {
            if (isset($eligible[$candidate['player_id']])) {
                return $candidate['player_id'];
            }
        }
        $ids = array_keys($eligible);
        sort($ids, SORT_STRING);

        return $ids[0] ?? null;
    }

    /** @return array<string,mixed> */
    public function current(DatabaseInterface $database, string $clubId, SeasonId|string $seasonId, ?SimulationDate $date = null): array
    {
        $season = $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId);
        $row = $this->repository($database, false)->get($clubId, $season->value());
        $memberships = $this->clubs->squadRepository($database)->byClub($clubId, $season);
        $players = $this->playersByMembership($database, $memberships);
        $active = $this->activePlayerIds($players);
        if (is_array($row)) {
            $primary = $this->validOrNull($row, 'penalty_primary_player_id', $active);
            $fallback = $this->validOrNull($row, 'penalty_fallback_player_id', $active);
            if ($primary !== null && ($fallback === null || $fallback !== $primary)) {
                return [
                    'club_id' => $clubId,
                    'season_id' => $season->value(),
                    'penalty_primary_player_id' => $primary,
                    'penalty_fallback_player_id' => $fallback,
                    'appointed_date' => $row['appointed_date'] === null ? null : (string) $row['appointed_date'],
                    'legacy_fallback' => false,
                ];
            }
            $ordered = $this->orderedPenaltyCandidates($database, $clubId, $memberships, $players);
            $primary ??= $ordered[0]['player_id'] ?? null;
            $fallback = $fallback !== $primary ? $fallback : null;
            $fallback ??= $this->firstOther($ordered, $primary);

            return [
                'club_id' => $clubId,
                'season_id' => $season->value(),
                'penalty_primary_player_id' => $primary,
                'penalty_fallback_player_id' => $fallback,
                'appointed_date' => $row['appointed_date'] === null ? null : (string) $row['appointed_date'],
                'legacy_fallback' => true,
            ];
        }
        $ordered = $this->orderedPenaltyCandidates($database, $clubId, $memberships, $players);

        return [
            'club_id' => $clubId,
            'season_id' => $season->value(),
            'penalty_primary_player_id' => $ordered[0]['player_id'] ?? null,
            'penalty_fallback_player_id' => $ordered[1]['player_id'] ?? null,
            'appointed_date' => null,
            'legacy_fallback' => true,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function historyForPlayer(DatabaseInterface $database, string $playerId): array
    {
        return $this->repository($database, false)->byPlayer($playerId);
    }

    /** @return array<string,mixed> */
    private function context(string $status, ?string $clubId, ?string $seasonId, array $history, array $reasons, ?string $appointedDate = null): array
    {
        $label = match ($status) {
            self::PRIMARY => 'Primary penalty taker',
            self::FALLBACK => 'Penalty fallback',
            default => 'No penalty responsibility',
        };

        return [
            'category' => self::PENALTY,
            'status' => $status,
            'label' => $label,
            'club_id' => $clubId,
            'season_id' => $seasonId,
            'appointed_date' => $appointedDate,
            'history' => $history,
            'reasons' => $reasons,
            'deferred_categories' => ['direct_free_kick', 'corner'],
        ];
    }

    /** @param array<string,mixed>|null $row @param array<string,bool> $active */
    private function validOrNull(?array $row, string $field, array $active): ?string
    {
        $value = is_array($row) && is_string($row[$field] ?? null) ? $row[$field] : null;

        return $value !== null && isset($active[$value]) ? $value : null;
    }

    /** @param list<array{player_id:string}> $ordered */
    private function firstOther(array $ordered, ?string $primary): ?string
    {
        foreach ($ordered as $candidate) {
            if ($candidate['player_id'] !== $primary) {
                return $candidate['player_id'];
            }
        }

        return null;
    }

    /** @param list<\Goal\Legacy\Modules\Club\Domain\ClubSquadMembership> $memberships @param array<string,Player> $players @return list<array{player_id:string}> */
    private function orderedPenaltyCandidates(DatabaseInterface $database, string $clubId, array $memberships, array $players): array
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
            $attributes = $player->attributes();
            $stats = $evidence[$playerId] ?? ['appearances' => 0, 'minutes' => 0];
            $role = $roles[$playerId] ?? SquadRole::Prospect;
            $rows[] = [
                'player_id' => $playerId,
                'shooting' => $attributes->shooting(),
                'role' => $role->weight(),
                'tenure' => $tenure[$playerId] ?? 0,
                'appearances' => $stats['appearances'],
                'minutes' => $stats['minutes'],
                'overall' => $player->overallRating(),
            ];
        }
        usort($rows, static fn (array $left, array $right): int => (($right['shooting'] <=> $left['shooting']) ?: ($right['role'] <=> $left['role']) ?: ($right['tenure'] <=> $left['tenure']) ?: ($right['appearances'] <=> $left['appearances']) ?: ($right['minutes'] <=> $left['minutes']) ?: ($right['overall'] <=> $left['overall']) ?: strcmp($left['player_id'], $right['player_id'])));

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

    /** @param array<string,Player> $players @return array<string,bool> */
    private function activePlayerIds(array $players): array
    {
        $active = [];
        foreach ($players as $playerId => $player) {
            if (!$player->isRetired()) {
                $active[$playerId] = true;
            }
        }

        return $active;
    }

    private function tableExists(DatabaseInterface $database, string $table): bool
    {
        $statement = $database->connection()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table");
        $statement->execute(['table' => $table]);

        return $statement->fetchColumn() !== false;
    }
}
