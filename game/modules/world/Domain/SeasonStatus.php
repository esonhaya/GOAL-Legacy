<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\World\Domain;

enum SeasonStatus: string
{
    case Upcoming = 'upcoming';
    case Active = 'active';
    case Completed = 'completed';
}
