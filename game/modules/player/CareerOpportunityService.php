<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Events\EventDispatcherInterface;
use Goal\Legacy\Core\Events\GenericEvent;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunity;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunityStatus;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Persistence\CareerOpportunityRepository;

final class CareerOpportunityService
{
    public function __construct(private readonly ?EventDispatcherInterface $events = null)
    {
    }

    /** @return list<CareerOpportunity> */
    public function openForPlayer(DatabaseInterface $database, PlayerId|string $playerId): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);

        return (new CareerOpportunityRepository($database))->openForPlayer($id);
    }

    public function createInTransaction(DatabaseInterface $database, CareerOpportunity $opportunity): void
    {
        $repository = new CareerOpportunityRepository($database);
        if ($repository->bySourceKey($opportunity->sourceKey()) !== null) { return; }
        $repository->saveInTransaction($opportunity);
    }

    public function accept(DatabaseInterface $database, string $opportunityId): CareerOpportunity
    {
        return $this->changeStatus($database, $opportunityId, CareerOpportunityStatus::Accepted);
    }

    public function decline(DatabaseInterface $database, string $opportunityId): CareerOpportunity
    {
        return $this->changeStatus($database, $opportunityId, CareerOpportunityStatus::Declined);
    }

    private function changeStatus(DatabaseInterface $database, string $opportunityId, CareerOpportunityStatus $status): CareerOpportunity
    {
        $repository = new CareerOpportunityRepository($database); $opportunity = $repository->get($opportunityId);
        if ($opportunity === null) { throw new \InvalidArgumentException(sprintf('Career opportunity "%s" was not found.', $opportunityId)); }
        if ($opportunity->status() !== CareerOpportunityStatus::Open) { throw new \InvalidArgumentException('Only open career opportunities can be resolved.'); }
        $updated = $opportunity->withStatus($status);
        $database->transaction(function () use ($repository, $updated, $status): void {
            $repository->updateStatusInTransaction($updated, $status);
        });
        $this->events?->dispatch(new GenericEvent('career.opportunity_resolved', $updated->toArray()));

        return $updated;
    }
}
