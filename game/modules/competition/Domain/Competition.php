<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Competition\Domain;

use Goal\Legacy\Modules\World\Domain\SeasonId;
use InvalidArgumentException;

final readonly class Competition
{
    public function __construct(
        private CompetitionDefinition $definition,
        private CompetitionStatus $status = CompetitionStatus::Upcoming,
        private ?SeasonId $seasonId = null,
    ) {
        if ($status !== CompetitionStatus::Upcoming && $seasonId === null) {
            throw new InvalidArgumentException('Active or completed Competitions require a Season ID.');
        }
    }

    public function definition(): CompetitionDefinition { return $this->definition; }

    public function id(): CompetitionId { return $this->definition->id(); }

    public function name(): string { return $this->definition->name(); }

    public function shortName(): string { return $this->definition->shortName(); }

    public function type(): CompetitionType { return $this->definition->type(); }

    public function maximumSubstitutions(): int { return $this->definition->maximumSubstitutions(); }

    public function nationId(): \Goal\Legacy\Modules\Nation\Domain\NationId { return $this->definition->nationId(); }

    public function status(): CompetitionStatus { return $this->status; }

    public function seasonId(): ?SeasonId { return $this->seasonId; }

    public function activate(SeasonId $seasonId): self
    {
        if ($this->status !== CompetitionStatus::Upcoming) {
            throw new InvalidArgumentException('Only upcoming Competitions can become active.');
        }

        return new self($this->definition, CompetitionStatus::Active, $seasonId);
    }

    public function complete(): self
    {
        if ($this->status !== CompetitionStatus::Active || $this->seasonId === null) {
            throw new InvalidArgumentException('Only active Competitions can become completed.');
        }

        return new self($this->definition, CompetitionStatus::Completed, $this->seasonId);
    }

    public function withSeason(SeasonId $seasonId): self
    {
        if ($this->status !== CompetitionStatus::Upcoming) {
            throw new InvalidArgumentException('Only upcoming Competitions can be assigned a Season.');
        }

        return new self($this->definition, $this->status, $seasonId);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id()->value(),
            'name' => $this->name(),
            'nation_id' => $this->nationId()->value(),
            'season_id' => $this->seasonId?->value(),
            'short_name' => $this->shortName(),
            'source_package_id' => $this->definition->sourcePackageId(),
            'source_package_version' => $this->definition->sourcePackageVersion(),
            'source_schema_version' => $this->definition->sourceSchemaVersion(),
            'status' => $this->status->value,
            'type' => $this->type()->value,
            'maximum_substitutions' => $this->maximumSubstitutions(),
        ];
    }
}
