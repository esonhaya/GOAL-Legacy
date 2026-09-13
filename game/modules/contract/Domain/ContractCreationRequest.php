<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Contract\Domain;

use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

final readonly class ContractCreationRequest
{
    public function __construct(
        public ContractId $id,
        public PlayerId $playerId,
        public ClubId $clubId,
        public SimulationDate $startDate,
        public SimulationDate $endDate,
        public int $wage,
        public SimulationDate $asOfDate,
    ) {
    }
}
