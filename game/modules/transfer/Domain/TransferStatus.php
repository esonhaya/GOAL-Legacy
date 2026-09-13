<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Transfer\Domain;

enum TransferStatus: string
{
    case Proposed = 'proposed';
    case Agreed = 'agreed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
