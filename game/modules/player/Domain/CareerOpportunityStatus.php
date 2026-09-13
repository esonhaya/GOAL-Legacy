<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

enum CareerOpportunityStatus: string
{
    case Open = 'open';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
    case Resolved = 'resolved';
}
