<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Persistence;

use PDO;
use WeakMap;

/** Runs repository schema setup once per live database connection. */
final class SchemaInitializationGuard
{
    /** @var WeakMap<object, array<string, true>>|null */
    private static ?WeakMap $initialized = null;

    /** @param callable(): void $initialize */
    public static function run(PDO $connection, string $key, callable $initialize): void
    {
        self::$initialized ??= new WeakMap();
        $connectionState = self::$initialized[$connection] ?? [];
        if (isset($connectionState[$key])) {
            return;
        }

        $initialize();
        // SQLite DDL participates in the surrounding transaction. Do not
        // remember setup performed inside a transaction: a later rollback
        // may have removed the table just created.
        if (!$connection->inTransaction()) {
            $connectionState[$key] = true;
            self::$initialized[$connection] = $connectionState;
        }
    }
}
