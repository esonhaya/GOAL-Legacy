<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Transfer;

use Goal\Legacy\Core\Events\EventDispatcherInterface;
use Goal\Legacy\Core\Events\GenericEvent;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Competition\CompetitionService;
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Contract\ContractService;
use Goal\Legacy\Modules\Contract\Domain\Contract;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Contract\Domain\ContractStatus;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\PlayerPopulationService;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Transfer\Domain\Transfer;
use Goal\Legacy\Modules\Transfer\Domain\TransferEventNames;
use Goal\Legacy\Modules\Transfer\Domain\TransferExecutionTerms;
use Goal\Legacy\Modules\Transfer\Domain\TransferException;
use Goal\Legacy\Modules\Transfer\Persistence\TransferRepository;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\Season;

final class TransferService
{
    public function __construct(private readonly ContractService $contractService, private readonly ClubService $clubService, private readonly CompetitionService $competitionService, private readonly EventDispatcherInterface $events) {}
    public function repository(DatabaseInterface $database): TransferRepository { return new TransferRepository($database); }
    public function save(DatabaseInterface $database, Transfer $transfer): void { $this->repository($database)->save($transfer); }

    /**
     * Sign an active out-of-contract Player through the canonical Contract,
     * squad, and registration owners.  The Season may still be upcoming;
     * activation will register the Player once its Competition membership is
     * active.
     */
    public function signFreeAgent(DatabaseInterface $database, Player $player, ClubId $clubId, Season $season, SimulationDate $asOfDate, SquadRole $role, ContractId $contractId, int $wage): Contract
    {
        if ($player->isRetired()) {
            throw new TransferException('Retired Players cannot sign a Contract.');
        }
        $contracts = $this->contractService->repository($database);
        if ($contracts->exists($contractId)) {
            $existing = $contracts->get($contractId);
            if ($existing->playerId()->value() !== $player->id()->value() || $existing->clubId()->value() !== $clubId->value()) {
                throw new TransferException('Free-agent Contract ID belongs to another Player or Club.');
            }
            return $existing;
        }
        if ($contracts->activeForPlayer($player->id()) !== null) {
            throw new TransferException('Player already has an active Contract.');
        }
        if (!$this->clubService->repository($database)->exists($clubId)) {
            throw new TransferException('Free-agent destination Club does not exist.');
        }
        $squads = $this->clubService->squadRepository($database);
        $existingSquad = $squads->byClub($clubId, $season->id());
        if (count($existingSquad) >= PlayerPopulationService::TARGET_SQUAD_SIZE && !$squads->exists(new ClubSquadMembership($clubId, $player->id(), $season->id(), $role))) {
            throw new TransferException('Free-agent destination Club has no safe squad capacity.');
        }
        $contract = Contract::forDate($contractId, $player->id(), $clubId, $season->startDate(), $season->endDate()->addDays(365), $wage, $asOfDate);
        $squad = new ClubSquadMembership($clubId, $player->id(), $season->id(), $role);
        $registrations = $this->competitionService->registrationRepository($database);
        $clubMemberships = $this->clubService->membershipRepository($database)->byClub($clubId);
        $database->transaction(function () use ($contracts, $contract, $squads, $squad, $registrations, $clubMemberships, $season, $clubId, $player): void {
            $contracts->saveInTransaction($contract);
            if (!$squads->exists($squad)) {
                $squads->save($squad);
            }
            foreach ($clubMemberships as $membership) {
                if ($membership->seasonId()->value() !== $season->id()->value()) {
                    continue;
                }
                $registration = new PlayerRegistration($season->id(), $membership->competitionId(), $clubId, $player->id());
                if (!$registrations->exists($registration)) {
                    $registrations->registerInTransaction($registration);
                }
            }
        });

        return $contract;
    }

    public function careerMovement(): CareerMovementService
    {
        return new CareerMovementService($this, $this->contractService, $this->clubService, $this->competitionService, $this->events);
    }

    public function execute(DatabaseInterface $database, Transfer $transfer, TransferExecutionTerms $terms): Transfer
    {
        if ($transfer->status()->value !== 'agreed') { throw new TransferException('Only agreed Transfers can be executed.'); }
        if ((new PlayerRepository($database))->get($transfer->playerId())->isRetired()) { throw new TransferException('Retired Players cannot be transferred.'); }
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
            $destinationSquad = new ClubSquadMembership($transfer->destinationClubId(), $transfer->playerId(), $transfer->seasonId(), $terms->destinationRole);
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
