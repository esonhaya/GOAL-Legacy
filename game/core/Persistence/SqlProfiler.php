<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Persistence;

use Throwable;

/** Bounded, opt-in aggregate measurements for one SQLite workload. */
final class SqlProfiler
{
    /** @var array<string, array<string, mixed>> */
    private array $profiles = [];
    private int $totalCalls = 0;
    private float $totalQueryMs = 0.0;
    private int $transactionCount = 0;
    private float $transactionTotalMs = 0.0;
    private float $transactionMaxMs = 0.0;
    private int $commitCount = 0;
    private float $commitTotalMs = 0.0;
    private float $commitMaxMs = 0.0;
    private int $suspendDepth = 0;
    /** @var array<string, string> */
    private array $normalizationCache = [];

    public function reset(): void
    {
        $this->profiles = [];
        $this->totalCalls = 0;
        $this->totalQueryMs = 0.0;
        $this->transactionCount = 0;
        $this->transactionTotalMs = 0.0;
        $this->transactionMaxMs = 0.0;
        $this->commitCount = 0;
        $this->commitTotalMs = 0.0;
        $this->commitMaxMs = 0.0;
        $this->normalizationCache = [];
    }

    /** @param array<int|string, mixed> $parameters */
    public function recordQuery(string $sql, array $parameters, int $elapsedNs, bool $successful = true): void
    {
        if ($this->suspendDepth > 0) {
            return;
        }

        $fingerprint = $this->fingerprint($sql);
        if ($fingerprint === '') {
            return;
        }

        $milliseconds = $elapsedNs / 1_000_000;
        $this->totalCalls++;
        $this->totalQueryMs += $milliseconds;
        if (!isset($this->profiles[$fingerprint])) {
            $this->profiles[$fingerprint] = [
                'fingerprint' => $fingerprint,
                'example_sql' => $fingerprint,
                'example_params' => self::safeParameters($parameters),
                'calls' => 0,
                'total_ms' => 0.0,
                'average_ms' => 0.0,
                'max_ms' => 0.0,
                'failed_calls' => 0,
                'plan' => [],
                'full_scan' => false,
                'temp_btree' => false,
                'index_used' => false,
            ];
        }

        $profile =& $this->profiles[$fingerprint];
        $profile['calls']++;
        $profile['total_ms'] += $milliseconds;
        $profile['average_ms'] = $profile['total_ms'] / $profile['calls'];
        $profile['max_ms'] = max((float) $profile['max_ms'], $milliseconds);
        if (!$successful) {
            $profile['failed_calls']++;
        }
        unset($profile);
    }

    public function recordTransaction(int $elapsedNs): void
    {
        if ($this->suspendDepth > 0) {
            return;
        }
        $milliseconds = $elapsedNs / 1_000_000;
        $this->transactionCount++;
        $this->transactionTotalMs += $milliseconds;
        $this->transactionMaxMs = max($this->transactionMaxMs, $milliseconds);
    }

    public function recordCommit(int $elapsedNs): void
    {
        if ($this->suspendDepth > 0) {
            return;
        }
        $milliseconds = $elapsedNs / 1_000_000;
        $this->commitCount++;
        $this->commitTotalMs += $milliseconds;
        $this->commitMaxMs = max($this->commitMaxMs, $milliseconds);
    }

    /** @template T */
    /** @param callable(): T $operation @return T */
    public function withoutProfiling(callable $operation): mixed
    {
        ++$this->suspendDepth;
        try {
            return $operation();
        } finally {
            --$this->suspendDepth;
        }
    }

    /** @param list<string> $planLines */
    public function recordPlan(string $fingerprint, array $planLines): void
    {
        if (!isset($this->profiles[$fingerprint])) {
            return;
        }

        $joined = strtoupper(implode(' ', $planLines));
        $this->profiles[$fingerprint]['plan'] = $planLines;
        $this->profiles[$fingerprint]['full_scan'] = str_contains($joined, 'SCAN ') && !str_contains($joined, 'USING');
        $this->profiles[$fingerprint]['temp_btree'] = str_contains($joined, 'USE TEMP B-TREE');
        $this->profiles[$fingerprint]['index_used'] = str_contains($joined, 'USING INDEX') || str_contains($joined, 'USING COVERING INDEX');
    }

    /** @return list<array<string, mixed>> */
    public function profilesBy(string $metric, int $limit = 10): array
    {
        $profiles = array_values($this->profiles);
        usort($profiles, static function (array $left, array $right) use ($metric): int {
            $comparison = ((float) ($right[$metric] ?? 0)) <=> ((float) ($left[$metric] ?? 0));
            return $comparison !== 0 ? $comparison : strcmp((string) $left['fingerprint'], (string) $right['fingerprint']);
        });

        return array_slice($profiles, 0, max(0, $limit));
    }

    /** @return list<array<string, mixed>> */
    public function planCandidates(int $limit = 10): array
    {
        $combined = [];
        foreach (array_merge($this->profilesBy('total_ms', $limit), $this->profilesBy('calls', $limit)) as $profile) {
            $combined[$profile['fingerprint']] = $profile;
        }

        return array_values($combined);
    }

    /** @return array<string, mixed>|null */
    public function profile(string $fingerprint): ?array
    {
        return $this->profiles[$fingerprint] ?? null;
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'total_sql_calls' => $this->totalCalls,
            'total_query_ms' => round($this->totalQueryMs, 3),
            'transaction_count' => $this->transactionCount,
            'transaction_total_ms' => round($this->transactionTotalMs, 3),
            'transaction_average_ms' => $this->transactionCount === 0 ? 0.0 : round($this->transactionTotalMs / $this->transactionCount, 3),
            'transaction_max_ms' => round($this->transactionMaxMs, 3),
            'commit_count' => $this->commitCount,
            'commit_total_ms' => round($this->commitTotalMs, 3),
            'commit_average_ms' => $this->commitCount === 0 ? 0.0 : round($this->commitTotalMs / $this->commitCount, 3),
            'commit_max_ms' => round($this->commitMaxMs, 3),
            'queries' => array_values($this->profiles),
        ];
    }

    public static function normalize(string $sql): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
        return rtrim($normalized, "; \t\r\n");
    }

    private function fingerprint(string $sql): string
    {
        if (isset($this->normalizationCache[$sql])) {
            return $this->normalizationCache[$sql];
        }
        if (count($this->normalizationCache) >= 2048) {
            array_shift($this->normalizationCache);
        }
        return $this->normalizationCache[$sql] = self::normalize($sql);
    }

    /** @param array<int|string, mixed> $parameters @return array<int|string, mixed> */
    private static function safeParameters(array $parameters): array
    {
        $safe = [];
        foreach ($parameters as $key => $value) {
            if (is_null($value) || is_scalar($value)) {
                $safe[$key] = $value;
            } elseif (is_array($value)) {
                $safe[$key] = self::safeParameters($value);
            } else {
                $safe[$key] = get_debug_type($value);
            }
        }

        return $safe;
    }
}
