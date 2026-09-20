<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\TrainingFocus;
use Goal\Legacy\Modules\Player\Domain\TrainingIntensity;
use Goal\Legacy\Modules\Player\Domain\WeakFootTier;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\WeakFootDevelopmentRepository;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

/** Controlled-only weak-foot progression layered onto the canonical training cadence. */
final class WeakFootDevelopmentService
{
    /** @return array<string,mixed> */
    public function context(DatabaseInterface $database, PlayerId|string $playerId): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        if (!(new CareerPlayerRepository($database))->byPlayer($id)) {
            return ['enabled' => false];
        }
        $player = (new PlayerRepository($database))->get($id);
        // Reads must not initialize a new persistence table on Profile/Home.
        $repository = new WeakFootDevelopmentRepository($database, false);
        $state = $repository->state($id, $player->weakFoot()->progressFloor());
        $progress = max($state->progress(), $player->weakFoot()->progressFloor());

        return [
            'enabled' => true,
            'tier' => WeakFootTier::fromProgress($progress)->value,
            'label' => WeakFootTier::fromProgress($progress)->label(),
            'progress' => $progress,
            'cap' => 100,
            'updated_date' => $state->updatedDate()?->toIsoString(),
        ];
    }

    /** Must be called inside the canonical training transaction. */
    public function applyTrainingInTransaction(DatabaseInterface $database, PlayerId $playerId, SimulationDate $date, int $weeks, TrainingIntensity $intensity, TrainingFocus $focus): ?array
    {
        if ($focus !== TrainingFocus::WeakFoot || !(new CareerPlayerRepository($database))->byPlayer($playerId)) {
            return null;
        }
        $players = new PlayerRepository($database);
        $player = $players->get($playerId);
        if ($player->isRetired()) { return null; }
        $repository = new WeakFootDevelopmentRepository($database, true);
        $state = $repository->state($playerId, $player->weakFoot()->progressFloor());
        $points = match ($intensity) {
            TrainingIntensity::Light => 1,
            TrainingIntensity::Normal => 2,
            TrainingIntensity::Intense => 3,
        };
        $nextProgress = min(100, max($state->progress(), $player->weakFoot()->progressFloor()) + max(1, $weeks) * $points);
        $tier = WeakFootTier::fromProgress($nextProgress);
        if ($tier !== $player->weakFoot()) {
            $players->saveInTransaction($player->withWeakFoot($tier));
        }
        $repository->saveStateInTransaction($state->withProgress($nextProgress, $date));

        return $this->context($database, $playerId);
    }
}
