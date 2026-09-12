<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Time;

/** Runtime convenience only; callbacks are not a persistence format. */
final class CallbackScheduledWork implements ScheduledWork
{
    public function __construct(private readonly \Closure $callback)
    {
    }

    public function execute(): void
    {
        ($this->callback)();
    }
}
