<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Persistence;

use PDO;

interface DatabaseInterface
{
    public function connection(): PDO;

    /** @param callable(PDO): mixed $operation */
    public function transaction(callable $operation): mixed;
}
