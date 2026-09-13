<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Transfer\Domain;

use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

final readonly class TransferExecutionTerms
{
    public function __construct(public ContractId $destinationContractId, public SimulationDate $contractEndDate, public int $wage, public SquadRole $destinationRole = SquadRole::Prospect)
    {
        if ($this->wage < 0) { throw new \InvalidArgumentException('Destination Contract wage cannot be negative.'); }
    }
}
