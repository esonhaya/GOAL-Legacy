<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Contract;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Contract\Domain\Contract;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractEventNames;
use Goal\Legacy\Modules\Contract\Domain\ContractStatus;
use Goal\Legacy\Modules\Contract\Persistence\ContractRepository;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

final class ContractService
{
    public function repository(DatabaseInterface $database): ContractRepository { return new ContractRepository($database); }

    public function create(ContractCreationRequest $request): Contract
    {
        return Contract::forDate($request->id, $request->playerId, $request->clubId, $request->startDate, $request->endDate, $request->wage, $request->asOfDate);
    }

    public function save(DatabaseInterface $database, Contract $contract): void { $this->repository($database)->save($contract); }

    public function activeForPlayer(DatabaseInterface $database, string $playerId): ?Contract { return $this->repository($database)->activeForPlayer($playerId); }

    /** @return list<array{before: Contract, after: Contract}> */
    public function evaluateInTransaction(DatabaseInterface $database, SimulationDate $date): array
    {
        return $this->repository($database)->evaluateLifecycleInTransaction($date);
    }
}
