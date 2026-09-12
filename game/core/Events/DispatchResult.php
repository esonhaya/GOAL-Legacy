<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Events;

final class DispatchResult
{
    /** @param list<DispatchFailure> $failures */
    public function __construct(
        private readonly EventInterface $event,
        private readonly int $deliveredCount,
        private readonly array $failures,
    ) {
    }

    public function event(): EventInterface
    {
        return $this->event;
    }

    public function deliveredCount(): int
    {
        return $this->deliveredCount;
    }

    /** @return list<DispatchFailure> */
    public function failures(): array
    {
        return $this->failures;
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }
}
