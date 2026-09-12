<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Content;

use InvalidArgumentException;

final readonly class ContentPackageManifest
{
    public const SUPPORTED_SCHEMA_VERSION = 1;

    /**
     * @param list<string> $dependencies
     * @param list<string> $files
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $id,
        private string $name,
        private string $version,
        private int $schemaVersion,
        private array $dependencies = [],
        private array $files = [],
        private array $metadata = [],
    ) {
        self::validateId($id);
        if (trim($name) === '') {
            throw new InvalidArgumentException('Content package name cannot be empty.');
        }
        if (preg_match('/^\\d+\\.\\d+\\.\\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) !== 1) {
            throw new InvalidArgumentException(sprintf('Content package "%s" has an invalid version.', $id));
        }
        if ($schemaVersion < 1) {
            throw new InvalidArgumentException('Content schema version must be positive.');
        }
        if ($schemaVersion > self::SUPPORTED_SCHEMA_VERSION) {
            throw new UnsupportedContentSchemaException(sprintf(
                'Content package "%s" uses unsupported schema version %d.',
                $id,
                $schemaVersion,
            ));
        }
        self::validateStringList($dependencies, 'dependency', true);
        self::validateStringList($files, 'declared file', false);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['id', 'name', 'version', 'schema_version'] as $field) {
            if (!array_key_exists($field, $data)) {
                throw new ContentPackageException(sprintf('Content manifest is missing required field "%s".', $field));
            }
        }

        if (!is_string($data['id']) || !is_string($data['name']) || !is_string($data['version']) || !is_int($data['schema_version'])) {
            throw new ContentPackageException('Content manifest contains invalid required field types.');
        }
        foreach (['dependencies', 'files'] as $field) {
            if (isset($data[$field]) && !is_array($data[$field])) {
                throw new ContentPackageException(sprintf('Content manifest field "%s" must be an array.', $field));
            }
        }
        if (isset($data['metadata']) && !is_array($data['metadata'])) {
            throw new ContentPackageException('Content manifest field "metadata" must be an object.');
        }

        return new self(
            $data['id'],
            $data['name'],
            $data['version'],
            $data['schema_version'],
            array_values($data['dependencies'] ?? []),
            array_values($data['files'] ?? []),
            $data['metadata'] ?? [],
        );
    }

    public function id(): string { return $this->id; }

    public function name(): string { return $this->name; }

    public function version(): string { return $this->version; }

    public function schemaVersion(): int { return $this->schemaVersion; }

    /** @return list<string> */
    public function dependencies(): array { return $this->dependencies; }

    /** @return list<string> */
    public function files(): array { return $this->files; }

    /** @return array<string, mixed> */
    public function metadata(): array { return $this->metadata; }

    private static function validateId(string $id): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $id) !== 1) {
            throw new InvalidArgumentException('Content package IDs must be 1-64 lowercase letters, numbers, dots, hyphens, or underscores.');
        }
    }

    /** @param array<int, mixed> $values */
    private static function validateStringList(array $values, string $label, bool $packageIds): void
    {
        $seen = [];
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException(sprintf('Content manifest %s entries must be non-empty strings.', $label));
            }
            if ($packageIds) {
                self::validateId($value);
            }
            if (isset($seen[$value])) {
                throw new InvalidArgumentException(sprintf('Content manifest contains duplicate %s "%s".', $label, $value));
            }
            $seen[$value] = true;
        }
    }
}
