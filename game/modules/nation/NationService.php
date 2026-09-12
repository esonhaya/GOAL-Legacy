<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Nation;

use Goal\Legacy\Core\Content\ContentPackageCatalog;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Nation\Content\NationContentLoader;
use Goal\Legacy\Modules\Nation\Domain\Nation;
use Goal\Legacy\Modules\Nation\Persistence\NationMaterializer;
use Goal\Legacy\Modules\Nation\Persistence\NationRepository;

final class NationService
{
    public function __construct(
        private readonly ContentPackageCatalog $contentPackages,
        private readonly NationContentLoader $contentLoader = new NationContentLoader(),
    ) {
    }

    /** @return list<Nation> */
    public function loadSelected(): array
    {
        return $this->contentLoader->loadSelected($this->contentPackages);
    }

    public function repository(DatabaseInterface $database): NationRepository
    {
        return new NationRepository($database);
    }

    public function materialize(DatabaseInterface $database): int
    {
        return (new NationMaterializer($database))->materialize($this->loadSelected());
    }

    /** @param list<Nation> $nations */
    public function materializeInTransaction(DatabaseInterface $database, array $nations): int
    {
        return (new NationMaterializer($database))->materializeInTransaction($nations);
    }
}
