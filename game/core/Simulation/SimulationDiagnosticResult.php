<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Simulation;

final readonly class SimulationDiagnosticResult
{
    /** @param list<string> $evidence @param array<string,mixed> $metrics */
    public function __construct(
        private string $id,
        private string $status,
        private string $severity,
        private string $summary,
        private array $evidence = [],
        private string $scope = 'GAME',
        private array $metrics = [],
        private string $suggestedAction = '',
        private string $cost = 'CHEAP',
    ) {
    }

    public function id(): string { return $this->id; }
    public function status(): string { return $this->status; }
    public function severity(): string { return $this->severity; }
    public function summary(): string { return $this->summary; }
    /** @return list<string> */
    public function evidence(): array { return $this->evidence; }
    public function scope(): string { return $this->scope; }
    /** @return array<string,mixed> */
    public function metrics(): array { return $this->metrics; }
    public function suggestedAction(): string { return $this->suggestedAction; }
    public function cost(): string { return $this->cost; }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'status' => $this->status, 'severity' => $this->severity, 'summary' => $this->summary, 'evidence' => $this->evidence, 'scope' => $this->scope, 'metrics' => $this->metrics, 'suggested_action' => $this->suggestedAction, 'cost' => $this->cost];
    }
}
