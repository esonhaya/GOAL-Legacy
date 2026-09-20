<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Persistence\ControlledMatchPositionRepository;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Match\PlayerMatchRatingService;
use Goal\Legacy\Modules\Player\Domain\Injury;
use Goal\Legacy\Modules\Player\Domain\InjurySeverity;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

/**
 * Read-only Career context around the canonical availability and Match facts.
 *
 * P2-017 owns injury dates, availability, fatigue, and readiness. This class
 * only explains that state for the controlled Player and identifies a first
 * real appearance after a meaningful injury. It never advances or persists
 * recovery state.
 */
final class CareerRecoveryService
{
    private const RETURN_CONTEXT_DAYS = 14;
    private const TRAINING_CONTEXT_DAYS = 7;

    /** @return array<string, mixed> */
    public function context(DatabaseInterface $database, PlayerId|string $playerId, SimulationDate $date): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $availability = (new PlayerAvailabilityService())->assess($database, $id, $date);
        $injuries = (new PlayerAvailabilityService())->injuries($database, $id);
        $active = $availability->injury();
        if ($active !== null) {
            $significance = $this->significance($active);

            return $this->baseContext(
                'active',
                $significance === 'MINOR' ? 'INJURED' : 'REHABILITATING',
                true,
                $significance,
                $active,
                $availability->readiness(),
                $this->activeMessage($active, $significance),
                null,
                $this->episodes($database, $id, $injuries, $date),
            );
        }

        $meaningful = array_values(array_filter($injuries, fn (Injury $injury): bool => $this->isMeaningful($injury)));
        usort($meaningful, static fn (Injury $left, Injury $right): int => strcmp($right->startDate()->toIsoString() . $right->id(), $left->startDate()->toIsoString() . $left->id()));
        $latest = null;
        foreach ($meaningful as $injury) {
            $end = $this->medicalEnd($injury);
            if (!$date->isBefore($end)) {
                $latest = $injury;
                break;
            }
        }
        if ($latest === null) {
            return $this->emptyContext();
        }

        $return = $this->firstReturnForInjury($database, $id, $latest, $date);
        $end = $this->medicalEnd($latest);
        $daysSinceEnd = $end->daysUntil($date);
        if ($return !== null) {
            $daysSinceReturn = SimulationDate::fromIsoString((string) $return['date'])->daysUntil($date);
            $visible = $daysSinceReturn >= 0 && $daysSinceReturn <= self::RETURN_CONTEXT_DAYS;

            return $this->baseContext(
                'returned',
                'RETURNED',
                $visible,
                $this->significance($latest),
                $latest,
                $availability->readiness(),
                $visible ? 'Back in Match football after the recorded injury.' : null,
                $return,
                $this->episodes($database, $id, $injuries, $date),
            );
        }

        $phase = $daysSinceEnd <= self::TRAINING_CONTEXT_DAYS
            ? 'RETURNING_TO_TRAINING'
            : ($availability->isAvailable() ? 'MATCH_READY' : 'AVAILABLE_NOT_READY');
        $visible = $daysSinceEnd <= self::RETURN_CONTEXT_DAYS;

        return $this->baseContext(
            'recovering',
            $phase,
            $visible,
            $this->significance($latest),
            $latest,
            $availability->readiness(),
            $visible ? $this->recoveryMessage($phase, $availability->isAvailable()) : null,
            null,
            $this->episodes($database, $id, $injuries, $date),
        );
    }

    /**
     * Identifies a first actual appearance after a meaningful injury. An
     * unused substitute has no Match-stat row with an appearance and cannot
     * satisfy this boundary.
     *
     * @return array<string, mixed>|null
     */
    public function returnForMatch(DatabaseInterface $database, GameMatch $match, PlayerId|string $playerId): ?array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $current = null;
        foreach ((new PlayerMatchStatRepository($database))->byMatch($match->id()) as $stat) {
            if ($stat->playerId()->value() === $id->value()) {
                $current = $stat;
                break;
            }
        }
        if ($current === null || !$current->appeared() || $current->minutes() < 1) {
            return null;
        }

        foreach ((new PlayerAvailabilityService())->injuries($database, $id) as $injury) {
            if (!$this->isMeaningful($injury)) {
                continue;
            }
            $candidate = $this->firstReturnForInjury($database, $id, $injury, $match->scheduledDate());
            if ($candidate !== null && ($candidate['match_id'] ?? null) === $match->id()->value()) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function episodes(DatabaseInterface $database, PlayerId $playerId, array $injuries, SimulationDate $asOf): array
    {
        $episodes = [];
        foreach ($injuries as $injury) {
            if (!$this->isMeaningful($injury)) {
                continue;
            }
            $episodes[] = [
                'injury' => $injury->toArray(),
                'significance' => $this->significance($injury),
                'medical_end_date' => $this->medicalEnd($injury)->toIsoString(),
                'first_match_back' => $this->firstReturnForInjury($database, $playerId, $injury, $asOf),
            ];
        }

        return $episodes;
    }

    /** @return array<string, mixed>|null */
    private function firstReturnForInjury(DatabaseInterface $database, PlayerId $playerId, Injury $injury, ?SimulationDate $asOf = null): ?array
    {
        $end = $this->medicalEnd($injury);
        $matches = new MatchRepository($database);
        $stats = new PlayerMatchStatRepository($database);
        $appearances = [];
        $playerStats = $stats->byPlayer($playerId);
        $matchesById = [];
        foreach ($matches->byIds(array_map(static fn ($stat): string => $stat->matchId()->value(), $playerStats)) as $match) {
            $matchesById[$match->id()->value()] = $match;
        }
        foreach ($playerStats as $stat) {
            if (!$stat->appeared() || $stat->minutes() < 1) {
                continue;
            }
            $match = $matchesById[$stat->matchId()->value()] ?? null;
            if ($match === null) {
                continue;
            }
            if ($match->status()->value !== 'completed' || $match->scheduledDate()->isBefore($end) || ($asOf !== null && $match->scheduledDate()->isAfter($asOf))) {
                continue;
            }
            $appearances[] = ['match' => $match, 'stat' => $stat];
        }
        usort($appearances, static fn (array $left, array $right): int => strcmp(
            $left['match']->scheduledDate()->toIsoString() . $left['match']->id()->value(),
            $right['match']->scheduledDate()->toIsoString() . $right['match']->id()->value(),
        ));
        $first = $appearances[0] ?? null;
        if ($first === null) {
            return null;
        }

        $match = $first['match'];
        $stat = $first['stat'];
        $player = (new PlayerRepository($database))->get($playerId);
        $position = (new ControlledMatchPositionRepository($database, false))->position($match->id(), $playerId) ?? $player->primaryPosition();
        $rating = (new PlayerMatchRatingService())->rate($stat, $position);
        $performance = [];
        $performance[] = $stat->started() ? 'returned to the starting XI' : 'returned as a substitute';
        if ($stat->goals() > 0) { $performance[] = 'scored on return'; }
        if ($stat->assists() > 0) { $performance[] = 'assisted on return'; }
        if ($rating !== null && $rating >= 7.5) { $performance[] = 'strong return'; }
        if ($rating !== null && $rating < 5.8) { $performance[] = 'difficult return'; }

        return [
            'date' => $match->scheduledDate()->toIsoString(),
            'match_id' => $match->id()->value(),
            'injury_id' => $injury->id(),
            'significance' => $this->significance($injury),
            'started' => $stat->started(),
            'minutes' => $stat->minutes(),
            'goals' => $stat->goals(),
            'assists' => $stat->assists(),
            'rating' => $rating,
            'performance' => $performance,
        ];
    }

    private function isMeaningful(Injury $injury): bool
    {
        return $injury->severity() !== InjurySeverity::Minor;
    }

    private function significance(Injury $injury): string
    {
        return match ($injury->severity()) {
            InjurySeverity::Minor => 'MINOR',
            InjurySeverity::Moderate => 'NOTABLE',
            InjurySeverity::Major => 'MAJOR',
        };
    }

    private function medicalEnd(Injury $injury): SimulationDate
    {
        return $injury->actualRecoveryDate() ?? $injury->recoveryDate();
    }

    /** @return array<string, mixed> */
    private function baseContext(string $status, string $phase, bool $visible, string $significance, Injury $injury, array $readiness, ?string $message, ?array $return, array $episodes): array
    {
        return [
            'status' => $status,
            'phase' => $phase,
            'visible' => $visible,
            'significance' => $significance,
            'injury' => $injury->toArray(),
            'medical_end_date' => $this->medicalEnd($injury)->toIsoString(),
            'readiness' => $readiness,
            'message' => $message,
            'first_match_back' => $return,
            'episodes' => $episodes,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyContext(): array
    {
        return [
            'status' => 'clear',
            'phase' => null,
            'visible' => false,
            'significance' => null,
            'injury' => null,
            'medical_end_date' => null,
            'readiness' => null,
            'message' => null,
            'first_match_back' => null,
            'episodes' => [],
        ];
    }

    private function activeMessage(Injury $injury, string $significance): string
    {
        return $significance === 'MINOR'
            ? 'Unavailable until the recorded recovery boundary.'
            : 'The recorded injury is being managed through the existing recovery clock.';
    }

    private function recoveryMessage(string $phase, bool $available): string
    {
        return match ($phase) {
            'RETURNING_TO_TRAINING' => 'Medical recovery is recorded; workload and readiness still need to settle.',
            'AVAILABLE_NOT_READY' => 'Available again, but current readiness still calls for a managed return.',
            default => $available ? 'Available for selection after the recorded recovery.' : 'Recovery is complete, but selection availability remains limited.',
        };
    }
}
