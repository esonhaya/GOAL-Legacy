<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Club\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Domain\ClubContentDefinition;
use Goal\Legacy\Modules\Club\Domain\ClubException;
use Goal\Legacy\Modules\Competition\Persistence\CompetitionRepository;
use Goal\Legacy\Modules\Nation\Persistence\NationRepository;
use Goal\Legacy\Modules\World\Persistence\SeasonRepository;

final class ClubMaterializer
{
    public function __construct(private readonly DatabaseInterface $database)
    {
    }

    /** @param list<ClubContentDefinition> $definitions */
    public function materialize(array $definitions): int
    {
        return $this->database->transaction(fn (): int => $this->materializeInTransaction($definitions));
    }

    /** @param list<ClubContentDefinition> $definitions */
    public function materializeInTransaction(array $definitions): int
    {
        $ordered = [];
        foreach ($definitions as $definition) {
            $id = $definition->club()->id()->value();
            if (isset($ordered[$id])) {
                throw new ClubException(sprintf('Duplicate Club ID "%s" cannot be materialized.', $id));
            }
            $ordered[$id] = $definition;
        }
        ksort($ordered, SORT_STRING);

        $nationRepository = new NationRepository($this->database);
        $competitionRepository = new CompetitionRepository($this->database);
        $seasonRepository = new SeasonRepository($this->database);
        $clubRepository = new ClubRepository($this->database);
        $membershipRepository = new ClubMembershipRepository($this->database);
        foreach ($ordered as $definition) {
            if (!$nationRepository->exists($definition->club()->nationId())) {
                throw new ClubException(sprintf('Club "%s" references Nation "%s" that is not materialized.', $definition->club()->id()->value(), $definition->club()->nationId()->value()));
            }
            $clubRepository->save($definition->club());
            foreach ($definition->memberships() as $membership) {
                if (!$competitionRepository->exists($membership->competitionId())) {
                    throw new ClubException(sprintf('Club "%s" references Competition "%s" that is not materialized.', $definition->club()->id()->value(), $membership->competitionId()->value()));
                }
                if (!$seasonRepository->exists($membership->seasonId())) {
                    throw new ClubException(sprintf('Club "%s" references Season "%s" that is not materialized.', $definition->club()->id()->value(), $membership->seasonId()->value()));
                }
                if (!$membershipRepository->exists($membership)) {
                    $membershipRepository->save($membership);
                }
            }
        }

        return count($ordered);
    }
}
