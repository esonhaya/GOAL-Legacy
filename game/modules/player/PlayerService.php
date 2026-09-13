<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Nation\NationService;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\PlayerException;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Domain\SeasonId;

final class PlayerService
{
    public function __construct(
        private readonly NationService $nationService,
        private readonly ClubService $clubService,
    ) {
    }

    public function create(PlayerCreationRequest $request): Player
    {
        return (new PlayerCreationService($this->nationService->loadSelected()))->create($request);
    }

    public function repository(DatabaseInterface $database): PlayerRepository
    {
        return new PlayerRepository($database);
    }

    public function careerRepository(DatabaseInterface $database): CareerPlayerRepository
    {
        return new CareerPlayerRepository($database);
    }

    /** @return list<Player> */
    public function byClub(DatabaseInterface $database, string $clubId, ?SeasonId $seasonId = null): array
    {
        $players = [];
        $repository = $this->repository($database);
        foreach ($this->clubService->squadRepository($database)->byClub($clubId, $seasonId) as $membership) {
            $players[] = $repository->get($membership->playerId());
        }

        return $players;
    }

    public function initializeCareer(
        DatabaseInterface $database,
        Player $player,
        CareerPlayerReference $career,
        ClubSquadMembership $squadMembership,
    ): void {
        if ($career->playerId()->value() !== $player->id()->value() || $squadMembership->playerId()->value() !== $player->id()->value()) {
            throw new PlayerException('Career and squad references must point to the initialized Player.');
        }
        $playerRepository = $this->repository($database);
        $squadRepository = $this->clubService->squadRepository($database);
        $careerRepository = $this->careerRepository($database);
        $database->transaction(function () use ($player, $career, $squadMembership, $playerRepository, $squadRepository, $careerRepository): void {
            $playerRepository->saveInTransaction($player);
            $squadRepository->save($squadMembership);
            $careerRepository->save($career);
        });
    }

}
