<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Simulation;

/** Generic scenario request; game-specific options stay inside an adapter. */
final readonly class SimulationScenario
{
    /** @param list<string> $checkpoints @param array<string,mixed> $options */
    public function __construct(
        private string $game,
        private int $seed,
        private int $horizon,
        private array $checkpoints = [],
        private array $options = [],
    ) {
        if (trim($game) === '' || $horizon < 1) {
            throw new \InvalidArgumentException('Simulation scenario requires a game and positive horizon.');
        }
    }

    public function game(): string { return $this->game; }
    public function seed(): int { return $this->seed; }
    public function horizon(): int { return $this->horizon; }
    /** @return list<string> */
    public function checkpoints(): array { return $this->checkpoints; }
    /** @return array<string,mixed> */
    public function options(): array { return $this->options; }
}
