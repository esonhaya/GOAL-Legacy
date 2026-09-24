<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Simulation;

/**
 * Boundary for a Haya simulation game. No football concepts belong here.
 */
interface GameSimulationAdapter
{
    public function gameIdentifier(): string;

    /** @return list<SimulationCapability> */
    public function capabilities(): array;

    /** @return array<string,mixed> */
    public function inspect(string $saveId): array;

    public function run(SimulationScenario $scenario): SimulationResult;

    /** @return list<SimulationDiagnosticResult> */
    public function diagnostics(string $saveId, ?array $ids = null): array;

    public function mutate(SimulationMutation $mutation): SimulationMutationResult;
}
