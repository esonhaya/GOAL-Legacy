<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Modules;

use RuntimeException;
use Throwable;

final class ModuleLifecycleException extends RuntimeException
{
    public function __construct(string $moduleId, string $phase, Throwable $previous)
    {
        parent::__construct(sprintf('Critical module "%s" failed during %s: %s', $moduleId, $phase, $previous->getMessage()), 0, $previous);
    }
}
