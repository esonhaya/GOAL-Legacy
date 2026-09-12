<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Modules;

use LogicException;

final class DuplicateModuleException extends LogicException
{
    public function __construct(string $moduleId)
    {
        parent::__construct(sprintf('Module ID "%s" is already registered.', $moduleId));
    }
}
