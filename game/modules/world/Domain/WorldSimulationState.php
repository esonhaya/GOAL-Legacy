<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\World\Domain;

enum WorldSimulationState: string
{
    case Running = 'running';
    case Paused = 'paused';
}
