<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Simulation;

/** Shared orchestration used by tests, CLI, Doctor, and web frontends. */
final class SimulationToolkit
{
    public function __construct(private readonly GameSimulationAdapter $adapter)
    {
    }

    public function adapter(): GameSimulationAdapter { return $this->adapter; }

    /** @return list<SimulationCapability> */
    public function capabilities(): array { return $this->adapter->capabilities(); }

    /** @return list<array<string,mixed>> */
    public function capabilityDescriptors(): array
    {
        return array_map(static fn (SimulationCapability $capability): array => $capability->toArray(), $this->capabilities());
    }

    /** @return array<string,mixed> */
    public function inspect(string $saveId): array { return $this->adapter->inspect($saveId); }

    public function run(SimulationScenario $scenario): SimulationResult
    {
        if ($scenario->game() !== $this->adapter->gameIdentifier()) {
            throw new \InvalidArgumentException('Simulation scenario belongs to a different game adapter.');
        }

        return $this->adapter->run($scenario);
    }

    /** @return list<SimulationDiagnosticResult> */
    public function diagnostics(string $saveId, ?array $ids = null): array { return $this->adapter->diagnostics($saveId, $ids); }

    public function mutate(SimulationMutation $mutation): SimulationMutationResult
    {
        foreach ($this->capabilities() as $capability) {
            if ($capability->id() === $mutation->capability()) {
                if ($capability->readOnly()) {
                    throw new \RuntimeException('Read-only simulation capabilities cannot mutate state.');
                }
                if (!$capability->available()) {
                    throw new \RuntimeException('That simulation capability is unavailable.');
                }
                if (!SimulationPermission::canUse($mutation->permission(), $capability->permission())) {
                    throw new \RuntimeException('The supplied permission cannot use this capability.');
                }
                return $this->adapter->mutate($mutation);
            }
        }

        throw new \RuntimeException(sprintf('Unsupported simulation capability "%s".', $mutation->capability()));
    }
}
