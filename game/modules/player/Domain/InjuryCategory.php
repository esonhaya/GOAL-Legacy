<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

enum InjuryCategory: string
{
    case Muscular = 'muscular';
    case Impact = 'impact';
    case Joint = 'joint';
    case Other = 'other';
}
