<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Simulation;

final readonly class GoalScenarioDefinition
{
    /** @param list<string> $requiredCapabilities @param array<string,mixed> $defaults */
    public function __construct(
        private string $id,
        private string $label,
        private string $description,
        private string $cost,
        private array $requiredCapabilities,
        private array $defaults = [],
    ) {
    }

    public function id(): string { return $this->id; }
    public function label(): string { return $this->label; }
    public function description(): string { return $this->description; }
    public function cost(): string { return $this->cost; }
    /** @return list<string> */
    public function requiredCapabilities(): array { return $this->requiredCapabilities; }
    /** @return array<string,mixed> */
    public function defaults(): array { return $this->defaults; }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'description' => $this->description, 'cost' => $this->cost, 'required_capabilities' => $this->requiredCapabilities, 'defaults' => $this->defaults];
    }
}
