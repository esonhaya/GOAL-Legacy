<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Persistence;

use PDO;
use PDOException;
use Throwable;

final class SqliteDatabase implements DatabaseInterface
{
    private PDO $connection;

    public function __construct(string $path)
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new PersistenceException('PDO SQLite extension is required for save persistence.');
        }
        if ($path === '' || str_contains($path, "\0")) {
            throw new PersistenceException('SQLite database path must be non-empty and free of null bytes.');
        }

        if ($path !== ':memory:') {
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new PersistenceException(sprintf('Unable to create SQLite directory "%s".', $directory));
            }
        }

        try {
            $this->connection = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->connection->exec('PRAGMA foreign_keys = ON');
            $this->connection->exec('PRAGMA busy_timeout = 5000');
        } catch (PDOException $exception) {
            throw new PersistenceException(sprintf('Unable to open SQLite database "%s".', $path), 0, $exception);
        }
    }

    public function connection(): PDO
    {
        return $this->connection;
    }

    public function transaction(callable $operation): mixed
    {
        if ($this->connection->inTransaction()) {
            throw new PersistenceException('Nested database transactions are not supported.');
        }

        $this->connection->beginTransaction();
        try {
            $result = $operation($this->connection);
            $this->connection->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }
}
