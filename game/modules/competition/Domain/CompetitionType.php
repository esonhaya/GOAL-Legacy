<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Competition\Domain;

enum CompetitionType: string
{
    case DomesticLeague = 'domestic_league';
    case DomesticCup = 'domestic_cup';
    case Continental = 'continental';
    case International = 'international';
    case FriendlyTournament = 'friendly_tournament';
}
