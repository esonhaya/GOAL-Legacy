<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Club\Domain;

final class ClubNotFoundException extends ClubException
{
    public function __construct(string $id)
    {
        parent::__construct(sprintf('Club "%s" does not exist.', $id));
    }
}
