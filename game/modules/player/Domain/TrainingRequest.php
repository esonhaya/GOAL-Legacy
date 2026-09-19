<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use Goal\Legacy\Modules\World\Domain\SimulationDate;
use InvalidArgumentException;

final readonly class TrainingRequest
{
    public function __construct(
        PlayerId|string $playerId,
        private string $blockId,
        TrainingFocus|string $focus,
        private SimulationDate $startDate,
        private SimulationDate $endDate,
        TrainingIntensity|string $intensity = TrainingIntensity::Normal,
    ) {
        $this->playerId = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $this->focus = $focus instanceof TrainingFocus ? $focus : TrainingFocus::fromInput($focus);
        $this->intensity = $intensity instanceof TrainingIntensity ? $intensity : TrainingIntensity::fromInput($intensity);
        if (trim($this->blockId) === '') {
            throw new InvalidArgumentException('Training block IDs cannot be empty.');
        }
        if ($this->endDate->isBefore($this->startDate)) {
            throw new InvalidArgumentException('Training blocks cannot run backwards.');
        }
    }

    private PlayerId $playerId;
    private TrainingFocus $focus;
    private TrainingIntensity $intensity;

    public function playerId(): PlayerId { return $this->playerId; }
    public function blockId(): string { return $this->blockId; }
    public function focus(): TrainingFocus { return $this->focus; }
    public function intensity(): TrainingIntensity { return $this->intensity; }
    public function startDate(): SimulationDate { return $this->startDate; }
    public function endDate(): SimulationDate { return $this->endDate; }
}
