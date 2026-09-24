<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Simulation;

/** Game-neutral metadata used by CLI, Doctor, tests, and web tooling. */
final readonly class SimulationCapability
{
    /** @param array<string,mixed> $inputSchema */
    public function __construct(
        private string $id,
        private string $label,
        private string $description,
        private string $category,
        private bool $readOnly,
        private string $scope,
        private string $permission,
        private array $inputSchema = [],
        private bool $available = true,
    ) {
        if (trim($id) === '' || trim($label) === '' || trim($category) === '') {
            throw new \InvalidArgumentException('Simulation capability metadata cannot be empty.');
        }
    }

    public function id(): string { return $this->id; }
    public function label(): string { return $this->label; }
    public function description(): string { return $this->description; }
    public function category(): string { return $this->category; }
    public function readOnly(): bool { return $this->readOnly; }
    public function scope(): string { return $this->scope; }
    public function permission(): string { return $this->permission; }
    /** @return array<string,mixed> */
    public function inputSchema(): array { return $this->inputSchema; }
    public function available(): bool { return $this->available; }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'description' => $this->description,
            'category' => $this->category,
            'read_only' => $this->readOnly,
            'scope' => $this->scope,
            'permission' => $this->permission,
            'input_schema' => $this->inputSchema,
            'available' => $this->available,
        ];
    }
}
