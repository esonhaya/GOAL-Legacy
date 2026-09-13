<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Competition;

use Goal\Legacy\Core\Content\ContentPackageCatalog;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Competition\Content\CompetitionContentLoader;
use Goal\Legacy\Modules\Competition\Domain\CompetitionDefinition;
use Goal\Legacy\Modules\Competition\Persistence\CompetitionMaterializer;
use Goal\Legacy\Modules\Competition\Persistence\CompetitionRepository;
use Goal\Legacy\Modules\Competition\Persistence\PlayerRegistrationRepository;
use Goal\Legacy\Modules\Nation\NationService;
use Goal\Legacy\Modules\World\Domain\SeasonId;

final class CompetitionService
{
    public function __construct(
        private readonly ContentPackageCatalog $contentPackages,
        private readonly NationService $nationService,
        private readonly CompetitionContentLoader $contentLoader = new CompetitionContentLoader(),
    ) {
    }

    /** @return list<CompetitionDefinition> */
    public function loadSelected(): array
    {
        return $this->contentLoader->loadSelected($this->contentPackages, $this->nationService->loadSelected());
    }

    public function repository(DatabaseInterface $database): CompetitionRepository
    {
        return new CompetitionRepository($database);
    }

    public function registrationRepository(DatabaseInterface $database): PlayerRegistrationRepository
    {
        return new PlayerRegistrationRepository($database);
    }

    public function materialize(DatabaseInterface $database, SeasonId $seasonId): int
    {
        return (new CompetitionMaterializer($database))->materialize($this->loadSelected(), $seasonId);
    }

    /** @param list<CompetitionDefinition> $definitions */
    public function materializeInTransaction(DatabaseInterface $database, array $definitions, SeasonId $seasonId): int
    {
        return (new CompetitionMaterializer($database))->materializeInTransaction($definitions, $seasonId);
    }
}
