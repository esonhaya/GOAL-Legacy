<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

final class PlayerNotFoundException extends PlayerException
{
    public function __construct(string $id)
    {
        parent::__construct(sprintf('Player "%s" was not found.', $id));
    }
}
