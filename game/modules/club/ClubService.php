<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Club;

use Goal\Legacy\Core\Content\ContentPackageCatalog;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Content\ClubContentLoader;
use Goal\Legacy\Modules\Club\Domain\Club;
use Goal\Legacy\Modules\Club\Domain\ClubContentDefinition;
use Goal\Legacy\Modules\Club\Domain\ClubCompetitionMembership;
use Goal\Legacy\Modules\Club\Persistence\ClubMaterializer;
use Goal\Legacy\Modules\Club\Persistence\ClubMembershipRepository;
use Goal\Legacy\Modules\Club\Persistence\ClubRepository;
use Goal\Legacy\Modules\Club\Persistence\ClubSquadRepository;
use Goal\Legacy\Modules\Competition\CompetitionService;
use Goal\Legacy\Modules\Nation\NationService;
use Goal\Legacy\Modules\World\Domain\SeasonId;

final class ClubService
{
    public function __construct(
        private readonly ContentPackageCatalog $contentPackages,
        private readonly NationService $nationService,
        private readonly CompetitionService $competitionService,
        private readonly ClubContentLoader $contentLoader = new ClubContentLoader(),
    ) {
    }

    /** @return list<ClubContentDefinition> */
    public function loadSelected(): array
    {
        return $this->contentLoader->loadSelected(
            $this->contentPackages,
            $this->nationService->loadSelected(),
            $this->competitionService->loadSelected(),
        );
    }

    public function repository(DatabaseInterface $database): ClubRepository
    {
        return new ClubRepository($database);
    }

    public function membershipRepository(DatabaseInterface $database): ClubMembershipRepository
    {
        return new ClubMembershipRepository($database);
    }

    public function squadRepository(DatabaseInterface $database): ClubSquadRepository
    {
        return new ClubSquadRepository($database);
    }

    /** @param list<ClubContentDefinition> $definitions */
    public function materialize(DatabaseInterface $database, array $definitions): int
    {
        return (new ClubMaterializer($database))->materialize($definitions);
    }

    /** @param list<ClubContentDefinition> $definitions */
    public function materializeInTransaction(DatabaseInterface $database, array $definitions): int
    {
        return (new ClubMaterializer($database))->materializeInTransaction($definitions);
    }

    /** @return list<Club> */
    public function byCompetition(DatabaseInterface $database, string $competitionId, ?SeasonId $seasonId = null): array
    {
        $memberships = $this->membershipRepository($database)->byCompetition($competitionId, $seasonId);
        $repository = $this->repository($database);

        return array_map(static fn (ClubCompetitionMembership $membership): Club => $repository->get($membership->clubId()), $memberships);
    }
}
