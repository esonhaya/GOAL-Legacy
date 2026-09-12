<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Events;

final class ListenerSubscription
{
    public function __construct(
        private readonly string $eventName,
        private readonly string $listenerId,
    ) {
    }

    public function eventName(): string
    {
        return $this->eventName;
    }

    public function listenerId(): string
    {
        return $this->listenerId;
    }
}
