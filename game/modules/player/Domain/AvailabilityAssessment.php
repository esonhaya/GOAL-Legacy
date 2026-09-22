<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use Goal\Legacy\Modules\World\Domain\SimulationDate;

final readonly class AvailabilityAssessment
{
    public function __construct(
        private PlayerId $playerId,
        private AvailabilityStatus $status,
        private int $fatigue,
        private SimulationDate $date,
        private ?Injury $injury = null,
        /** @var array<string, int|bool|string|null> */
        private array $workload = [],
    ) {
    }

    public function playerId(): PlayerId { return $this->playerId; }
    public function status(): AvailabilityStatus { return $this->status; }
    public function fatigue(): int { return $this->fatigue; }
    public function date(): SimulationDate { return $this->date; }
    public function injury(): ?Injury { return $this->injury; }
    /** @return array<string, int|bool|string|null> */
    public function workload(): array
    {
        return array_merge([
            'label' => $this->workloadLabel(),
            'recent_minutes' => 0,
            'recent_appearances' => 0,
            'recent_starts' => 0,
            'minutes_last_7_days' => 0,
            'appearances_last_7_days' => 0,
            'last_match_date' => null,
            'days_since_last_match' => null,
            'short_recovery_matches' => 0,
            'congested' => false,
        ], $this->workload, ['label' => $this->workloadLabel()]);
    }
    public function isAvailable(): bool { return $this->status === AvailabilityStatus::Available; }
    public function isLimited(): bool { return $this->status === AvailabilityStatus::Limited; }
    public function isUnavailable(): bool { return $this->status === AvailabilityStatus::Unavailable; }

    public function readinessLabel(): string
    {
        if ($this->injury !== null) {
            return 'injured';
        }
        return match (true) {
            $this->fatigue >= 85 => 'fatigued',
            $this->fatigue >= 70 => 'tired',
            $this->fatigue >= 50 => 'managed',
            $this->fatigue <= 15 => 'fresh',
            default => 'ready',
        };
    }

    public function readinessDescription(): string
    {
        return match ($this->readinessLabel()) {
            'injured' => 'Unavailable until the recorded recovery boundary.',
            'fatigued' => 'Too much recent workload for normal selection.',
            'tired' => 'Recent workload may affect selection and recovery.',
            'managed' => 'Available, but the next block should be managed.',
            'fresh' => 'No meaningful recent workload is being carried.',
            default => 'Available for the next football block.',
        };
    }

    public function workloadLabel(): string
    {
        return match (true) {
            $this->fatigue >= 85 => 'heavy',
            $this->fatigue >= 50 => 'managed',
            $this->fatigue <= 15 => 'fresh',
            default => 'normal',
        };
    }

    /** @return array<string, mixed> */
    public function readiness(): array
    {
        return [
            'description' => $this->readinessDescription(),
            'fatigue' => $this->fatigue,
            'injury' => $this->injury?->toArray(),
            'label' => $this->readinessLabel(),
            'status' => $this->status->value,
            'workload' => $this->workload(),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'active_injury' => $this->injury?->toArray(),
            'date' => $this->date->toIsoString(),
            'fatigue' => $this->fatigue,
            'player_id' => $this->playerId->value(),
            'status' => $this->status->value,
            'readiness' => $this->readiness(),
        ];
    }
}
