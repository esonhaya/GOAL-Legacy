<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Simulation;

final readonly class SimulationMutationResult
{
    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    public function __construct(
        private string $status,
        private array $before = [],
        private array $after = [],
        private string $message = '',
    ) {
    }

    public function status(): string { return $this->status; }
    /** @return array<string,mixed> */
    public function before(): array { return $this->before; }
    /** @return array<string,mixed> */
    public function after(): array { return $this->after; }
    public function message(): string { return $this->message; }
}
