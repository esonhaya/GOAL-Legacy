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
use Goal\Legacy\Modules\Player\Finance\PlayerFinanceService;
use Goal\Legacy\Modules\World\Domain\SeasonId;

final class PlayerService
{
    public function __construct(
        private readonly NationService $nationService,
        private readonly ClubService $clubService,
        private readonly ?PlayerDevelopmentService $developmentService = null,
        private readonly ?PlayerAvailabilityService $availabilityService = null,
        private readonly ?PlayerPopulationService $populationService = null,
        private readonly ?PlayerFinanceService $financeService = null,
        private readonly ?FootballSocialService $socialService = null,
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

    public function developmentService(): PlayerDevelopmentService
    {
        return $this->developmentService ?? new PlayerDevelopmentService();
    }

    public function trainingService(): TrainingService
    {
        return new TrainingService($this->developmentService(), $this->availabilityService);
    }

    public function careerExperienceService(): CareerExperienceService
    {
        return new CareerExperienceService($this->developmentService(), $this->trainingService(), $this->clubService, $this->financeService(), $this->socialService());
    }

    public function populationService(): PlayerPopulationService
    {
        if ($this->populationService === null) {
            throw new PlayerException('Player population is not configured for this service composition.');
        }

        return $this->populationService;
    }

    public function financeService(): PlayerFinanceService
    {
        return $this->financeService ?? new PlayerFinanceService();
    }

    public function socialService(): FootballSocialService
    {
        return $this->socialService ?? new FootballSocialService($this->clubService);
    }

    /** @return list<Player> */
    public function byClub(DatabaseInterface $database, string $clubId, ?SeasonId $seasonId = null): array
    {
        $repository = $this->repository($database);
        $memberships = $this->clubService->squadRepository($database)->byClub($clubId, $seasonId);

        return $repository->byIds(array_map(static fn (ClubSquadMembership $membership): string => $membership->playerId()->value(), $memberships));
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
        $this->socialService()->initializeCareer($database, $player, $squadMembership->clubId()->value(), $squadMembership->role()->value, $career->startDate());
    }

}
