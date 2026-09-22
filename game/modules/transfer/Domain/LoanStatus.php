<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Transfer\Domain;

enum LoanStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
}
