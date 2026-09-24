<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Simulation;

final readonly class SimulationMutation
{
    /** @param array<string,mixed> $input */
    public function __construct(
        private string $capability,
        private string $saveId,
        private string $target,
        private array $input,
        private string $actor,
        private string $permission,
    ) {
    }

    public function capability(): string { return $this->capability; }
    public function saveId(): string { return $this->saveId; }
    public function target(): string { return $this->target; }
    /** @return array<string,mixed> */
    public function input(): array { return $this->input; }
    public function actor(): string { return $this->actor; }
    public function permission(): string { return $this->permission; }
}
