<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Player\Domain\CareerEvent;
use Goal\Legacy\Modules\Player\Domain\CareerEventStatus;
use Goal\Legacy\Modules\Player\Domain\CareerPriority;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\TrainingFocus;
use Goal\Legacy\Modules\Player\Domain\TrainingRequest;
use Goal\Legacy\Modules\Player\Persistence\CareerEventRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerPriorityRepository;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use RuntimeException;

/** Owns the small, persisted layer between controlled Matches. */
final class CareerExperienceService
{
    public function __construct(private readonly PlayerDevelopmentService $development, private readonly TrainingService $training)
    {
    }

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

    /**
     * Create at most one event for a player in a calendar month. The source
     * key is independent of the current priority, so changing priority after
     * creation cannot reroll or multiply the event.
     *
     * @param array<string, mixed> $summary
     */
    public function ensureEvent(DatabaseInterface $database, PlayerId|string $playerId, SeasonId $seasonId, SimulationDate $date, array $summary): ?CareerEvent
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        if (!is_array($summary['current_club'] ?? null)) {
            return null;
        }
        $clubId = (string) (($summary['current_club']['id'] ?? ''));
        if ($clubId === '') {
            return null;
        }
        $completedClubMatches = array_filter(
            (new MatchRepository($database))->byClub(new ClubId($clubId), $seasonId),
            static fn ($match): bool => $match->status() === MatchStatus::Completed && !$match->scheduledDate()->isAfter($date),
        );
        if ($completedClubMatches === []) { return null; }
        $sourceKey = $id->value() . '|' . $seasonId->value() . '|' . sprintf('%04d-%02d', $date->year(), $date->month());
        $repository = new CareerEventRepository($database);
        $existing = $repository->bySourceKey($sourceKey);
        if ($existing !== null) {
            return $existing->status() === CareerEventStatus::Pending ? $existing : null;
        }

        return $database->transaction(function () use ($database, $id, $seasonId, $date, $summary, $sourceKey): ?CareerEvent {
            $repository = new CareerEventRepository($database);
            $existing = $repository->bySourceKey($sourceKey);
            if ($existing !== null) {
                return $existing->status() === CareerEventStatus::Pending ? $existing : null;
            }
            $priority = $this->priority($database, $id);
            $definitions = $this->definitionsFor($priority);
            $seed = hash('sha256', $sourceKey . '|' . $priority->value);
            $definition = $definitions[hexdec(substr($seed, 0, 8)) % count($definitions)];
            $club = is_array($summary['current_club'] ?? null) ? (string) ($summary['current_club']['name'] ?? 'your Club') : 'your Club';
            $event = CareerEvent::pending(
                hash('sha256', 'career-event|' . $sourceKey),
                $id,
                $seasonId,
                $date,
                $sourceKey,
                (string) $definition['category'],
                (string) $definition['id'],
                (string) $definition['title'],
                str_replace('{club}', $club, (string) $definition['description']),
                $definition['choices'],
                [
                    'priority' => $priority->value,
                    'club' => $club,
                    'season_id' => $seasonId->value(),
                    'newsworthy' => (bool) ($definition['newsworthy'] ?? false),
                ],
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
            if ($event === null) {
                throw new RuntimeException('That career event is no longer available.');
            }
            if ($event->status() === CareerEventStatus::Resolved) {
                return $event;
            }
            $choice = $event->choices()[$choiceNumber - 1] ?? null;
            if (!is_array($choice) || !isset($choice['id'])) {
                throw new RuntimeException('That career event choice is unavailable.');
            }
            $focus = isset($choice['focus']) ? TrainingFocus::fromInput((string) $choice['focus']) : null;
            $priority = isset($choice['priority']) ? CareerPriority::fromInput((string) $choice['priority']) : null;
            if ($focus !== null) {
                $this->development->setTrainingFocusInTransaction($database, $event->playerId(), $focus, $date);
            }
            if ($priority !== null) {
                (new PlayerPriorityRepository($database))->saveInTransaction($event->playerId(), $priority, $date);
            }
            $consequence = [
                'history' => (string) ($choice['history'] ?? 'Career event resolved'),
                'training_focus' => $focus?->value,
                'priority' => $priority?->value,
                'newsworthy' => (bool) (($event->context()['newsworthy'] ?? false)),
            ];
            $resolved = $event->resolved((string) $choice['id'], $consequence);
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

        return [
            'focus' => $focus->value,
            'applied' => $result->applied(),
            'ovr_before' => $result->beforeOverall(),
            'ovr_after' => $result->afterOverall(),
            'deltas' => $result->attributeDeltas(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function definitionsFor(CareerPriority $priority): array
    {
        $definitions = $this->definitions();
        $preferred = match ($priority) {
            CareerPriority::Development => ['training', 'career', 'team', 'media'],
            CareerPriority::Recovery => ['recovery', 'adaptation', 'family', 'team'],
            CareerPriority::Professional => ['team', 'career', 'media', 'training'],
            CareerPriority::Lifestyle => ['social', 'family', 'community', 'lifestyle'],
            CareerPriority::Balanced => array_values(array_unique(array_map(static fn (array $definition): string => (string) $definition['category'], $definitions))),
        };
        $filtered = array_values(array_filter($definitions, static fn (array $definition): bool => in_array($definition['category'], $preferred, true)));

        return $filtered === [] ? $definitions : $filtered;
    }

    /** @return list<array<string, mixed>> */
    private function definitions(): array
    {
        return [
            ['id' => 'extra-technical-work', 'category' => 'training', 'title' => 'A focused training opportunity', 'description' => 'The staff at {club} offer extra individual work before the next fixture.', 'newsworthy' => false, 'choices' => [
                ['id' => 'focus-passing', 'label' => 'Use the time to sharpen passing', 'focus' => 'passing', 'priority' => 'development', 'history' => 'Training focus changed to Passing'],
                ['id' => 'focus-shooting', 'label' => 'Use the time to sharpen finishing', 'focus' => 'shooting', 'priority' => 'development', 'history' => 'Training focus changed to Shooting'],
                ['id' => 'keep-balance', 'label' => 'Keep a balanced programme', 'focus' => 'balanced', 'priority' => 'balanced', 'history' => 'Kept a balanced training programme'],
            ]],
            ['id' => 'recovery-window', 'category' => 'recovery', 'title' => 'A chance to reset', 'description' => "A quieter week opens up around {club}'s schedule.", 'newsworthy' => false, 'choices' => [
                ['id' => 'protect-recovery', 'label' => 'Put recovery first', 'priority' => 'recovery', 'history' => 'Prioritised recovery during a quiet week'],
                ['id' => 'keep-working', 'label' => 'Keep the normal professional routine', 'priority' => 'professional', 'history' => 'Kept the normal professional routine'],
                ['id' => 'balanced-week', 'label' => 'Keep the week balanced', 'priority' => 'balanced', 'history' => 'Kept the week balanced'],
            ]],
            ['id' => 'coach-review', 'category' => 'team', 'title' => 'A word with the coaching staff', 'description' => 'The staff at {club} want to discuss how you can help the team.', 'newsworthy' => true, 'choices' => [
                ['id' => 'team-first', 'label' => 'Focus on your role in the team', 'priority' => 'professional', 'history' => 'Focused on the team role after a staff review'],
                ['id' => 'development-plan', 'label' => 'Ask for a development plan', 'priority' => 'development', 'history' => 'Asked the staff for a development plan'],
                ['id' => 'balanced-review', 'label' => 'Keep the current balance', 'priority' => 'balanced', 'history' => 'Kept the current balance after a staff review'],
            ]],
            ['id' => 'family-weekend', 'category' => 'family', 'title' => 'A family request', 'description' => 'Your family would value time together during the next break.', 'newsworthy' => false, 'choices' => [
                ['id' => 'make-time', 'label' => 'Make time for family', 'priority' => 'lifestyle', 'history' => 'Made time for family during a break'],
                ['id' => 'short-visit', 'label' => 'Arrange a short visit and keep the routine', 'priority' => 'balanced', 'history' => 'Balanced family time with the football routine'],
                ['id' => 'stay-focused', 'label' => 'Stay with the football routine', 'priority' => 'professional', 'history' => 'Kept the football routine during a family break'],
            ]],
            ['id' => 'teammates-invite', 'category' => 'social', 'title' => 'Teammates invite you out', 'description' => 'A few teammates from {club} invite you to spend an evening together.', 'newsworthy' => false, 'choices' => [
                ['id' => 'join-team', 'label' => 'Join them for the evening', 'priority' => 'lifestyle', 'history' => 'Spent time with teammates away from football'],
                ['id' => 'leave-early', 'label' => 'Join them, then leave early', 'priority' => 'balanced', 'history' => 'Joined teammates briefly before returning to routine'],
                ['id' => 'recover-instead', 'label' => 'Skip it and recover', 'priority' => 'recovery', 'history' => 'Skipped a social evening to recover'],
            ]],
            ['id' => 'personal-routine', 'category' => 'lifestyle', 'title' => 'A little time for yourself', 'description' => 'The next few days offer a rare chance to choose how you want to spend your time away from {club}.', 'newsworthy' => false, 'choices' => [
                ['id' => 'enjoy-the-break', 'label' => 'Enjoy the break', 'priority' => 'lifestyle', 'history' => 'Made room for life away from football'],
                ['id' => 'keep-structure', 'label' => 'Keep a structured routine', 'priority' => 'professional', 'history' => 'Kept a structured routine away from the Club'],
                ['id' => 'rest-quietly', 'label' => 'Use the time to rest quietly', 'priority' => 'recovery', 'history' => 'Used a quiet break to recover'],
            ]],
            ['id' => 'community-visit', 'category' => 'community', 'title' => 'A community invitation', 'description' => 'A local community group asks for a short appearance from a {club} player.', 'newsworthy' => true, 'choices' => [
                ['id' => 'represent-club', 'label' => 'Represent the Club', 'priority' => 'professional', 'history' => 'Represented the Club at a community event'],
                ['id' => 'short-appearance', 'label' => 'Make a short appearance', 'priority' => 'balanced', 'history' => 'Made a short community appearance'],
                ['id' => 'protect-time', 'label' => 'Protect the training schedule', 'priority' => 'development', 'history' => 'Protected the training schedule instead of attending'],
            ]],
            ['id' => 'media-request', 'category' => 'media', 'title' => 'A media request', 'description' => 'A local outlet wants to hear about your progress at {club}.', 'newsworthy' => true, 'choices' => [
                ['id' => 'speak-proudly', 'label' => 'Speak about the Club with pride', 'priority' => 'professional', 'history' => 'Spoke publicly about the Club'],
                ['id' => 'brief-answer', 'label' => 'Give a brief answer and move on', 'priority' => 'balanced', 'history' => 'Gave a brief media answer'],
                ['id' => 'decline-media', 'label' => 'Decline and keep working', 'priority' => 'development', 'history' => 'Declined a media request to keep working'],
            ]],
            ['id' => 'new-city-adjustment', 'category' => 'adaptation', 'title' => 'Settling into the new routine', 'description' => 'The demands of a new football environment are beginning to feel real.', 'newsworthy' => false, 'choices' => [
                ['id' => 'ask-for-help', 'label' => 'Ask teammates for help settling in', 'priority' => 'lifestyle', 'history' => 'Asked teammates for help settling into the Club'],
                ['id' => 'stay-professional', 'label' => 'Keep the professional routine', 'priority' => 'professional', 'history' => 'Kept a professional routine while settling in'],
                ['id' => 'take-it-slow', 'label' => 'Take the adjustment slowly', 'priority' => 'recovery', 'history' => 'Took time to adjust to a new routine'],
            ]],
            ['id' => 'form-conversation', 'category' => 'career', 'title' => 'A conversation about your form', 'description' => 'The people around {club} want to talk through your next step.', 'newsworthy' => true, 'choices' => [
                ['id' => 'push-forward', 'label' => 'Push for a bigger football role', 'priority' => 'professional', 'history' => 'Asked to push forward in the football role'],
                ['id' => 'focus-development', 'label' => 'Focus on steady development', 'priority' => 'development', 'history' => 'Chose steady development as the next step'],
                ['id' => 'protect-balance', 'label' => 'Protect a balanced routine', 'priority' => 'balanced', 'history' => 'Protected a balanced routine while considering the next step'],
            ]],
        ];
    }
}
