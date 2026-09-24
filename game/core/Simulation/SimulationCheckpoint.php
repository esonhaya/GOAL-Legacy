<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Simulation;

/** Compact, non-persisted observation returned by a simulation adapter. */
final readonly class SimulationCheckpoint
{
    /** @param array<string,mixed> $state @param array<string,mixed> $metadata */
    public function __construct(
        private string $period,
        private string $scope,
        private array $state,
        private array $metadata = [],
    ) {
    }

    public function period(): string { return $this->period; }
    public function scope(): string { return $this->scope; }
    /** @return array<string,mixed> */
    public function state(): array { return $this->state; }
    /** @return array<string,mixed> */
    public function metadata(): array { return $this->metadata; }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['period' => $this->period, 'scope' => $this->scope, 'state' => $this->state, 'metadata' => $this->metadata];
    }
}
