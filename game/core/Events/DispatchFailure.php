<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Events;

use Throwable;

final class DispatchFailure
{
    public function __construct(
        private readonly string $listenerId,
        private readonly Throwable $exception,
    ) {
    }

    public function listenerId(): string
    {
        return $this->listenerId;
    }

    public function exception(): Throwable
    {
        return $this->exception;
    }
}
