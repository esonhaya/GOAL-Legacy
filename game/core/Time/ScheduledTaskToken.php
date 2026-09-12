<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Time;

final readonly class ScheduledTaskToken
{
    public function __construct(private string $id)
    {
    }

    public function id(): string
    {
        return $this->id;
    }
}
