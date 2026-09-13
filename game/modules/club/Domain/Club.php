<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Club\Domain;

use Goal\Legacy\Modules\Nation\Domain\NationId;
use InvalidArgumentException;

final readonly class Club
{
    public function __construct(
        private ClubId $id,
        private string $canonicalName,
        private string $shortName,
        private ?string $nickname,
        private NationId $nationId,
        private string $city,
        private int $foundedYear,
        private string $stadiumName,
        private string $clubColors,
        private string $corePhilosophy,
        private string $footballIdentity,
        private string $currentStyle,
        private int $reputation,
        private int $facilitiesLevel,
        private string $sourcePackageId,
        private string $sourcePackageVersion,
        private int $sourceSchemaVersion,
    ) {
        foreach ([
            'canonical name' => $canonicalName,
            'short name' => $shortName,
            'city' => $city,
            'stadium name' => $stadiumName,
            'club colors' => $clubColors,
            'Core Philosophy' => $corePhilosophy,
            'Football Identity' => $footballIdentity,
            'Current Style' => $currentStyle,
        ] as $label => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf('Club %s cannot be empty.', $label));
            }
        }
        if ($nickname !== null && trim($nickname) === '') {
            throw new InvalidArgumentException('Club nicknames must be non-empty when supplied.');
        }
        if ($foundedYear < 1800 || $foundedYear > 2100) {
            throw new InvalidArgumentException('Club founded years must be between 1800 and 2100.');
        }
        if ($reputation < 0 || $reputation > 100 || $facilitiesLevel < 0 || $facilitiesLevel > 100) {
            throw new InvalidArgumentException('Club reputation and facilities levels must be between 0 and 100.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $sourcePackageId) !== 1) {
            throw new InvalidArgumentException('Club source package ID must be a valid package ID.');
        }
        if (trim($sourcePackageVersion) === '' || $sourceSchemaVersion < 1) {
            throw new InvalidArgumentException('Club content provenance is invalid.');
        }
    }

    public function id(): ClubId { return $this->id; }

    public function canonicalName(): string { return $this->canonicalName; }

    public function shortName(): string { return $this->shortName; }

    public function nickname(): ?string { return $this->nickname; }

    public function nationId(): NationId { return $this->nationId; }

    public function city(): string { return $this->city; }

    public function foundedYear(): int { return $this->foundedYear; }

    public function stadiumName(): string { return $this->stadiumName; }

    public function clubColors(): string { return $this->clubColors; }

    public function corePhilosophy(): string { return $this->corePhilosophy; }

    public function footballIdentity(): string { return $this->footballIdentity; }

    public function currentStyle(): string { return $this->currentStyle; }

    public function reputation(): int { return $this->reputation; }

    public function facilitiesLevel(): int { return $this->facilitiesLevel; }

    public function sourcePackageId(): string { return $this->sourcePackageId; }

    public function sourcePackageVersion(): string { return $this->sourcePackageVersion; }

    public function sourceSchemaVersion(): int { return $this->sourceSchemaVersion; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'canonical_name' => $this->canonicalName,
            'city' => $this->city,
            'club_colors' => $this->clubColors,
            'core_philosophy' => $this->corePhilosophy,
            'current_style' => $this->currentStyle,
            'facilities_level' => $this->facilitiesLevel,
            'football_identity' => $this->footballIdentity,
            'founded_year' => $this->foundedYear,
            'id' => $this->id->value(),
            'nation_id' => $this->nationId->value(),
            'nickname' => $this->nickname,
            'reputation' => $this->reputation,
            'short_name' => $this->shortName,
            'source_package_id' => $this->sourcePackageId,
            'source_package_version' => $this->sourcePackageVersion,
            'source_schema_version' => $this->sourceSchemaVersion,
            'stadium_name' => $this->stadiumName,
        ];
    }
}
