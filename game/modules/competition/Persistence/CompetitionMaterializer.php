<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Competition\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Competition\Domain\Competition;
use Goal\Legacy\Modules\Competition\Domain\CompetitionDefinition;
use Goal\Legacy\Modules\Competition\Domain\CompetitionException;
use Goal\Legacy\Modules\Nation\Persistence\NationRepository;
use Goal\Legacy\Modules\World\Domain\SeasonId;

final class CompetitionMaterializer
{
    public function __construct(private readonly DatabaseInterface $database)
    {
    }

    /** @param list<CompetitionDefinition> $definitions */
    public function materialize(array $definitions, SeasonId $seasonId): int
    {
        return $this->database->transaction(fn (): int => $this->materializeInTransaction($definitions, $seasonId));
    }

    /** @param list<CompetitionDefinition> $definitions */
    public function materializeInTransaction(array $definitions, SeasonId $seasonId): int
    {
        $ordered = [];
        foreach ($definitions as $definition) {
            $id = $definition->id()->value();
            if (isset($ordered[$id])) {
                throw new CompetitionException(sprintf('Duplicate Competition ID "%s" cannot be materialized.', $id));
            }
            $ordered[$id] = $definition;
        }
        ksort($ordered, SORT_STRING);

        $nationRepository = new NationRepository($this->database);
        $repository = new CompetitionRepository($this->database);
        foreach ($ordered as $definition) {
            if (!$nationRepository->exists($definition->nationId())) {
                throw new CompetitionException(sprintf('Competition "%s" references Nation "%s" that is not materialized.', $definition->id()->value(), $definition->nationId()->value()));
            }
            $repository->save(new Competition($definition, seasonId: $seasonId));
        }

        return count($ordered);
    }
}
