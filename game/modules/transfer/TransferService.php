<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Transfer;

use Goal\Legacy\Core\Events\EventDispatcherInterface;
use Goal\Legacy\Core\Events\GenericEvent;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Competition\CompetitionService;
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Contract\ContractService;
use Goal\Legacy\Modules\Contract\Domain\Contract;
use Goal\Legacy\Modules\Contract\Domain\ContractStatus;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Transfer\Domain\Transfer;
use Goal\Legacy\Modules\Transfer\Domain\TransferEventNames;
use Goal\Legacy\Modules\Transfer\Domain\TransferExecutionTerms;
use Goal\Legacy\Modules\Transfer\Domain\TransferException;
use Goal\Legacy\Modules\Transfer\Persistence\TransferRepository;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

final class TransferService
{
    public function __construct(private readonly ContractService $contractService, private readonly ClubService $clubService, private readonly CompetitionService $competitionService, private readonly EventDispatcherInterface $events) {}
    public function repository(DatabaseInterface $database): TransferRepository { return new TransferRepository($database); }
    public function save(DatabaseInterface $database, Transfer $transfer): void { $this->repository($database)->save($transfer); }

    public function execute(DatabaseInterface $database, Transfer $transfer, TransferExecutionTerms $terms): Transfer
    {
        if ($transfer->status()->value !== 'agreed') { throw new TransferException('Only agreed Transfers can be executed.'); }
        if ($terms->contractEndDate->isBefore($transfer->effectiveDate())) { throw new TransferException('Destination Contract must end on or after the transfer effective date.'); }
        $contractRepository = $this->contractService->repository($database);
        $squadRepository = $this->clubService->squadRepository($database);
        $registrationRepository = $this->competitionService->registrationRepository($database);
        $clubMembershipRepository = $this->clubService->membershipRepository($database);
        $transferRepository = $this->repository($database);
        if ($transferRepository->exists($transfer->id())) {
            $stored = $transferRepository->get($transfer->id());
            if ($stored->status()->value === 'completed') { throw new TransferException('Transfer has already been completed.'); }
            if ($stored->status()->value !== 'agreed') { throw new TransferException('Persisted Transfer is not agreed and cannot be executed.'); }
        }
        $sourceSquad = new ClubSquadMembership($transfer->sourceClubId(), $transfer->playerId(), $transfer->seasonId());
        $sourceContract = $contractRepository->activeForPlayer($transfer->playerId());
        if ($sourceContract === null || $sourceContract->clubId()->value() !== $transfer->sourceClubId()->value()) { throw new TransferException('Player does not have an active Contract with the source Club.'); }
        if (!$squadRepository->exists($sourceSquad)) { throw new TransferException('Player does not belong to the source Club squad for the transfer Season.'); }
        $completed = $database->transaction(function () use ($database, $transfer, $terms, $contractRepository, $squadRepository, $registrationRepository, $clubMembershipRepository, $transferRepository, $sourceSquad, $sourceContract): Transfer {
            $sourceContractAfter = $sourceContract->terminate();
            $contractRepository->saveInTransaction($sourceContractAfter);
            $registrationRepository->unregisterByPlayerClubSeason($transfer->playerId(), $transfer->sourceClubId(), $transfer->seasonId());
            $squadRepository->remove($sourceSquad);
            $destinationContract = Contract::forDate($terms->destinationContractId, $transfer->playerId(), $transfer->destinationClubId(), $transfer->effectiveDate(), $terms->contractEndDate, $terms->wage, $transfer->effectiveDate());
            if ($destinationContract->status() !== ContractStatus::Active) { throw new TransferException('Destination Contract must be active at the transfer effective date.'); }
            $contractRepository->saveInTransaction($destinationContract);
            $destinationSquad = new ClubSquadMembership($transfer->destinationClubId(), $transfer->playerId(), $transfer->seasonId());
            $squadRepository->save($destinationSquad);
            foreach ($clubMembershipRepository->byClub($transfer->destinationClubId()) as $membership) {
                if ($membership->seasonId()->value() !== $transfer->seasonId()->value()) { continue; }
                $registrationRepository->registerInTransaction(new PlayerRegistration($membership->seasonId(), $membership->competitionId(), $membership->clubId(), $transfer->playerId()));
            }
            $result = $transfer->complete($sourceContract->id(), $destinationContract->id());
            $transferRepository->saveInTransaction($result);
            return $result;
        });
        $this->events->dispatch(new GenericEvent(TransferEventNames::COMPLETED, ['transfer_id' => $completed->id()->value(), 'player_id' => $completed->playerId()->value(), 'source_club_id' => $completed->sourceClubId()->value(), 'destination_club_id' => $completed->destinationClubId()->value(), 'fee' => $completed->fee(), 'effective_date' => $completed->effectiveDate()->toIsoString()]));
        return $completed;
    }
}
