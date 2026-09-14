<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Persistence;

use PDO;
use PDOStatement;
use Throwable;

final class ProfilingPdo extends PDO
{
    private readonly SqlProfiler $sqlProfiler;

    public function __construct(string $dsn, ?string $username, ?string $password, array $options, SqlProfiler $profiler)
    {
        $this->sqlProfiler = $profiler;
        parent::__construct($dsn, $username, $password, $options);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ProfilingPdoStatement::class, [$profiler]]);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $started = hrtime(true);
        try {
            $statement = $fetchMode === null
                ? parent::query($query)
                : parent::query($query, $fetchMode, ...$fetchModeArgs);
            $this->profiler()->recordQuery($query, [], hrtime(true) - $started, $statement !== false);
            return $statement;
        } catch (Throwable $exception) {
            $this->profiler()->recordQuery($query, [], hrtime(true) - $started, false);
            throw $exception;
        }
    }

    public function exec(string $statement): int|false
    {
        $started = hrtime(true);
        try {
            $result = parent::exec($statement);
            $this->profiler()->recordQuery($statement, [], hrtime(true) - $started, $result !== false);
            return $result;
        } catch (Throwable $exception) {
            $this->profiler()->recordQuery($statement, [], hrtime(true) - $started, false);
            throw $exception;
        }
    }

    private function profiler(): SqlProfiler
    {
        return $this->sqlProfiler;
    }
}
