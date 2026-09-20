<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Persistence\PlayerDisciplineRepository;

/**
 * Owns only competition disciplinary eligibility. Match statistics own card
 * facts; PlayerAvailabilityService owns medical availability.
 */
final class PlayerDisciplineService
{
    public const YELLOW_THRESHOLD = 5;
    public const RED_BAN_MATCHES = 1;

    /** @return array{eligible:bool,scope:?string,scope_label:?string,remaining:int,reason:?string,reason_label:?string} */
    public function eligibility(DatabaseInterface $database, GameMatch $match, string|PlayerId $playerId): array
    {
        $scope = $this->scopeForMatch($database, $match);
        if ($scope === null) {
            return ['eligible' => true, 'scope' => null, 'scope_label' => null, 'remaining' => 0, 'reason' => null, 'reason_label' => null];
        }
        $id = $playerId instanceof PlayerId ? $playerId->value() : $playerId;
        $state = (new PlayerDisciplineRepository($database))->find($id, $scope);
        $remaining = (int) ($state['suspension_matches_remaining'] ?? 0);
        $reason = $remaining > 0 ? (string) ($state['suspension_reason'] ?? 'disciplinary_suspension') : null;

        return [
            'eligible' => $remaining < 1,
            'scope' => $scope,
            'scope_label' => $this->scopeLabel($scope),
            'remaining' => $remaining,
            'reason' => $reason,
            'reason_label' => $reason === null ? null : $this->reasonLabel($reason),
        ];
    }

    /** @param list<string> $playerIds @return array<string, array{eligible:bool,scope:?string,scope_label:?string,remaining:int,reason:?string,reason_label:?string}> */
    public function eligibilities(DatabaseInterface $database, GameMatch $match, array $playerIds): array
    {
        $scope = $this->scopeForMatch($database, $match);
        $result = [];
        foreach (array_values(array_unique(array_map('strval', $playerIds))) as $playerId) {
            $result[$playerId] = ['eligible' => true, 'scope' => $scope, 'scope_label' => $scope === null ? null : $this->scopeLabel($scope), 'remaining' => 0, 'reason' => null, 'reason_label' => null];
        }
        if ($scope === null || $result === []) {
            return $result;
        }
        foreach ((new PlayerDisciplineRepository($database))->activeByPlayers($scope, array_keys($result)) as $playerId => $state) {
            $reason = (string) ($state['suspension_reason'] ?? 'disciplinary_suspension');
            $result[$playerId] = ['eligible' => false, 'scope' => $scope, 'scope_label' => $this->scopeLabel($scope), 'remaining' => (int) $state['suspension_matches_remaining'], 'reason' => $reason, 'reason_label' => $this->reasonLabel($reason)];
        }

        return $result;
    }

    /**
     * Process one completed detailed Match. The source table is deliberately
     * only an idempotency boundary for active/card-bearing Players, not a
     * per-Match eligibility history.
     */
    public function processCompletedMatchInTransaction(DatabaseInterface $database, GameMatch $match): void
    {
        if ($match->status() !== MatchStatus::Completed) {
            return;
        }
        $scope = $this->scopeForMatch($database, $match);
        if ($scope === null) {
            return;
        }
        $repository = new PlayerDisciplineRepository($database);
        $selections = (new MatchSelectionRepository($database))->byMatch($match->id());
        $stats = (new PlayerMatchStatRepository($database))->byMatch($match->id());
        $statsByPlayer = [];
        foreach ($stats as $stat) {
            $statsByPlayer[$stat->playerId()->value()] = $stat;
        }
        $playerIds = array_keys($statsByPlayer);
        foreach ($selections as $selection) {
            $playerIds[] = $selection->playerId()->value();
        }
        $playerIds = array_values(array_unique($playerIds));
        foreach ($playerIds as $playerId) {
            $state = $repository->find($playerId, $scope);
            $stat = $statsByPlayer[$playerId] ?? null;
            $hasCard = $stat !== null && ($stat->yellowCards() > 0 || $stat->redCards() > 0);
            $hasActiveBan = (int) ($state['suspension_matches_remaining'] ?? 0) > 0;
            if (!$hasCard && !$hasActiveBan) {
                continue;
            }
            if ($repository->processed($playerId, $match->id()->value(), $scope)) {
                continue;
            }
            $state = $this->normaliseState($state, $playerId, $scope, $match);
            if ((int) $state['suspension_matches_remaining'] > 0) {
                // A completed applicable fixture serves one Match even when
                // the Player was injured or suspended and therefore absent.
                $state['suspension_matches_remaining'] = max(0, (int) $state['suspension_matches_remaining'] - 1);
            }
            if ($stat !== null && $stat->redCards() > 0) {
                $state['suspension_matches_remaining'] = max((int) $state['suspension_matches_remaining'], self::RED_BAN_MATCHES);
                $state['yellow_count'] = 0;
                $state['suspension_reason'] = 'red_card';
                $state['source_match_id'] = $match->id()->value();
                $state['source_competition_id'] = $match->competitionId()->value();
            } elseif ($stat !== null && $stat->yellowCards() > 0) {
                $state['yellow_count'] += $stat->yellowCards();
                if ($state['yellow_count'] >= self::YELLOW_THRESHOLD) {
                    $state['suspension_matches_remaining'] = max((int) $state['suspension_matches_remaining'], self::RED_BAN_MATCHES);
                    $state['yellow_count'] = 0;
                    $state['suspension_reason'] = 'yellow_accumulation';
                    $state['source_match_id'] = $match->id()->value();
                    $state['source_competition_id'] = $match->competitionId()->value();
                }
            }
            $repository->save($state);
            $repository->markProcessed($playerId, $match->id()->value(), $scope, $match->scheduledDate()->toIsoString());
        }
    }

    /** @return array{active:bool,status_label:string,suspensions:list<array<string,mixed>>,message:?string} */
    public function context(DatabaseInterface $database, string|PlayerId $playerId): array
    {
        $id = $playerId instanceof PlayerId ? $playerId->value() : $playerId;
        $states = (new PlayerDisciplineRepository($database))->byPlayer($id);
        $suspensions = [];
        foreach ($states as $state) {
            $remaining = (int) ($state['suspension_matches_remaining'] ?? 0);
            if ($remaining < 1) {
                continue;
            }
            $scope = (string) $state['scope'];
            $reason = (string) ($state['suspension_reason'] ?? 'disciplinary_suspension');
            $suspensions[] = [
                'scope' => $scope,
                'scope_label' => $this->scopeLabel($scope),
                'remaining' => $remaining,
                'reason' => $reason,
                'reason_label' => $this->reasonLabel($reason),
                'message' => $this->reasonLabel($reason) . ' — ' . $remaining . ' applicable Match' . ($remaining === 1 ? '' : 'es') . ' remaining.',
            ];
        }
        usort($suspensions, static fn (array $a, array $b): int => strcmp((string) $a['scope'], (string) $b['scope']));

        return [
            'active' => $suspensions !== [],
            'status_label' => $suspensions === [] ? 'ELIGIBLE' : 'SUSPENDED',
            'suspensions' => $suspensions,
            'message' => $suspensions[0]['message'] ?? null,
        ];
    }

    public function scopeForMatch(DatabaseInterface $database, GameMatch $match): ?string
    {
        try {
            $statement = $database->connection()->prepare('SELECT type FROM competition_records WHERE id = :id');
            $statement->execute(['id' => $match->competitionId()->value()]);
            $type = $statement->fetchColumn();
        } catch (\PDOException) {
            return null;
        }
        if (!is_string($type)) {
            return null;
        }

        return match ($type) {
            CompetitionType::DomesticLeague->value => 'domestic_league',
            CompetitionType::DomesticCup->value => 'domestic_cup',
            CompetitionType::Continental->value => 'europe',
            CompetitionType::International->value => 'international',
            default => null,
        };
    }

    public function scopeLabel(string $scope): string
    {
        return match ($scope) {
            'domestic_league' => 'League',
            'domestic_cup' => 'Domestic Cup',
            'europe' => 'Europe',
            'international' => 'International',
            default => 'Competition',
        };
    }

    private function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'yellow_accumulation' => 'Yellow-card accumulation suspension',
            'red_card' => 'Red-card suspension',
            default => 'Disciplinary suspension',
        };
    }

    /** @return array<string, mixed> */
    private function normaliseState(?array $state, string $playerId, string $scope, GameMatch $match): array
    {
        $state ??= [];
        $cycle = $match->seasonId()->value();

        return [
            'player_id' => $playerId,
            'scope' => $scope,
            'accumulation_cycle' => $cycle,
            'yellow_count' => (string) ($state['accumulation_cycle'] ?? '') === $cycle ? (int) ($state['yellow_count'] ?? 0) : 0,
            'suspension_matches_remaining' => (int) ($state['suspension_matches_remaining'] ?? 0),
            'suspension_reason' => $state['suspension_reason'] ?? null,
            'source_match_id' => $state['source_match_id'] ?? null,
            'source_competition_id' => $state['source_competition_id'] ?? null,
            'updated_date' => $match->scheduledDate()->toIsoString(),
        ];
    }
}
