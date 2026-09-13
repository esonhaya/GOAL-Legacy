<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Contract\ContractService;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerCareerState;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

/** Season-boundary age, decline, and retirement policy for ordinary Players. */
final class PlayerLifecycleService
{
    public function __construct(private readonly PlayerDevelopmentService $development, private readonly ContractService $contracts)
    {
    }

    /** @return array{processed:int,retired:int,declined:int} */
    public function processSeasonBoundaryInTransaction(DatabaseInterface $database, Season $nextSeason): array
    {
        $players = new PlayerRepository($database);
        $retired = 0;
        $declined = 0;
        $processed = 0;
        $contractRepository = $this->contracts->repository($database);
        foreach ($players->all() as $player) {
            if ($player->isRetired()) {
                continue;
            }
            ++$processed;
            $result = $this->development->applySeasonLifecycleInTransaction($database, $player->id(), $nextSeason->startDate(), $nextSeason->id()->value());
            if ($result->attributeDeltas() !== []) {
                ++$declined;
            }
            $current = $players->get($player->id());
            if (!$this->shouldRetire($database, $current, $nextSeason->startDate())) {
                continue;
            }
            $players->saveInTransaction($current->withCareerState(PlayerCareerState::Retired));
            $active = $contractRepository->activeForPlayer($current->id());
            if ($active !== null) {
                $contractRepository->saveInTransaction($active->terminate());
            }
            ++$retired;
        }

        return ['processed' => $processed, 'retired' => $retired, 'declined' => $declined];
    }

    public function shouldRetire(DatabaseInterface $database, Player $player, SimulationDate $date): bool
    {
        if ($player->isRetired()) {
            return false;
        }
        $age = $player->ageAt($date);
        if ($age < 34) {
            return false;
        }
        if ($age >= 40) {
            return true;
        }
        $threshold = match (true) {
            $age === 34 => 8,
            $age === 35 => 22,
            $age === 36 => 42,
            $age === 37 => 64,
            default => 84,
        };
        if ($player->overallRating() < 58) {
            $threshold += 14;
        }
        $contract = $this->contracts->repository($database)->activeForPlayer($player->id());
        if ($contract === null) {
            $threshold += 8;
        }
        $positionLongevity = $player->primaryPosition()->value === 'GK' ? -8 : 0;
        $roll = hexdec(substr(hash('sha256', 'retirement|' . $player->id()->value() . '|' . $date->toIsoString()), 0, 8)) % 100;

        return $roll < max(0, $threshold + $positionLongevity);
    }
}
