<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\World\Domain;

final class WorldEventNames
{
    public const TIME_ADVANCED = 'world.time_advanced';
    public const SEASON_STARTED = 'season.started';
    public const SEASON_COMPLETED = 'season.completed';
    public const COMPETITION_ACTIVATED = 'competition.activated';
    public const COMPETITION_COMPLETED = 'competition.completed';
}
