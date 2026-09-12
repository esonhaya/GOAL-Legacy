<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Events;

interface EventDispatcherInterface
{
    /** @param callable(EventInterface): void $listener */
    public function subscribe(string $eventName, callable $listener, int $priority = 0, ?string $listenerId = null): ListenerSubscription;

    public function unsubscribe(ListenerSubscription|string $subscription, ?string $listenerId = null): bool;

    public function dispatch(EventInterface $event): DispatchResult;

    public function queue(EventInterface $event): void;

    /** @return list<DispatchResult> */
    public function dispatchQueued(): array;

    public function clearQueue(): void;

    public function listenerCount(?string $eventName = null): int;
}
