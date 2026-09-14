<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

enum CareerTransferRequestStatus: string
{
    case None = 'none';
    case Requested = 'requested';
}
