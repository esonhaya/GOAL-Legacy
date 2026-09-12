<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Competition\Domain;

final class CompetitionNotFoundException extends CompetitionException
{
    public function __construct(string $id)
    {
        parent::__construct(sprintf('Competition "%s" does not exist.', $id));
    }
}
