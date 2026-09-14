<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Persistence;

use PDO;
use PDOException;
use Throwable;

final class SqliteDatabase implements DatabaseInterface
{
    private PDO $connection;
    private readonly ?SqlProfiler $profiler;

    public function __construct(string $path, ?SqlProfiler $profiler = null)
    {
        $this->profiler = $profiler;
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
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->connection = $profiler === null
                ? new PDO('sqlite:' . $path, null, null, $options)
                : new ProfilingPdo('sqlite:' . $path, null, null, $options, $profiler);
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

        $started = hrtime(true);
        $this->connection->beginTransaction();
        try {
            $result = $operation($this->connection);
            $commitStarted = hrtime(true);
            $this->connection->commit();
            $this->profiler?->recordCommit(hrtime(true) - $commitStarted);

            return $result;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        } finally {
            $this->profiler?->recordTransaction(hrtime(true) - $started);
        }
    }
}
