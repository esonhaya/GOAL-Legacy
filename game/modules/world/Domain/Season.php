<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\World\Domain;

use InvalidArgumentException;

final readonly class Season
{
    public function __construct(
        private SeasonId $id,
        private string $label,
        private SimulationDate $startDate,
        private SimulationDate $endDate,
        private SeasonStatus $status = SeasonStatus::Upcoming,
    ) {
        if (trim($label) === '') {
            throw new InvalidArgumentException('Season labels cannot be empty.');
        }
        if ($endDate->isBefore($startDate)) {
            throw new InvalidArgumentException('Season end dates cannot precede start dates.');
        }
    }

    public function id(): SeasonId { return $this->id; }

    public function label(): string { return $this->label; }

    public function startDate(): SimulationDate { return $this->startDate; }

    public function endDate(): SimulationDate { return $this->endDate; }

    public function status(): SeasonStatus { return $this->status; }

    public function activate(): self
    {
        if ($this->status !== SeasonStatus::Upcoming) {
            throw new InvalidArgumentException('Only upcoming Seasons can become active.');
        }

        return $this->withStatus(SeasonStatus::Active);
    }

    public function complete(): self
    {
        if ($this->status !== SeasonStatus::Active) {
            throw new InvalidArgumentException('Only active Seasons can become completed.');
        }

        return $this->withStatus(SeasonStatus::Completed);
    }

    public function withStatus(SeasonStatus $status): self
    {
        return new self($this->id, $this->label, $this->startDate, $this->endDate, $status);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'end_date' => $this->endDate->toIsoString(),
            'id' => $this->id->value(),
            'label' => $this->label,
            'start_date' => $this->startDate->toIsoString(),
            'status' => $this->status->value,
        ];
    }
}
