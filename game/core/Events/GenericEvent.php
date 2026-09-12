<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Events;

use InvalidArgumentException;

final class GenericEvent implements EventInterface
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly string $eventName,
        private readonly array $eventPayload = [],
        private readonly int $eventPriority = EventPriority::Normal->value,
        private readonly \DateTimeImmutable $timestamp = new \DateTimeImmutable(),
    ) {
        if (trim($eventName) === '') {
            throw new InvalidArgumentException('Event names cannot be empty.');
        }
    }

    public function name(): string
    {
        return $this->eventName;
    }

    public function priority(): int
    {
        return $this->eventPriority;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function payload(): array
    {
        return $this->eventPayload;
    }
}
