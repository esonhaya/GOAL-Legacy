<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Nation\Domain;

use InvalidArgumentException;

final readonly class Nation
{
    public function __construct(
        private NationId $id,
        private string $canonicalName,
        private string $displayName,
        private ?string $code,
        private ?string $regionId,
        private ?string $geographyReferenceId,
        private ?string $associationId,
        private string $sourcePackageId,
        private string $sourcePackageVersion,
        private int $sourceSchemaVersion,
    ) {
        if (trim($canonicalName) === '' || trim($displayName) === '') {
            throw new InvalidArgumentException('Nation canonical and display names cannot be empty.');
        }
        if ($code !== null && preg_match('/^[A-Z0-9][A-Z0-9_-]{1,15}$/', $code) !== 1) {
            throw new InvalidArgumentException('Nation codes must be 2-16 uppercase letters, numbers, hyphens, or underscores.');
        }
        foreach (['regionId' => $regionId, 'geographyReferenceId' => $geographyReferenceId, 'associationId' => $associationId] as $field => $reference) {
            if ($reference !== null && preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $reference) !== 1) {
                throw new InvalidArgumentException(sprintf('Nation %s must be a valid stable reference.', $field));
            }
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $sourcePackageId) !== 1) {
            throw new InvalidArgumentException('Nation source package ID must be a valid package ID.');
        }
        if (trim($sourcePackageVersion) === '') {
            throw new InvalidArgumentException('Nation source package version cannot be empty.');
        }
        if ($sourceSchemaVersion < 1) {
            throw new InvalidArgumentException('Nation content schema version must be positive.');
        }
    }

    public function id(): NationId { return $this->id; }

    public function canonicalName(): string { return $this->canonicalName; }

    public function displayName(): string { return $this->displayName; }

    public function code(): ?string { return $this->code; }

    public function regionId(): ?string { return $this->regionId; }

    public function geographyReferenceId(): ?string { return $this->geographyReferenceId; }

    public function associationId(): ?string { return $this->associationId; }

    public function sourcePackageId(): string { return $this->sourcePackageId; }

    public function sourcePackageVersion(): string { return $this->sourcePackageVersion; }

    public function sourceSchemaVersion(): int { return $this->sourceSchemaVersion; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'association_id' => $this->associationId,
            'canonical_name' => $this->canonicalName,
            'code' => $this->code,
            'display_name' => $this->displayName,
            'geography_reference_id' => $this->geographyReferenceId,
            'id' => $this->id->value(),
            'region_id' => $this->regionId,
            'source_package_id' => $this->sourcePackageId,
            'source_package_version' => $this->sourcePackageVersion,
            'source_schema_version' => $this->sourceSchemaVersion,
        ];
    }
}
