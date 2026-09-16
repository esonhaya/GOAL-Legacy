<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

enum CareerEventStatus: string
{
    case Pending = 'pending';
    case Resolved = 'resolved';
}
