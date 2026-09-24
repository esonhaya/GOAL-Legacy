<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Simulation;

final readonly class SimulationResult
{
    /** @param list<SimulationCheckpoint> $checkpoints @param list<SimulationDiagnosticResult> $findings @param array<string,mixed> $metrics */
    public function __construct(
        private string $status,
        private array $checkpoints = [],
        private array $findings = [],
        private array $metrics = [],
    ) {
    }

    public function status(): string { return $this->status; }
    /** @return list<SimulationCheckpoint> */
    public function checkpoints(): array { return $this->checkpoints; }
    /** @return list<SimulationDiagnosticResult> */
    public function findings(): array { return $this->findings; }
    /** @return array<string,mixed> */
    public function metrics(): array { return $this->metrics; }
}
