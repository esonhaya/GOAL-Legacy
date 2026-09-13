<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Domain;

final class MatchEventNames
{
    public const FIXTURES_GENERATED = 'match.fixtures_generated';
    public const COMPLETED = 'match.completed';
    public const STANDINGS_UPDATED = 'competition.standings_updated';
}
