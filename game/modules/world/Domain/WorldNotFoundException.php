<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\World\Domain;

final class WorldNotFoundException extends WorldException
{
    public function __construct(string $id)
    {
        parent::__construct(sprintf('World "%s" does not exist.', $id));
    }
}
