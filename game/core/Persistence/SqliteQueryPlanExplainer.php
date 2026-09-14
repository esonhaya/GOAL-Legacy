<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Persistence;

use PDO;
use Throwable;

final class SqliteQueryPlanExplainer
{
    /** @return list<array<string, mixed>> */
    public function explain(PDO $connection, SqlProfiler $profiler, int $limit = 10): array
    {
        $plans = [];
        foreach ($profiler->planCandidates($limit) as $profile) {
            $sql = (string) $profile['fingerprint'];
            if (!preg_match('/^(SELECT|WITH)\b/i', $sql)) {
                continue;
            }

            try {
                $lines = $profiler->withoutProfiling(function () use ($connection, $sql, $profile): array {
                    $statement = $connection->prepare('EXPLAIN QUERY PLAN ' . $sql);
                    $statement->execute(is_array($profile['example_params'] ?? null) ? $profile['example_params'] : []);
                    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
                    return array_values(array_map(static fn (array $row): string => (string) ($row['detail'] ?? implode(' ', array_map('strval', $row))), $rows));
                });
            } catch (Throwable) {
                continue;
            }

            $profiler->recordPlan($sql, $lines);
            $updated = $profiler->profile($sql) ?? [];
            $plans[] = [
                'fingerprint' => $sql,
                'plan' => $lines,
                'full_scan' => (bool) ($updated['full_scan'] ?? false),
                'temp_btree' => (bool) ($updated['temp_btree'] ?? false),
                'index_used' => (bool) ($updated['index_used'] ?? false),
            ];
        }

        return $plans;
    }
}
