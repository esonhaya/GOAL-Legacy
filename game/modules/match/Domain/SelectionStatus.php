<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Domain;

enum SelectionStatus: string
{
    case Starter = 'starter';
    case Bench = 'bench';
    case NotSelected = 'not_selected';
    case Unavailable = 'unavailable';
}
