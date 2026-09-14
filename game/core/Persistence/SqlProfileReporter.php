<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Persistence;

final class SqlProfileReporter
{
    public function text(SqlProfiler $profiler, int $top = 10): string
    {
        $snapshot = $profiler->snapshot();
        $lines = [sprintf(
            'SQL_PROFILE calls=%d query_ms=%.1f transactions=%d transaction_ms=%.1f commits=%d commit_ms=%.1f',
            $snapshot['total_sql_calls'],
            $snapshot['total_query_ms'],
            $snapshot['transaction_count'],
            $snapshot['transaction_total_ms'],
            $snapshot['commit_count'],
            $snapshot['commit_total_ms'],
        )];

        foreach ($profiler->profilesBy('total_ms', $top) as $index => $profile) {
            $flags = [];
            if ($profile['full_scan']) { $flags[] = 'SCAN'; }
            if ($profile['temp_btree']) { $flags[] = 'TEMP_BTREE'; }
            if ($profile['index_used']) { $flags[] = 'INDEX'; }
            $lines[] = sprintf(
                'SQL_TOP rank=%d calls=%d total_ms=%.1f avg_ms=%.3f max_ms=%.3f flags=%s sql=%s',
                $index + 1,
                $profile['calls'],
                $profile['total_ms'],
                $profile['average_ms'],
                $profile['max_ms'],
                $flags === [] ? 'none' : implode(',', $flags),
                json_encode($profile['fingerprint'], JSON_THROW_ON_ERROR),
            );
        }

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    public function json(SqlProfiler $profiler, int $top = 10): array
    {
        $snapshot = $profiler->snapshot();
        $snapshot['top_by_total_ms'] = $profiler->profilesBy('total_ms', $top);
        $snapshot['top_by_calls'] = $profiler->profilesBy('calls', $top);
        return $snapshot;
    }
}
