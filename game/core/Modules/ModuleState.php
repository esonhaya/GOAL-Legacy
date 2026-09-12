<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Modules;

enum ModuleState: string
{
    case Registered = 'registered';
    case Booted = 'booted';
    case Started = 'started';
    case Stopped = 'stopped';
    case Failed = 'failed';
}
