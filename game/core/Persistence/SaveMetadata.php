<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Persistence;

use DateTimeImmutable;
use Goal\Legacy\Core\Time\SimulationTime;
use InvalidArgumentException;

final readonly class SaveMetadata
{
    public const FORMAT_VERSION = 1;

    public function __construct(
        private string $id,
        private string $name,
        private int $formatVersion,
        private string $createdAt,
        private string $updatedAt,
        private SimulationTime $simulationTime,
    ) {
        self::validateId($id);
        if (trim($name) === '') {
            throw new InvalidArgumentException('Save name cannot be empty.');
        }
        if ($formatVersion < 1) {
            throw new InvalidArgumentException('Save format version must be positive.');
        }
        self::validateTimestamp($createdAt, 'createdAt');
        self::validateTimestamp($updatedAt, 'updatedAt');
    }

    public static function create(
        string $id,
        string $name,
        SimulationTime $simulationTime,
        DateTimeImmutable $timestamp,
    ): self {
        $formatted = $timestamp->format(DATE_ATOM);

        return new self($id, $name, self::FORMAT_VERSION, $formatted, $formatted, $simulationTime);
    }

    public static function fromArray(array $data): self
    {
        $required = ['id', 'name', 'format_version', 'created_at', 'updated_at', 'simulation_time'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) {
                throw new PersistenceException(sprintf('Save metadata is missing required field "%s".', $field));
            }
        }

        if (!is_string($data['id']) || !is_string($data['name']) || !is_int($data['format_version'])
            || !is_string($data['created_at']) || !is_string($data['updated_at']) || !is_int($data['simulation_time'])) {
            throw new PersistenceException('Save metadata contains invalid field types.');
        }

        return new self(
            $data['id'],
            $data['name'],
            $data['format_version'],
            $data['created_at'],
            $data['updated_at'],
            new SimulationTime($data['simulation_time']),
        );
    }

    public function id(): string { return $this->id; }

    public function name(): string { return $this->name; }

    public function formatVersion(): int { return $this->formatVersion; }

    public function createdAt(): string { return $this->createdAt; }

    public function updatedAt(): string { return $this->updatedAt; }

    public function simulationTime(): SimulationTime { return $this->simulationTime; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'created_at' => $this->createdAt,
            'format_version' => $this->formatVersion,
            'id' => $this->id,
            'name' => $this->name,
            'simulation_time' => $this->simulationTime->ticks(),
            'updated_at' => $this->updatedAt,
        ];
    }

    private static function validateId(string $id): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $id) !== 1) {
            throw new InvalidArgumentException('Save IDs must be 1-64 characters using letters, numbers, hyphens, or underscores.');
        }
    }

    private static function validateTimestamp(string $timestamp, string $field): void
    {
        try {
            new DateTimeImmutable($timestamp);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException(sprintf('Save metadata "%s" must be a valid timestamp.', $field), 0, $exception);
        }
    }
}
