<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

enum PlayerCareerState: string
{
    case Active = 'active';
    case Retired = 'retired';
}
