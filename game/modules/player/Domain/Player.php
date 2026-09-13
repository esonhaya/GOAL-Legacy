<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use Goal\Legacy\Modules\Nation\Domain\NationId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use InvalidArgumentException;

final readonly class Player
{
    /** @param list<NationId> $secondaryNationIds @param list<NationId> $eligibilityNationIds */
    public function __construct(
        private PlayerId $id,
        private string $firstName,
        private string $lastName,
        private string $preferredName,
        private SimulationDate $birthDate,
        private NationId $primaryNationId,
        private array $secondaryNationIds,
        private NationId $birthNationId,
        private array $eligibilityNationIds,
        private int $heightCm,
        private int $weightKg,
        private PlayerPosition $primaryPosition,
        private PlayerAttributeSet $attributes,
        private int $potential,
        private DevelopmentProfile $developmentProfile,
        private int $creationSeed,
    ) {
        foreach (['first name' => $firstName, 'last name' => $lastName, 'preferred name' => $preferredName] as $label => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf('Player %s cannot be empty.', $label));
            }
        }
        if ($heightCm < 120 || $heightCm > 250) {
            throw new InvalidArgumentException('Player height must be between 120 and 250 centimetres.');
        }
        if ($weightKg < 30 || $weightKg > 200) {
            throw new InvalidArgumentException('Player weight must be between 30 and 200 kilograms.');
        }
        if ($potential < 1 || $potential > 99) {
            throw new InvalidArgumentException('Player potential must be between 1 and 99.');
        }
        if ($this->overallRating() > $potential) {
            throw new InvalidArgumentException('Player overall rating cannot exceed potential.');
        }
        $this->assertNationList($secondaryNationIds, $primaryNationId, 'secondary nationality');
        $this->assertUniqueNationList($eligibilityNationIds, 'eligibility');
        if ($eligibilityNationIds === []) {
            throw new InvalidArgumentException('Player eligibility must contain at least one Nation.');
        }
    }

    public function id(): PlayerId { return $this->id; }

    public function firstName(): string { return $this->firstName; }

    public function lastName(): string { return $this->lastName; }

    public function preferredName(): string { return $this->preferredName; }

    public function fullName(): string { return trim($this->firstName . ' ' . $this->lastName); }

    public function birthDate(): SimulationDate { return $this->birthDate; }

    public function primaryNationId(): NationId { return $this->primaryNationId; }

    /** @return list<NationId> */
    public function secondaryNationIds(): array { return $this->secondaryNationIds; }

    public function birthNationId(): NationId { return $this->birthNationId; }

    /** @return list<NationId> */
    public function eligibilityNationIds(): array { return $this->eligibilityNationIds; }

    /** @return list<NationId> */
    public function nationalityIds(): array { return array_merge([$this->primaryNationId], $this->secondaryNationIds); }

    public function heightCm(): int { return $this->heightCm; }

    public function weightKg(): int { return $this->weightKg; }

    public function primaryPosition(): PlayerPosition { return $this->primaryPosition; }

    public function attributes(): PlayerAttributeSet { return $this->attributes; }

    public function overallRating(): int { return $this->attributes->overallRating(); }

    public function potential(): int { return $this->potential; }

    public function developmentProfile(): DevelopmentProfile { return $this->developmentProfile; }

    public function creationSeed(): int { return $this->creationSeed; }

    public function ageAt(SimulationDate $date): int
    {
        if ($date->isBefore($this->birthDate)) {
            throw new InvalidArgumentException('Player age cannot be calculated before the birth date.');
        }
        $age = $date->year() - $this->birthDate->year();
        if ($date->month() < $this->birthDate->month()
            || ($date->month() === $this->birthDate->month() && $date->day() < $this->birthDate->day())) {
            --$age;
        }

        return $age;
    }

    public function withAttributes(PlayerAttributeSet $attributes): self
    {
        return new self(
            $this->id,
            $this->firstName,
            $this->lastName,
            $this->preferredName,
            $this->birthDate,
            $this->primaryNationId,
            $this->secondaryNationIds,
            $this->birthNationId,
            $this->eligibilityNationIds,
            $this->heightCm,
            $this->weightKg,
            $this->primaryPosition,
            $attributes,
            $this->potential,
            $this->developmentProfile,
            $this->creationSeed,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'attributes' => $this->attributes->toArray(),
            'birth_date' => $this->birthDate->toIsoString(),
            'birth_nation_id' => $this->birthNationId->value(),
            'creation_seed' => $this->creationSeed,
            'development_profile' => $this->developmentProfile->value,
            'eligibility_nation_ids' => array_map(static fn (NationId $id): string => $id->value(), $this->eligibilityNationIds),
            'first_name' => $this->firstName,
            'height_cm' => $this->heightCm,
            'id' => $this->id->value(),
            'last_name' => $this->lastName,
            'nationality_ids' => array_map(static fn (NationId $id): string => $id->value(), $this->nationalityIds()),
            'overall_rating' => $this->overallRating(),
            'potential' => $this->potential,
            'preferred_name' => $this->preferredName,
            'primary_nation_id' => $this->primaryNationId->value(),
            'primary_position' => $this->primaryPosition->value,
            'weight_kg' => $this->weightKg,
        ];
    }

    /** @param list<NationId> $ids */
    private function assertNationList(array $ids, NationId $primary, string $label): void
    {
        $this->assertUniqueNationList($ids, $label);
        foreach ($ids as $id) {
            if ($id->value() === $primary->value()) {
                throw new InvalidArgumentException(sprintf('Player %s cannot repeat the primary Nation.', $label));
            }
        }
    }

    /** @param list<NationId> $ids */
    private function assertUniqueNationList(array $ids, string $label): void
    {
        $seen = [];
        foreach ($ids as $id) {
            if (!$id instanceof NationId) {
                throw new InvalidArgumentException(sprintf('Player %s references must be Nation IDs.', $label));
            }
            if (isset($seen[$id->value()])) {
                throw new InvalidArgumentException(sprintf('Player %s Nation references cannot be duplicated.', $label));
            }
            $seen[$id->value()] = true;
        }
    }
}
