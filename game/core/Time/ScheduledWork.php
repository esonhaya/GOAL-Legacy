<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Time;

interface ScheduledWork
{
    public function execute(): void;
}
