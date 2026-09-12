<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Competition\Domain;

enum CompetitionStatus: string
{
    case Upcoming = 'upcoming';
    case Active = 'active';
    case Completed = 'completed';
}
