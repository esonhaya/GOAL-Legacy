<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Simulation;

/** Bounded, game-neutral comparison for curated state/checkpoint payloads. */
final class SimulationStateDiff
{
    /** @param array<string,mixed> $before @param array<string,mixed> $after @return list<array{path:string,status:string,before:mixed,after:mixed}> */
    public static function compare(array $before, array $after, int $limit = 100): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Simulation diff limit must be positive.');
        }

        $changes = [];
        self::walk($before, $after, '', $changes, $limit);

        return $changes;
    }

    /** @param mixed $before @param mixed $after @param list<array{path:string,status:string,before:mixed,after:mixed}> $changes */
    private static function walk(mixed $before, mixed $after, string $path, array &$changes, int $limit): void
    {
        if (count($changes) >= $limit) {
            return;
        }
        if (is_array($before) && is_array($after)) {
            $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
            sort($keys, SORT_STRING);
            foreach ($keys as $key) {
                $child = $path === '' ? (string) $key : $path . '.' . $key;
                if (!array_key_exists($key, $before)) {
                    $changes[] = ['path' => $child, 'status' => 'added', 'before' => null, 'after' => $after[$key]];
                } elseif (!array_key_exists($key, $after)) {
                    $changes[] = ['path' => $child, 'status' => 'removed', 'before' => $before[$key], 'after' => null];
                } else {
                    self::walk($before[$key], $after[$key], $child, $changes, $limit);
                }
                if (count($changes) >= $limit) {
                    return;
                }
            }
            return;
        }
        if ($before !== $after) {
            $changes[] = ['path' => $path === '' ? '$' : $path, 'status' => 'changed', 'before' => $before, 'after' => $after];
        }
    }
}
