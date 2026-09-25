<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Support;

use Goal\Legacy\Core\Simulation\SimulationCheckpoint;
use PHPUnit\Framework\TestCase;

/** Small semantic assertions for Simulation Lab tests; gameplay stays canonical. */
trait SimulationAssertions
{
    private function assertCheckpointState(SimulationCheckpoint $checkpoint, string $key, mixed $expected): void
    {
        TestCase::assertArrayHasKey($key, $checkpoint->state(), sprintf('Checkpoint %s is missing state key %s.', $checkpoint->period(), $key));
        TestCase::assertSame($expected, $checkpoint->state()[$key], sprintf('Checkpoint %s state key %s differs.', $checkpoint->period(), $key));
    }

    /** @param array<string,mixed> $state */
    private function assertMatchStatus(array $state, string $expected): void
    {
        TestCase::assertSame($expected, $state['status'] ?? null, 'Canonical Match status differs.');
    }

    /** @param array<string,mixed> $state */
    private function assertMatchPlayer(array $state, string $playerId): void
    {
        TestCase::assertSame($playerId, $state['player_id'] ?? null, 'Canonical controlled Player identity differs.');
        TestCase::assertArrayHasKey('selection', $state, 'Canonical Match checkpoint has no selection projection.');
        TestCase::assertArrayHasKey('availability', $state, 'Canonical Match checkpoint has no availability projection.');
    }
}
