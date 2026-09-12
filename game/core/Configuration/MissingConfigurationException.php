<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Configuration;

use RuntimeException;

final class MissingConfigurationException extends RuntimeException
{
    public function __construct(string $key)
    {
        parent::__construct(sprintf('Required configuration value "%s" is missing.', $key));
    }
}
