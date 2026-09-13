<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Contract\Domain;

enum ContractStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Expired = 'expired';
    case Terminated = 'terminated';
}
