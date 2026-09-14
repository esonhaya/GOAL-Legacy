<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Persistence;

use PDOStatement;
use Throwable;

final class ProfilingPdoStatement extends PDOStatement
{
    private SqlProfiler $sqlProfiler;

    protected function __construct(SqlProfiler $profiler)
    {
        $this->sqlProfiler = $profiler;
    }

    public function execute(?array $params = null): bool
    {
        $started = hrtime(true);
        try {
            $result = parent::execute($params);
            $this->sqlProfiler->recordQuery($this->queryString, $params ?? [], hrtime(true) - $started, $result);
            return $result;
        } catch (Throwable $exception) {
            $this->sqlProfiler->recordQuery($this->queryString, $params ?? [], hrtime(true) - $started, false);
            throw $exception;
        }
    }
}
