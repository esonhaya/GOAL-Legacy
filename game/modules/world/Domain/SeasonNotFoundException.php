<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\World\Domain;

final class SeasonNotFoundException extends WorldException
{
    public function __construct(string $id)
    {
        parent::__construct(sprintf('Season "%s" does not exist.', $id));
    }
}
