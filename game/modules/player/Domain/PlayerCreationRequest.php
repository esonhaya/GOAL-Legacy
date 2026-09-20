<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

final readonly class PlayerCreationRequest
{
    /** @param list<string> $secondaryNationIds @param list<string>|null $eligibilityNationIds */
    public function __construct(
        public string $playerId,
        public string $firstName,
        public string $lastName,
        public ?string $preferredName,
        public string $birthDate,
        public string $primaryNationId,
        public array $secondaryNationIds,
        public ?string $birthNationId,
        public ?array $eligibilityNationIds,
        public int $heightCm,
        public int $weightKg,
        public string $primaryPosition,
        public int $potential,
        public string $developmentProfile,
        public int $seed = 0,
        public ?PlayerAttributeSet $attributes = null,
        public ?PlayerFoot $preferredFoot = null,
        public ?WeakFootTier $weakFoot = null,
    ) {
    }
}
