<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Match\StandingsService;
use Goal\Legacy\Modules\Player\Domain\CareerEvent;
use Goal\Legacy\Modules\Player\Domain\CareerEventStatus;
use Goal\Legacy\Modules\Player\Domain\CareerPriority;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\TrainingFocus;
use Goal\Legacy\Modules\Player\Domain\TrainingRequest;
use Goal\Legacy\Modules\Player\Persistence\CareerEventRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerPriorityRepository;
use Goal\Legacy\Modules\Player\Finance\PlayerFinanceService;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use RuntimeException;

/** Owns the persisted, bounded layer between controlled Matches. */
final class CareerExperienceService
{
    public function __construct(private readonly PlayerDevelopmentService $development, private readonly TrainingService $training, private readonly ?ClubService $clubs = null, private readonly ?PlayerFinanceService $finance = null) {}

    public function priority(DatabaseInterface $database, PlayerId|string $playerId): CareerPriority
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        return (new PlayerPriorityRepository($database))->current($id);
    }

    public function setPriority(DatabaseInterface $database, PlayerId|string $playerId, CareerPriority|string $priority, SimulationDate $date): CareerPriority
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $value = $priority instanceof CareerPriority ? $priority : CareerPriority::fromInput($priority);
        $database->transaction(function () use ($database, $id, $value, $date): void {
            (new PlayerPriorityRepository($database))->saveInTransaction($id, $value, $date);
        });
        return $value;
    }

    public function setTrainingFocus(DatabaseInterface $database, PlayerId|string $playerId, TrainingFocus|string $focus, SimulationDate $date): TrainingFocus
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $value = $focus instanceof TrainingFocus ? $focus : TrainingFocus::fromInput($focus);
        $database->transaction(function () use ($database, $id, $value, $date): void {
            $this->development->setTrainingFocusInTransaction($database, $id, $value, $date);
        });
        return $value;
    }

    public function pendingEvent(DatabaseInterface $database, PlayerId|string $playerId): ?CareerEvent
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        return (new CareerEventRepository($database))->pendingForPlayer($id)[0] ?? null;
    }

    /** @return list<CareerEvent> */
    public function lifeHistory(DatabaseInterface $database, PlayerId|string $playerId, int $limit = 20): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        return (new CareerEventRepository($database))->resolvedForPlayer($id, $limit);
    }

    /** @return list<array<string, mixed>> */
    public function eventCatalog(): array { return CareerEventCatalog::all(); }

    /**
     * Create at most one event for a player in a calendar month. The source
     * key remains compatible with DOMAIN-040, while selection now prefers
     * contextual situations over the generic fallback pool.
     * @param array<string, mixed> $summary
     */
    public function ensureEvent(DatabaseInterface $database, PlayerId|string $playerId, SeasonId $seasonId, SimulationDate $date, array $summary): ?CareerEvent
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $club = is_array($summary['current_club'] ?? null) ? $summary['current_club'] : null;
        $clubId = is_array($club) ? (string) ($club['id'] ?? '') : '';
        $isFreeAgent = $clubId === '' && ($summary['current_contract'] ?? null) === null;
        if ($clubId === '' && !$isFreeAgent) { return null; }
        $completed = [];
        if (!$isFreeAgent) {
            $matches = (new MatchRepository($database))->byClub(new ClubId($clubId), $seasonId);
            $completed = array_values(array_filter($matches, static fn ($match): bool => $match->status() === MatchStatus::Completed && !$match->scheduledDate()->isAfter($date)));
            if ($completed === []) { return null; }
        }
        $sourceKey = $id->value() . '|' . $seasonId->value() . '|' . sprintf('%04d-%02d', $date->year(), $date->month());
        $repository = new CareerEventRepository($database);
        $existing = $repository->bySourceKey($sourceKey);
        if ($existing !== null) { return $existing->status() === CareerEventStatus::Pending ? $existing : null; }
        $signals = $this->signals($database, $id, $seasonId, $summary, $date, $completed === [] ? null : $completed[array_key_last($completed)]);
        $priority = $this->priority($database, $id);
        $resolved = $repository->resolvedForPlayer($id, 250);
        $definition = $this->selectDefinition($sourceKey, $priority, $signals, $resolved, $date, $clubId);
        if ($definition === null) { return null; }

        return $database->transaction(function () use ($database, $id, $seasonId, $date, $summary, $sourceKey, $priority, $definition, $signals, $clubId): ?CareerEvent {
            $repository = new CareerEventRepository($database);
            $existing = $repository->bySourceKey($sourceKey);
            if ($existing !== null) { return $existing->status() === CareerEventStatus::Pending ? $existing : null; }
            $club = is_array($summary['current_club'] ?? null) ? (string) ($summary['current_club']['name'] ?? 'your Club') : 'your Club';
            $context = [
                'event_key' => (string) $definition['id'],
                'priority' => $priority->value,
                'club_id' => $clubId,
                'club' => $club,
                'season_id' => $seasonId->value(),
                'newsworthy' => (bool) ($definition['newsworthy'] ?? false),
                'historyworthy' => (bool) ($definition['historyworthy'] ?? true),
                'repeatability' => (string) ($definition['repeatability'] ?? 'cooldown'),
                'chain_id' => $definition['chain_id'] ?? null,
                'chain_stage' => $definition['chain_stage'] ?? null,
                'career_context' => $signals['context_keys'],
            ];
            $event = CareerEvent::pending(
                hash('sha256', 'career-event|' . $sourceKey), $id, $seasonId, $date, $sourceKey,
                (string) $definition['category'], (string) $definition['id'], (string) $definition['title'],
                str_replace('{club}', $club, (string) $definition['description']), $definition['choices'], $context,
            );
            $repository->saveInTransaction($event);
            return $repository->bySourceKey($sourceKey);
        });
    }

    public function resolve(DatabaseInterface $database, string $eventId, int $choiceNumber, SimulationDate $date): CareerEvent
    {
        return $database->transaction(function () use ($database, $eventId, $choiceNumber, $date): CareerEvent {
            $repository = new CareerEventRepository($database);
            $event = $repository->get($eventId);
            if ($event === null) { throw new RuntimeException('That career event is no longer available.'); }
            if ($event->status() === CareerEventStatus::Resolved) { return $event; }
            $choice = $event->choices()[$choiceNumber - 1] ?? null;
            if (!is_array($choice) || !isset($choice['id'])) { throw new RuntimeException('That career event choice is unavailable.'); }
            $focus = isset($choice['focus']) ? TrainingFocus::fromInput((string) $choice['focus']) : null;
            $priority = isset($choice['priority']) ? CareerPriority::fromInput((string) $choice['priority']) : null;
            if ($focus !== null) { $this->development->setTrainingFocusInTransaction($database, $event->playerId(), $focus, $date); }
            if ($priority !== null) { (new PlayerPriorityRepository($database))->saveInTransaction($event->playerId(), $priority, $date); }
            $financeResult = null;
            if (is_array($choice['finance'] ?? null) && $this->finance !== null) {
                $effect = $choice['finance'];
                $financeResult = $this->finance->applyEventEffectInTransaction($database, $event->playerId(), $date, 'event:' . $event->id() . ':' . (string) $choice['id'], (int) ($effect['amount'] ?? 0), (string) ($effect['type'] ?? 'event_expense'), (string) ($effect['context'] ?? 'Career event'));
            }
            $memory = is_string($choice['memory'] ?? null) && trim((string) $choice['memory']) !== '' ? [(string) $choice['memory']] : [];
            $resolved = $event->resolved((string) $choice['id'], [
                'history' => (string) ($choice['history'] ?? 'Career event resolved'),
                'training_focus' => $focus?->value,
                'priority' => $priority?->value,
                'memory' => $memory,
                'newsworthy' => (bool) (($event->context()['newsworthy'] ?? false)),
                'finance' => $financeResult === null ? null : ['amount' => $financeResult['amount'], 'balance_after' => $financeResult['balance_after']],
            ]);
            $repository->resolveInTransaction($resolved);
            return $resolved;
        });
    }

    /** @return array<string, mixed>|null */
    public function prepareTraining(DatabaseInterface $database, PlayerId|string $playerId, string $matchId, SimulationDate $startDate, SimulationDate $endDate): ?array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $focus = $this->development->state($database, $id)->currentFocus() ?? TrainingFocus::Balanced;
        $result = $this->training->complete($database, new TrainingRequest($id, 'career-between-match:' . $matchId, $focus, $startDate, $endDate));
        return ['focus' => $focus->value, 'applied' => $result->applied(), 'ovr_before' => $result->beforeOverall(), 'ovr_after' => $result->afterOverall(), 'deltas' => $result->attributeDeltas()];
    }

    /** @param list<CareerEvent> $resolved @param array<string, mixed> $signals */
    private function selectDefinition(string $sourceKey, CareerPriority $priority, array $signals, array $resolved, SimulationDate $date, string $clubId): ?array
    {
        $candidates = [];
        foreach (CareerEventCatalog::all() as $definition) {
            if (!$this->eligible($definition, $signals, $resolved, $date, $clubId)) { continue; }
            $score = (int) ($definition['context_weight'] ?? 0);
            if (in_array($priority->value, $definition['priority_categories'] ?? [], true)) { $score += 5; }
            foreach (array_slice($resolved, 0, 3) as $previous) { if ($previous->category() === $definition['category']) { $score -= 3; } }
            $candidates[] = ['definition' => $definition, 'score' => $score, 'tie' => hash('sha256', $sourceKey . '|' . $definition['id'] . '|' . $priority->value)];
        }
        if ($candidates === []) { return null; }
        usort($candidates, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp((string) $a['tie'], (string) $b['tie']));
        $pool = array_slice($candidates, 0, min(6, count($candidates)));
        return $pool[hexdec(substr(hash('sha256', $sourceKey . '|event-selection'), 0, 8)) % count($pool)]['definition'];
    }

    /** @param array<string, mixed> $definition @param list<CareerEvent> $resolved @param array<string, mixed> $signals */
    private function eligible(array $definition, array $signals, array $resolved, SimulationDate $date, string $clubId): bool
    {
        $requirements = is_array($definition['requirements'] ?? null) ? $definition['requirements'] : [];
        if ($clubId === '' && !isset($requirements['free_agent'])) { return false; }
        if (($requirements['current_club'] ?? false) === true && $clubId === '') { return false; }
        if (($requirements['free_agent'] ?? false) === true && $clubId !== '') { return false; }
        if (isset($requirements['roles']) && !in_array($signals['role'], $requirements['roles'], true)) { return false; }
        if (isset($requirements['forms']) && !in_array($signals['form'], $requirements['forms'], true)) { return false; }
        if (isset($requirements['appearances_min']) && $signals['appearances'] < (int) $requirements['appearances_min']) { return false; }
        if (isset($requirements['starts_min']) && $signals['starts'] < (int) $requirements['starts_min']) { return false; }
        if (isset($requirements['goals_min']) && $signals['goals'] < (int) $requirements['goals_min']) { return false; }
        if (isset($requirements['recent_goals_min']) && $signals['recent_goals'] < (int) $requirements['recent_goals_min']) { return false; }
        if (($requirements['position_competition'] ?? false) === true && !$signals['position_competition']) { return false; }
        if (($requirements['transfer_request'] ?? null) !== null && $signals['transfer_request'] !== $requirements['transfer_request']) { return false; }
        if (($requirements['recent_transfer'] ?? false) === true && !$signals['recent_transfer']) { return false; }
        if (($requirements['contract_expiring'] ?? false) === true && !$signals['contract_expiring']) { return false; }
        if (($requirements['recent_team_result'] ?? null) !== null && $signals['recent_team_result'] !== $requirements['recent_team_result']) { return false; }
        if (($requirements['season_phase'] ?? null) !== null && $signals['season_phase'] !== $requirements['season_phase']) { return false; }
        if (($requirements['competition_pressure'] ?? null) !== null && $signals['competition_pressure'] !== $requirements['competition_pressure']) { return false; }
        if (isset($requirements['income_min']) && $signals['income'] < (int) $requirements['income_min']) { return false; }
        if (isset($requirements['wage_income_min']) && $signals['wage_income'] < (int) $requirements['wage_income_min']) { return false; }
        if (isset($requirements['balance_min']) && $signals['balance'] < (int) $requirements['balance_min']) { return false; }
        if (isset($requirements['owned_item']) && !isset($signals['owned_item'][(string) $requirements['owned_item']])) { return false; }
        if (isset($requirements['owned_effect']) && is_array($requirements['owned_effect'])) {
            $effect = (string) ($requirements['owned_effect']['effect'] ?? '');
            $minimum = (int) ($requirements['owned_effect']['min'] ?? 1);
            if ($effect === '' || (int) (($signals['owned_effects'] ?? [])[$effect] ?? 0) < $minimum) { return false; }
        }
        if (isset($requirements['history_absent']) && $this->hasMemory($resolved, (string) $requirements['history_absent'])) { return false; }
        foreach ((array) ($requirements['history_absent_any'] ?? []) as $memory) { if ($this->hasMemory($resolved, (string) $memory)) { return false; } }
        if (isset($requirements['history_present']) && !$this->hasMemory($resolved, (string) $requirements['history_present'])) { return false; }
        $repeatability = (string) ($definition['repeatability'] ?? 'cooldown');
        if ($repeatability === 'once_per_career' && $this->hasDefinition($resolved, (string) $definition['id'])) { return false; }
        if ($repeatability === 'once_per_season' && $this->hasDefinitionInSeason($resolved, (string) $definition['id'], (string) ($signals['season_id'] ?? ''))) { return false; }
        if ($repeatability === 'once_per_club' && $this->hasDefinitionForClub($resolved, (string) $definition['id'], $clubId)) { return false; }
        return !$this->withinCooldown($resolved, (string) $definition['id'], $date, (int) ($definition['cooldown_days'] ?? 90));
    }

    /** @param list<CareerEvent> $resolved */
    private function hasMemory(array $resolved, string $memory): bool
    {
        foreach ($resolved as $event) {
            $values = $event->consequence()['memory'] ?? [];
            if (is_string($values)) { $values = [$values]; }
            if (is_array($values) && in_array($memory, $values, true)) { return true; }
        }
        return false;
    }

    /** @param list<CareerEvent> $resolved */
    private function hasDefinition(array $resolved, string $definition): bool
    {
        foreach ($resolved as $event) { if ($event->definition() === $definition) { return true; } }
        return false;
    }

    /** @param list<CareerEvent> $resolved */
    private function hasDefinitionInSeason(array $resolved, string $definition, string $seasonId): bool
    {
        foreach ($resolved as $event) { if ($event->definition() === $definition && $event->seasonId()->value() === $seasonId) { return true; } }
        return false;
    }

    /** @param list<CareerEvent> $resolved */
    private function hasDefinitionForClub(array $resolved, string $definition, string $clubId): bool
    {
        foreach ($resolved as $event) { if ($event->definition() === $definition && (string) ($event->context()['club_id'] ?? '') === $clubId) { return true; } }
        return false;
    }

    /** @param list<CareerEvent> $resolved */
    private function withinCooldown(array $resolved, string $definition, SimulationDate $date, int $days): bool
    {
        foreach ($resolved as $event) {
            $distance = $event->date()->daysUntil($date);
            if ($event->definition() === $definition && $distance >= 0 && $distance < $days) { return true; }
        }
        return false;
    }

    /** @return array<string, mixed> */
    private function signals(DatabaseInterface $database, PlayerId $playerId, SeasonId $seasonId, array $summary, SimulationDate $date, mixed $recentMatch): array
    {
        $seasonStats = is_array($summary['season_stats'] ?? null) ? $summary['season_stats'] : [];
        $careerStats = is_array($summary['career_stats'] ?? null) ? $summary['career_stats'] : [];
        $recentForm = is_array($summary['recent_form'] ?? null) ? $summary['recent_form'] : [];
        $club = is_array($summary['current_club'] ?? null) ? $summary['current_club'] : null;
        $contract = is_array($summary['current_contract'] ?? null) ? $summary['current_contract'] : null;
        $recentEvidence = (new PlayerMatchStatRepository($database))->recentCompletedRatingEvidence($playerId, 1)[0]['stat'] ?? null;
        $recentGoals = $recentEvidence?->goals() ?? 0;
        $result = $recentMatch?->result();
        $clubId = is_array($club) ? (string) ($club['id'] ?? '') : '';
        $recentTeamResult = null;
        if ($result !== null && $clubId !== '') {
            $isHome = $recentMatch->homeClubId()->value() === $clubId;
            $for = $isHome ? $result->homeGoals() : $result->awayGoals();
            $against = $isHome ? $result->awayGoals() : $result->homeGoals();
            $recentTeamResult = $for === $against ? 'draw' : ($for > $against ? 'win' : 'loss');
        }
        $movement = is_array($summary['movement_history'] ?? null) ? $summary['movement_history'] : [];
        $recentTransfer = false;
        foreach ($movement as $entry) {
            if (!is_array($entry) || ($entry['type'] ?? null) !== 'transfer' || !is_string($entry['date'] ?? null)) { continue; }
            try {
                $distance = SimulationDate::fromIsoString($entry['date'])->daysUntil($date);
                $recentTransfer = $distance >= 0 && $distance <= 90;
            } catch (\Throwable) { $recentTransfer = false; }
            if ($recentTransfer) { break; }
        }
        $contractExpiring = false;
        if (is_string($contract['end_date'] ?? null)) {
            try { $days = $date->daysUntil(SimulationDate::fromIsoString($contract['end_date'])); $contractExpiring = $days >= 0 && $days <= 180; } catch (\Throwable) { $contractExpiring = false; }
        }
        $appearances = max((int) ($careerStats['appearances'] ?? 0), (int) ($seasonStats['appearances'] ?? 0));
        $starts = max((int) ($careerStats['starts'] ?? 0), (int) ($seasonStats['starts'] ?? 0));
        $goals = max((int) ($careerStats['goals'] ?? 0), (int) ($seasonStats['goals'] ?? 0));
        $role = (string) ($summary['current_role'] ?? $summary['squad_role'] ?? '');
        $form = (string) ($recentForm['classification'] ?? 'insufficient_evidence');
        $phase = $date->month() >= 2 ? 'run_in' : ($date->month() >= 10 ? 'midseason' : 'early_season');
        $competitionPressure = null;
        $competitionId = (string) (($summary['current_competition']['id'] ?? ''));
        if ($this->clubs !== null && $clubId !== '' && $competitionId !== '') {
            $table = (new StandingsService($this->clubs))->table($database, new CompetitionId($competitionId), $seasonId);
            foreach ($table as $index => $row) {
                if ((string) ($row['club_id'] ?? '') !== $clubId) { continue; }
                $rank = $index + 1;
                $size = count($table);
                $tier = (int) (($summary['current_competition']['tier'] ?? 1));
                if ($tier > 1 && $rank <= min(4, $size)) { $competitionPressure = 'promotion'; }
                if ($rank > max(0, $size - 3)) { $competitionPressure = 'relegation'; }
                break;
            }
        }
        $finance = $this->finance?->summary($database, $playerId, $date) ?? ['balance' => 0, 'income' => 0, 'wage_income' => 0, 'owned_ids' => [], 'owned' => []];
        $ownedIds = is_array($finance['owned_ids'] ?? null) ? $finance['owned_ids'] : [];
        $ownedEffects = [];
        foreach ((array) ($finance['owned'] ?? []) as $item) {
            foreach ((array) ($item['effects'] ?? []) as $effect => $value) {
                $ownedEffects[$effect] = max((int) ($ownedEffects[$effect] ?? 0), (int) $value);
            }
        }
        return [
            'season_id' => (string) ($summary['current_season_id'] ?? ''),
            'role' => $role, 'form' => $form, 'appearances' => $appearances, 'starts' => $starts,
            'goals' => $goals, 'recent_goals' => $recentGoals,
            'position_competition' => ((int) (($summary['position_competition']['higher_ovr_count'] ?? 0)) > 0),
            'transfer_request' => (string) (($summary['transfer_request']['status'] ?? 'none')),
            'recent_transfer' => $recentTransfer, 'contract_expiring' => $contractExpiring,
            'recent_team_result' => $recentTeamResult, 'season_phase' => $phase, 'competition_pressure' => $competitionPressure,
            'income' => (int) ($finance['income'] ?? 0), 'wage_income' => (int) ($finance['wage_income'] ?? 0), 'balance' => (int) ($finance['balance'] ?? 0), 'owned_item' => $ownedIds, 'owned_effects' => $ownedEffects,
            'context_keys' => array_values(array_filter([$role, $form, $recentTeamResult, $recentTransfer ? 'recent_transfer' : null, $contractExpiring ? 'contract_expiring' : null, ((int) ($finance['wage_income'] ?? 0)) > 0 ? 'wage_received' : null, $ownedEffects === [] ? null : 'lifestyle_owned'])),
        ];
    }
}
