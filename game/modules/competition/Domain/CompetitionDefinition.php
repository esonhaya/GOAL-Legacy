<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Competition\Domain;

use Goal\Legacy\Modules\Nation\Domain\NationId;
use InvalidArgumentException;

final readonly class CompetitionDefinition
{
    public function __construct(
        private CompetitionId $id,
        private string $name,
        private string $shortName,
        private CompetitionType $type,
        private NationId $nationId,
        private string $sourcePackageId,
        private string $sourcePackageVersion,
        private int $sourceSchemaVersion,
        private int $maximumSubstitutions = 5,
    ) {
        if (trim($name) === '' || trim($shortName) === '') {
            throw new InvalidArgumentException('Competition names cannot be empty.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $sourcePackageId) !== 1) {
            throw new InvalidArgumentException('Competition source package ID must be a valid package ID.');
        }
        if (trim($sourcePackageVersion) === '') {
            throw new InvalidArgumentException('Competition source package version cannot be empty.');
        }
        if ($sourceSchemaVersion < 1) {
            throw new InvalidArgumentException('Competition content schema version must be positive.');
        }
        if ($maximumSubstitutions < 1 || $maximumSubstitutions > 5) {
            throw new InvalidArgumentException('Competition maximum substitutions must be between 1 and 5.');
        }
    }

    public function id(): CompetitionId { return $this->id; }

    public function name(): string { return $this->name; }

    public function shortName(): string { return $this->shortName; }

    public function type(): CompetitionType { return $this->type; }

    public function nationId(): NationId { return $this->nationId; }

    public function sourcePackageId(): string { return $this->sourcePackageId; }

    public function sourcePackageVersion(): string { return $this->sourcePackageVersion; }

    public function sourceSchemaVersion(): int { return $this->sourceSchemaVersion; }

    public function maximumSubstitutions(): int { return $this->maximumSubstitutions; }
}
