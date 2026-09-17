<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use InvalidArgumentException;

/** Cosmetic identity only; it never participates in football simulation. */
final readonly class PlayerAppearance
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private string $skinTone,
        private string $face,
        private string $jaw,
        private string $ears,
        private string $eyes,
        private string $eyeColor,
        private string $brows,
        private string $nose,
        private string $mouth,
        private string $hair,
        private string $hairColor,
        private string $facialHair,
        private string $facialHairColor,
        private string $skinDetail,
        private string $scar,
        private string $accessory,
    ) {
        foreach ($this->toArray() as $field => $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException(sprintf('Appearance field "%s" cannot be empty.', $field));
            }
            if (!str_starts_with($value, 'avatar.') && !str_starts_with($value, 'palette.')) {
                throw new InvalidArgumentException(sprintf('Appearance field "%s" has an invalid asset ID.', $field));
            }
        }
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        return new self(
            (string) ($values['skin_tone'] ?? ''),
            (string) ($values['face'] ?? ''),
            (string) ($values['jaw'] ?? ''),
            (string) ($values['ears'] ?? ''),
            (string) ($values['eyes'] ?? ''),
            (string) ($values['eye_color'] ?? ''),
            (string) ($values['brows'] ?? ''),
            (string) ($values['nose'] ?? ''),
            (string) ($values['mouth'] ?? ''),
            (string) ($values['hair'] ?? ''),
            (string) ($values['hair_color'] ?? ''),
            (string) ($values['facial_hair'] ?? ''),
            (string) ($values['facial_hair_color'] ?? ''),
            (string) ($values['skin_detail'] ?? ''),
            (string) ($values['scar'] ?? ''),
            (string) ($values['accessory'] ?? ''),
        );
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'skin_tone' => $this->skinTone,
            'face' => $this->face,
            'jaw' => $this->jaw,
            'ears' => $this->ears,
            'eyes' => $this->eyes,
            'eye_color' => $this->eyeColor,
            'brows' => $this->brows,
            'nose' => $this->nose,
            'mouth' => $this->mouth,
            'hair' => $this->hair,
            'hair_color' => $this->hairColor,
            'facial_hair' => $this->facialHair,
            'facial_hair_color' => $this->facialHairColor,
            'skin_detail' => $this->skinDetail,
            'scar' => $this->scar,
            'accessory' => $this->accessory,
        ];
    }

    public function schemaVersion(): int { return self::SCHEMA_VERSION; }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }

    /** @param array<string, string> $changes */
    public function withChanges(array $changes): self
    {
        return self::fromArray(array_replace($this->toArray(), $changes));
    }
}
