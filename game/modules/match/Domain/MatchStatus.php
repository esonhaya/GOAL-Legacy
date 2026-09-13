<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Domain;

enum MatchStatus: string
{
    case Scheduled = 'scheduled';
    case Completed = 'completed';
}
