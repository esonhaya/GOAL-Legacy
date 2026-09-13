<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Club\Domain;

use InvalidArgumentException;

final readonly class ClubContentDefinition
{
    /** @param list<ClubCompetitionMembership> $memberships */
    public function __construct(
        private Club $club,
        private array $memberships,
    ) {
        $seen = [];
        foreach ($memberships as $membership) {
            if ($membership->clubId()->value() !== $club->id()->value()) {
                throw new InvalidArgumentException('Club membership must reference its containing Club.');
            }
            if (isset($seen[$membership->key()])) {
                throw new InvalidArgumentException(sprintf('Duplicate Club membership "%s".', $membership->key()));
            }
            $seen[$membership->key()] = true;
        }
    }

    public function club(): Club { return $this->club; }

    /** @return list<ClubCompetitionMembership> */
    public function memberships(): array { return $this->memberships; }
}
