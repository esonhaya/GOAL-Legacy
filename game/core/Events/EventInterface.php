<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Events;

interface EventInterface
{
    public function name(): string;

    public function priority(): int;

    public function occurredAt(): \DateTimeImmutable;

    /** @return array<string, mixed> */
    public function payload(): array;
}
