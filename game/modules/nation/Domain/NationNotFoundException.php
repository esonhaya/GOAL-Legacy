<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Nation\Domain;

final class NationNotFoundException extends NationException
{
    public function __construct(string $id)
    {
        parent::__construct(sprintf('Nation "%s" does not exist.', $id));
    }
}
