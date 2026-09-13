<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

enum AvailabilityStatus: string
{
    case Available = 'available';
    case Limited = 'limited';
    case Unavailable = 'unavailable';
}
