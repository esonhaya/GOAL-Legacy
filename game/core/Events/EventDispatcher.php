<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Events;

use Goal\Legacy\Core\Logging\LoggerInterface;
use InvalidArgumentException;
use Throwable;

final class EventDispatcher implements EventDispatcherInterface
{
    /** @var array<string, array<string, array{listener: callable, priority: int, sequence: int}>> */
    private array $listeners = [];

    /** @var list<array{event: EventInterface, sequence: int}> */
    private array $queue = [];

    private int $sequence = 0;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function subscribe(string $eventName, callable $listener, int $priority = 0, ?string $listenerId = null): ListenerSubscription
    {
        self::validateEventName($eventName);
        $listenerId = trim((string) $listenerId);
        if ($listenerId === '') {
            throw new InvalidArgumentException('Listener IDs are required to prevent duplicate accidental subscriptions.');
        }
        if (isset($this->listeners[$eventName][$listenerId])) {
            throw new DuplicateSubscriptionException($eventName, $listenerId);
        }

        $this->listeners[$eventName][$listenerId] = [
            'listener' => $listener,
            'priority' => $priority,
            'sequence' => $this->sequence++,
        ];

        return new ListenerSubscription($eventName, $listenerId);
    }

    public function unsubscribe(ListenerSubscription|string $subscription, ?string $listenerId = null): bool
    {
        if ($subscription instanceof ListenerSubscription) {
            $eventName = $subscription->eventName();
            $listenerId = $subscription->listenerId();
        } else {
            $eventName = $subscription;
            $listenerId = trim((string) $listenerId);
        }

        if ($listenerId === '' || !isset($this->listeners[$eventName][$listenerId])) {
            return false;
        }

        unset($this->listeners[$eventName][$listenerId]);
        if ($this->listeners[$eventName] === []) {
            unset($this->listeners[$eventName]);
        }

        return true;
    }

    public function dispatch(EventInterface $event): DispatchResult
    {
        self::validateEventName($event->name());
        $listeners = $this->listeners[$event->name()] ?? [];
        uasort($listeners, static function (array $left, array $right): int {
            return $right['priority'] <=> $left['priority'] ?: $left['sequence'] <=> $right['sequence'];
        });

        $delivered = 0;
        $failures = [];
        foreach ($listeners as $listenerId => $registration) {
            try {
                ($registration['listener'])($event);
                $delivered++;
            } catch (Throwable $exception) {
                $failure = new DispatchFailure((string) $listenerId, $exception);
                $failures[] = $failure;
                $this->logger->error('core.event', 'Event listener failed; dispatch continued.', [
                    'event' => $event->name(),
                    'listener' => $listenerId,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return new DispatchResult($event, $delivered, $failures);
    }

    public function queue(EventInterface $event): void
    {
        $this->queue[] = ['event' => $event, 'sequence' => $this->sequence++];
    }

    public function dispatchQueued(): array
    {
        $queued = $this->queue;
        $this->queue = [];
        usort($queued, static function (array $left, array $right): int {
            return $right['event']->priority() <=> $left['event']->priority()
                ?: $left['sequence'] <=> $right['sequence'];
        });

        return array_map(fn (array $item): DispatchResult => $this->dispatch($item['event']), $queued);
    }

    public function clearQueue(): void
    {
        $this->queue = [];
    }

    public function listenerCount(?string $eventName = null): int
    {
        if ($eventName !== null) {
            return count($this->listeners[$eventName] ?? []);
        }

        return array_sum(array_map('count', $this->listeners));
    }

    private static function validateEventName(string $eventName): void
    {
        if (trim($eventName) === '') {
            throw new InvalidArgumentException('Event names cannot be empty.');
        }
    }
}
