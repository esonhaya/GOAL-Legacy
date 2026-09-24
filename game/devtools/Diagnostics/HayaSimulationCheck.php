<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Diagnostics;

use Goal\Legacy\Core\Simulation\GameSimulationAdapter;
use Tools\Doctor\Contracts\CheckIdentityInterface;
use Tools\Doctor\Contracts\CheckInterface;
use Tools\Doctor\DTO\CheckResult;
use Tools\Doctor\DTO\CheckStatus;

/** Generic Doctor frontend: it knows the adapter contract, not GOAL internals. */
final class HayaSimulationCheck implements CheckInterface, CheckIdentityInterface
{
    public function __construct(private readonly GameSimulationAdapter $adapter) {}

    public function id(): string { return 'haya.simulation.' . $this->adapter->gameIdentifier(); }

    public function run(): CheckResult
    {
        $capabilities = $this->adapter->capabilities();
        $ids = array_map(static fn ($capability): string => $capability->id(), $capabilities);
        $unique = count($ids) === count(array_unique($ids));
        return new CheckResult(
            title: 'Haya Simulation Toolkit — ' . $this->adapter->gameIdentifier(),
            status: $unique && $capabilities !== [] ? CheckStatus::PASS : CheckStatus::FAIL,
            summary: $unique && $capabilities !== [] ? 'Game adapter is discoverable through the game-neutral simulation contract.' : 'Adapter capability registration is invalid.',
            details: ['capabilities=' . count($capabilities), 'unique_ids=' . ($unique ? 'yes' : 'no'), 'long diagnostics are not run automatically'],
            recommendations: $unique ? [] : ['Remove duplicate capability IDs from the game adapter.'],
            score: $unique && $capabilities !== [] ? 100 : 0,
            scope: 'DOCTOR',
            id: $this->id(),
            metadata: ['game' => $this->adapter->gameIdentifier(), 'capabilities' => $ids, 'cost' => 'CHEAP'],
        );
    }

    public function category(): string { return 'haya.simulation'; }
    public function priority(): int { return 20; }
}
