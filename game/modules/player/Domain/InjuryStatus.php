<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

enum InjuryStatus: string
{
    case Active = 'active';
    case Recovered = 'recovered';
}
