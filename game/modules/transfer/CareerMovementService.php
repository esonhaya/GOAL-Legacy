<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Transfer;

use Goal\Legacy\Core\Events\EventDispatcherInterface;
use Goal\Legacy\Core\Events\GenericEvent;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Club\Domain\Club;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Competition\CompetitionService;
use Goal\Legacy\Modules\Contract\ContractService;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\PlayerFormService;
use Goal\Legacy\Modules\Player\PlayerSeasonPerformanceService;
use Goal\Legacy\Modules\Player\PlayerPopulationService;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunity;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunityStatus;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunityType;
use Goal\Legacy\Modules\Player\Domain\CareerTransferRequestStatus;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerOpportunityRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Transfer\Domain\Transfer;
use Goal\Legacy\Modules\Transfer\Domain\TransferException;
use Goal\Legacy\Modules\Transfer\Domain\TransferExecutionTerms;
use Goal\Legacy\Modules\Transfer\Domain\TransferId;
use Goal\Legacy\Modules\Transfer\Domain\TransferStatus;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Persistence\SeasonRepository;

final class CareerMovementService
{
    private const MAX_OFFERS = 3;

    /** @var array<string, int> */
    private const GROUP_MINIMUMS = [
        'goalkeeper' => 1,
        'defensive' => 4,
        'midfield' => 5,
        'attacking' => 3,
    ];

    public function __construct(
        private readonly TransferService $transferService,
        private readonly ContractService $contractService,
        private readonly ClubService $clubService,
        private readonly CompetitionService $competitionService,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /** @return list<CareerOpportunity> Newly created transfer offers. */
    public function evaluate(DatabaseInterface $database, PlayerId|string $playerId, SeasonId|string $seasonId, SimulationDate $date, ?string $competitionId = null): array
    {
        $playerId = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $seasonId = $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId);
        $player = (new PlayerRepository($database))->get($playerId);
        if ($player->isRetired()) {
            return [];
        }
        $sourceMembership = $this->currentMembership($database, $playerId, $seasonId);
        $sourceContract = $this->contractService->repository($database)->activeForPlayer($playerId);
        if ($sourceMembership === null || $sourceContract === null || $sourceContract->clubId()->value() !== $sourceMembership->clubId()->value()) {
            return [];
        }
        $competitionId ??= $this->competitionForClub($database, $sourceMembership->clubId(), $seasonId);
        if ($competitionId === null) {
            return [];
        }
        $currentClub = $this->clubService->repository($database)->get($sourceMembership->clubId());
        $currentMetrics = $this->playerMetrics($database, $player, $sourceMembership->clubId(), $seasonId);
        $candidates = [];
        foreach ($this->clubService->byCompetition($database, $competitionId, $seasonId) as $targetClub) {
            if ($targetClub->id()->value() === $currentClub->id()->value()) {
                continue;
            }
            $target = $this->targetMetrics($database, $player, $targetClub->id(), $seasonId);
            if ($target['position_rank'] > 8) {
                continue;
            }
            $score = $this->interestScore($player, $currentClub->reputation(), $sourceMembership->role(), $currentMetrics, $target, $targetClub->reputation());
            if (!$this->isJustified($currentMetrics, $target, $targetClub->reputation(), $currentClub->reputation(), $score)) {
                continue;
            }
            $candidates[] = ['club' => $targetClub, 'metrics' => $target, 'score' => $score];
        }
        usort($candidates, static fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: strcmp($left['club']->id()->value(), $right['club']->id()->value()));
        $created = [];
        foreach (array_slice($candidates, 0, self::MAX_OFFERS) as $candidate) {
            $targetClub = $candidate['club'];
            $sourceKey = implode('|', ['transfer-offer', $playerId->value(), $sourceMembership->clubId()->value(), $targetClub->id()->value(), $date->year() . '-' . str_pad((string) $date->month(), 2, '0', STR_PAD_LEFT)]);
            $repository = new CareerOpportunityRepository($database);
            if ($repository->bySourceKey($sourceKey) !== null) {
                continue;
            }
            $context = $this->offerContext($player, $currentClub->reputation(), $sourceMembership->role(), $currentMetrics, $targetClub->reputation(), $candidate['metrics'], $candidate['score'], $date, $sourceKey, $seasonId);
            $offer = new CareerOpportunity(
                'offer-' . substr(hash('sha256', $sourceKey), 0, 24),
                $playerId,
                CareerOpportunityType::TransferInterest,
                $sourceMembership->clubId(),
                $targetClub->id(),
                $date,
                $date->addDays(30),
                CareerOpportunityStatus::Open,
                $context,
                $sourceKey,
            );
            $database->transaction(function () use ($repository, $offer): void { $repository->saveInTransaction($offer); });
            $created[] = $offer;
            $this->events->dispatch(new GenericEvent('career.transfer_offer_created', $offer->toArray()));
        }

        return $created;
    }

    /** Request consideration by the existing bounded pre-season market. */
    public function requestTransfer(DatabaseInterface $database, PlayerId|string $playerId, Season $season, SimulationDate $date): CareerPlayerReference
    {
        $playerId = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $careers = new CareerPlayerRepository($database);
        $reference = $careers->byPlayer($playerId);
        if ($reference === null) {
            throw new TransferException('Only the controlled career Player can request a transfer.');
        }
        $player = (new PlayerRepository($database))->get($playerId);
        if ($player->isRetired()) {
            throw new TransferException('Retired Players cannot request a transfer.');
        }
        $membership = $this->currentMembership($database, $playerId, $season->id());
        $contract = $this->contractService->repository($database)->activeForPlayer($playerId);
        if ($membership === null || $contract === null || $contract->clubId()->value() !== $membership->clubId()->value()) {
            throw new TransferException('A transfer request requires an active Club Contract.');
        }
        if (!$contract->endDate()->isAfter($season->endDate())) {
            throw new TransferException('Contract expiry decisions take priority over transfer requests.');
        }
        if ($reference->hasActiveTransferRequest($season->id())) {
            throw new TransferException('Transfer request is already active for this window.');
        }
        foreach ($this->transferService->repository($database)->byPlayer($playerId) as $transfer) {
            if ($transfer->seasonId()->value() === $season->id()->value() && $transfer->status() === TransferStatus::Completed) {
                throw new TransferException('Recently moved Players cannot request another transfer in the same window.');
            }
        }
        foreach ((new CareerOpportunityRepository($database))->openForPlayer($playerId) as $opportunity) {
            if ($opportunity->type() === CareerOpportunityType::ContractRenewal || ($opportunity->context()['decision_kind'] ?? null) === 'controlled_transfer') {
                throw new TransferException('An existing career decision must be resolved before requesting a transfer.');
            }
        }
        $requested = $reference->withTransferRequest($season->id());
        $database->transaction(function () use ($careers, $requested): void { $careers->save($requested); });
        $this->events->dispatch(new GenericEvent('career.transfer_request_created', ['player_id' => $playerId->value(), 'season_id' => $season->id()->value(), 'date' => $date->toIsoString()]));

        return $requested;
    }

    /** Withdraw an unresolved request without changing Contract or Club state. */
    public function withdrawTransferRequest(DatabaseInterface $database, PlayerId|string $playerId, SimulationDate $date): CareerPlayerReference
    {
        $playerId = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $careers = new CareerPlayerRepository($database);
        $reference = $careers->byPlayer($playerId);
        if ($reference === null) {
            throw new TransferException('Only the controlled career Player can withdraw a transfer request.');
        }
        if ($reference->transferRequestStatus() === CareerTransferRequestStatus::None) {
            return $reference;
        }
        $withdrawn = $reference->withoutTransferRequest();
        $database->transaction(function () use ($careers, $withdrawn): void { $careers->save($withdrawn); });
        $this->events->dispatch(new GenericEvent('career.transfer_request_withdrawn', ['player_id' => $playerId->value(), 'date' => $date->toIsoString()]));

        return $withdrawn;
    }

    /** Expire requests after their Season-scoped market window. */
    public function expireStaleTransferRequests(DatabaseInterface $database, Season $season): int
    {
        $careers = new CareerPlayerRepository($database);
        $expired = 0;
        foreach ($careers->playerIds() as $playerId) {
            $reference = $careers->byPlayer($playerId);
            if ($reference === null || $reference->transferRequestStatus() !== CareerTransferRequestStatus::Requested || $reference->transferRequestSeasonId()?->value() === $season->id()->value()) {
                continue;
            }
            $cleared = $reference->withoutTransferRequest();
            $database->transaction(function () use ($careers, $cleared): void { $careers->save($cleared); });
            ++$expired;
        }

        return $expired;
    }

    /**
     * Turn a legitimate NPC-market candidate into one controlled-career
     * choice. The market remains Club-owned; this method only replaces the
     * automatic execution when the candidate is the controlled Player.
     */
    public function prepareControlledTransferDecision(DatabaseInterface $database, PlayerId|string $playerId, Season $season, SimulationDate $date): ?CareerOpportunity
    {
        $playerId = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        if (!in_array($playerId->value(), (new CareerPlayerRepository($database))->playerIds(), true)) {
            throw new TransferException('Transfer decisions are available only for the controlled career Player.');
        }
        $player = (new PlayerRepository($database))->get($playerId);
        if ($player->isRetired()) {
            return null;
        }
        $sourceMembership = $this->currentMembership($database, $playerId, $season->id());
        $sourceContract = $this->contractService->repository($database)->activeForPlayer($playerId);
        if ($sourceMembership === null || $sourceContract === null || $sourceContract->clubId()->value() !== $sourceMembership->clubId()->value()) {
            return null;
        }
        // DOMAIN-019 owns an expiring Contract decision at this boundary.
        if (!$sourceContract->endDate()->isAfter($season->endDate())) {
            return null;
        }
        $sourceClub = $this->clubService->repository($database)->get($sourceMembership->clubId());
        if (!$this->sourceCanReleaseControlledPlayer($database, $sourceClub->id(), $player, $season)) {
            return null;
        }
        $sourceKey = implode('|', ['controlled-transfer', $playerId->value(), $sourceClub->id()->value(), $season->id()->value()]);
        $repository = new CareerOpportunityRepository($database);
        $existing = $repository->bySourceKey($sourceKey);
        if ($existing !== null) {
            return $existing;
        }
        foreach ($this->transferService->repository($database)->byPlayer($playerId) as $transfer) {
            if ($transfer->seasonId()->value() === $season->id()->value()) {
                return null;
            }
        }

        $currentMetrics = $this->playerMetrics($database, $player, $sourceClub->id(), $season->id());
        $candidates = [];
        foreach ($this->clubService->repository($database)->all() as $targetClub) {
            if ($targetClub->id()->value() === $sourceClub->id()->value()) {
                continue;
            }
            if ($this->competitionForClub($database, $targetClub->id(), $season->id()) === null) {
                continue;
            }
            if (count($this->clubService->squadRepository($database)->byClub($targetClub->id(), $season->id())) >= PlayerPopulationService::TARGET_SQUAD_SIZE) {
                continue;
            }
            $target = $this->targetMetrics($database, $player, $targetClub->id(), $season->id());
            if ($target['position_rank'] > 8) {
                continue;
            }
            $score = $this->interestScore($player, $sourceClub->reputation(), $sourceMembership->role(), $currentMetrics, $target, $targetClub->reputation());
            if (!$this->isJustified($currentMetrics, $target, $targetClub->reputation(), $sourceClub->reputation(), $score)) {
                continue;
            }
            $candidates[] = ['club' => $targetClub, 'metrics' => $target, 'score' => $score];
        }
        usort($candidates, static fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: strcmp($left['club']->id()->value(), $right['club']->id()->value()));
        if ($candidates === []) {
            return null;
        }

        $careerReference = (new CareerPlayerRepository($database))->byPlayer($playerId);
        $requestActive = $careerReference?->hasActiveTransferRequest($season->id()) ?? false;
        $options = [['id' => 'stay', 'kind' => 'stay', 'club_id' => $sourceClub->id()->value()]];
        foreach (array_slice($candidates, 0, self::MAX_OFFERS) as $candidate) {
            $targetClub = $candidate['club'];
            $optionKey = $sourceKey . '|' . $targetClub->id()->value();
            $context = $this->offerContext($player, $sourceClub->reputation(), $sourceMembership->role(), $currentMetrics, $targetClub->reputation(), $candidate['metrics'], $candidate['score'], $date, $optionKey, $season->id());
            $options[] = [
                'id' => 'accept-' . $targetClub->id()->value(),
                'kind' => 'accept_transfer',
                'club_id' => $targetClub->id()->value(),
                'role' => $context['proposed_role'],
                'transfer_id' => $context['transfer_id'],
                'destination_contract_id' => $context['destination_contract_id'],
                'contract_end_date' => $context['contract_end_date'],
                'fee' => $context['fee'],
                'wage' => $context['wage'],
                'interest_score' => $context['interest_score'],
                'reasons' => $context['reasons'],
            ];
        }
        $opportunity = new CareerOpportunity(
            'controlled-transfer-' . substr(hash('sha256', $sourceKey), 0, 24),
            $playerId,
            CareerOpportunityType::TransferInterest,
            $sourceClub->id(),
            null,
            $date,
            $date->addDays(30),
            CareerOpportunityStatus::Open,
            [
                'decision_kind' => 'controlled_transfer',
                'offer_status' => 'open',
                'origin' => $requestActive ? 'player_request' : 'club_interest',
                'request_season_id' => $requestActive ? $season->id()->value() : null,
                'season_id' => $season->id()->value(),
                'source_club_id' => $sourceClub->id()->value(),
                'options' => $options,
            ],
            $sourceKey,
        );
        $database->transaction(function () use ($repository, $opportunity): void { $repository->saveInTransaction($opportunity); });
        $this->events->dispatch(new GenericEvent('career.controlled_transfer_interest_created', $opportunity->toArray()));

        return $opportunity;
    }

    /** Resolve one controlled transfer-interest decision through TransferService. */
    public function resolveTransferDecision(DatabaseInterface $database, string $opportunityId, string $optionId, SimulationDate $date): CareerOpportunity
    {
        $repository = new CareerOpportunityRepository($database);
        $opportunity = $repository->get($opportunityId);
        if ($opportunity === null || $opportunity->type() !== CareerOpportunityType::TransferInterest || ($opportunity->context()['decision_kind'] ?? null) !== 'controlled_transfer') {
            throw new TransferException(sprintf('Controlled transfer decision "%s" was not found.', $opportunityId));
        }
        if ($opportunity->status() === CareerOpportunityStatus::Resolved) {
            return $opportunity;
        }
        if ($opportunity->status() !== CareerOpportunityStatus::Open) {
            throw new TransferException('Only open controlled transfer decisions can be resolved.');
        }
        if ($opportunity->expiryDate() !== null && $date->isAfter($opportunity->expiryDate())) {
            $this->setStatus($database, $opportunity, CareerOpportunityStatus::Expired, 'expired');
            throw new TransferException('Controlled transfer decision has expired.');
        }
        if (!in_array($opportunity->playerId()->value(), (new CareerPlayerRepository($database))->playerIds(), true)) {
            throw new TransferException('Controlled transfer decision no longer belongs to the career Player.');
        }
        $context = $opportunity->context();
        $selected = null;
        foreach (($context['options'] ?? []) as $option) {
            if (is_array($option) && ($option['id'] ?? null) === $optionId) {
                $selected = $option;
                break;
            }
        }
        if (!is_array($selected)) {
            throw new TransferException('Controlled transfer option is stale or unknown.');
        }
        if (($selected['kind'] ?? null) === 'stay') {
            $resolved = $opportunity->withStatusAndContext(CareerOpportunityStatus::Resolved, $this->withOfferStatus($context, 'stayed') + ['selected_option' => $optionId]);
            $this->saveStatus($database, $resolved);
            $this->clearRequestForOpportunity($database, $resolved);
            $this->events->dispatch(new GenericEvent('career.controlled_transfer_declined', $resolved->toArray()));

            return $resolved;
        }
        if (($selected['kind'] ?? null) !== 'accept_transfer') {
            throw new TransferException('Unsupported controlled transfer option.');
        }
        $transferId = new TransferId((string) ($selected['transfer_id'] ?? ''));
        $transfers = $this->transferService->repository($database);
        if ($transfers->exists($transferId)) {
            $stored = $transfers->get($transferId);
            $storedDestination = (string) ($selected['club_id'] ?? '');
            if ($stored->playerId()->value() !== $opportunity->playerId()->value() || $stored->sourceClubId()->value() !== $opportunity->sourceClubId()->value() || $stored->destinationClubId()->value() !== $storedDestination) {
                throw new TransferException('Controlled transfer record does not match the current decision.');
            }
            if ($stored->status() === TransferStatus::Completed) {
                $resolved = $opportunity->withStatusAndContext(CareerOpportunityStatus::Resolved, $this->withOfferStatus($context, 'completed') + ['selected_option' => $optionId, 'completed_transfer_id' => $stored->id()->value()]);
                $this->saveStatus($database, $resolved);

                return $resolved;
            }
        }
        $player = (new PlayerRepository($database))->get($opportunity->playerId());
        if ($player->isRetired()) {
            throw new TransferException('Retired Players cannot accept transfer interest.');
        }
        $seasonId = new SeasonId((string) ($context['season_id'] ?? ''));
        $membership = $this->currentMembership($database, $player->id(), $seasonId);
        $sourceClubId = new ClubId((string) ($context['source_club_id'] ?? $opportunity->sourceClubId()->value()));
        if ($membership === null || $membership->clubId()->value() !== $sourceClubId->value()) {
            throw new TransferException('Controlled transfer is stale because the source squad changed.');
        }
        $sourceContract = $this->contractService->repository($database)->activeForPlayer($player->id());
        $season = (new SeasonRepository($database))->get($seasonId);
        if ($sourceContract === null || $sourceContract->clubId()->value() !== $sourceClubId->value() || !$sourceContract->endDate()->isAfter($season->endDate())) {
            throw new TransferException('Controlled transfer is stale because the source Contract is no longer eligible.');
        }
        $destinationClubId = new ClubId((string) ($selected['club_id'] ?? ''));
        if (!$this->clubService->repository($database)->exists($destinationClubId) || $destinationClubId->value() === $sourceClubId->value() || $this->competitionForClub($database, $destinationClubId, $seasonId) === null) {
            throw new TransferException('Controlled transfer destination is stale.');
        }
        if (count($this->clubService->squadRepository($database)->byClub($destinationClubId, $seasonId)) >= PlayerPopulationService::TARGET_SQUAD_SIZE) {
            throw new TransferException('Controlled transfer destination has no safe squad capacity.');
        }
        if (!$this->sourceCanReleaseControlledPlayer($database, $sourceClubId, $player, $season)) {
            throw new TransferException('Controlled transfer would make the source Club unsafe.');
        }
        foreach ($this->transferService->repository($database)->byPlayer($player->id()) as $existing) {
            if ($existing->seasonId()->value() === $seasonId->value()) {
                if ($existing->status() === TransferStatus::Completed) {
                    throw new TransferException('Controlled Player has already moved in this transfer window.');
                }
            }
        }
        $transfer = $transfers->exists($transferId)
            ? $transfers->get($transferId)
            : new Transfer($transferId, $player->id(), $sourceClubId, $destinationClubId, $seasonId, (int) ($selected['fee'] ?? 0), $date, TransferStatus::Agreed);
        if ($transfer->status() === TransferStatus::Completed) {
            $resolved = $opportunity->withStatusAndContext(CareerOpportunityStatus::Resolved, $this->withOfferStatus($context, 'completed') + ['selected_option' => $optionId, 'completed_transfer_id' => $transfer->id()->value()]);
            $this->saveStatus($database, $resolved);

            return $resolved;
        }
        if ($transfer->status() !== TransferStatus::Agreed) {
            throw new TransferException('Controlled transfer cannot be executed from its current state.');
        }
        if (!$transfers->exists($transferId)) {
            $this->transferService->save($database, $transfer);
        }
        $role = SquadRole::fromInput((string) ($selected['role'] ?? SquadRole::Prospect->value));
        $completed = $this->transferService->execute($database, $transfer, new TransferExecutionTerms(new ContractId((string) $selected['destination_contract_id']), SimulationDate::fromIsoString((string) $selected['contract_end_date']), (int) $selected['wage'], $role));
        $resolved = $opportunity->withStatusAndContext(CareerOpportunityStatus::Resolved, $this->withOfferStatus($context, 'completed') + ['selected_option' => $optionId, 'completed_transfer_id' => $completed->id()->value()]);
        $this->saveStatus($database, $resolved);
        $this->clearRequestForOpportunity($database, $resolved);
        $this->events->dispatch(new GenericEvent('career.controlled_transfer_completed', $resolved->toArray()));

        return $resolved;
    }

    /**
     * Create the one controlled-player decision at an expiring Contract
     * boundary.  NPC renewal policy remains owned by SeasonRolloverService;
     * this method only turns its current-club result into career options.
     */
    public function prepareContractDecision(DatabaseInterface $database, PlayerId|string $playerId, Season $currentSeason, Season $nextSeason, SimulationDate $date, ClubSquadMembership $currentMembership, bool $currentClubOffersRenewal, ?SquadRole $nextSeasonRole = null): ?CareerOpportunity
    {
        $playerId = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        if (!in_array($playerId->value(), (new CareerPlayerRepository($database))->playerIds(), true)) {
            throw new TransferException('Contract decisions are available only for the controlled career Player.');
        }
        $player = (new PlayerRepository($database))->get($playerId);
        if ($player->isRetired()) {
            return null;
        }
        $sourceClub = $this->clubService->repository($database)->get($currentMembership->clubId());
        $sourceKey = implode('|', ['contract-decision', $playerId->value(), $currentMembership->clubId()->value(), $nextSeason->id()->value()]);
        $repository = new CareerOpportunityRepository($database);
        $existing = $repository->bySourceKey($sourceKey);
        if ($existing !== null) {
            return $existing;
        }
        $currentMetrics = $this->playerMetrics($database, $player, $sourceClub->id(), $currentSeason->id());
        $options = [];
        if ($currentClubOffersRenewal) {
            $options[] = [
                'id' => 'renew-current-club',
                'kind' => 'renew_current_club',
                'club_id' => $sourceClub->id()->value(),
                'contract_end_date' => $nextSeason->endDate()->addDays(365)->toIsoString(),
                'wage' => max(100, ($sourceClub->reputation() * 10) + ($player->overallRating() * 5)),
                'role' => ($nextSeasonRole ?? $currentMembership->role())->value,
            ];
        }
        foreach ($this->contractBoundaryCandidates($database, $player, $sourceClub, $currentMembership, $currentMetrics, $currentSeason) as $candidate) {
            $target = $candidate['club'];
            $options[] = [
                'id' => 'sign-' . $target->id()->value(),
                'kind' => 'sign_with_club',
                'club_id' => $target->id()->value(),
                'contract_end_date' => $nextSeason->endDate()->addDays(365)->toIsoString(),
                'wage' => max(100, ($target->reputation() * 10) + ($player->overallRating() * 5)),
                'role' => $candidate['role']->value,
                'interest_score' => $candidate['score'],
                'reasons' => $candidate['reasons'],
            ];
        }
        if ($options === []) {
            return null;
        }
        $options[] = ['id' => 'enter-free-agency', 'kind' => 'enter_free_agency', 'club_id' => null];
        $contract = $this->contractService->repository($database)->byPlayer($playerId);
        $currentContract = null;
        foreach (array_reverse($contract) as $candidate) {
            if ($candidate->clubId()->value() === $sourceClub->id()->value()) {
                $currentContract = $candidate;
                break;
            }
        }
        $context = [
            'decision_kind' => 'contract_boundary',
            'offer_status' => 'open',
            'season_id' => $nextSeason->id()->value(),
            'outgoing_season_id' => $currentSeason->id()->value(),
            'current_club_id' => $sourceClub->id()->value(),
            'current_contract_id' => $currentContract?->id()->value(),
            'current_role' => $currentMembership->role()->value,
            'next_role' => ($nextSeasonRole ?? $currentMembership->role())->value,
            'performance' => [
                'classification' => (string) ($currentMetrics['performance'] ?? 'insufficient_evidence'),
                'score' => (int) ($currentMetrics['performance_score'] ?? 0),
            ],
            'options' => $options,
        ];
        $opportunity = new CareerOpportunity(
            'contract-decision-' . substr(hash('sha256', $sourceKey), 0, 24),
            $playerId,
            CareerOpportunityType::ContractRenewal,
            $sourceClub->id(),
            null,
            $date,
            $nextSeason->startDate()->addDays(14),
            CareerOpportunityStatus::Open,
            $context,
            $sourceKey,
        );
        $database->transaction(function () use ($repository, $opportunity): void { $repository->saveInTransaction($opportunity); });
        $this->events->dispatch(new GenericEvent('career.contract_decision_created', $opportunity->toArray()));

        return $opportunity;
    }

    /**
     * Resolve a controlled Contract decision exactly once.  The selected
     * signing uses the same free-agent path as Club recruitment; decline
     * intentionally leaves the Player active and unattached.
     */
    public function resolveContractDecision(DatabaseInterface $database, string $opportunityId, string $optionId, SimulationDate $date): CareerOpportunity
    {
        $repository = new CareerOpportunityRepository($database);
        $opportunity = $repository->get($opportunityId);
        if ($opportunity === null || $opportunity->type() !== CareerOpportunityType::ContractRenewal) {
            throw new TransferException(sprintf('Contract decision "%s" was not found.', $opportunityId));
        }
        if ($opportunity->status() === CareerOpportunityStatus::Resolved) {
            return $opportunity;
        }
        if ($opportunity->status() !== CareerOpportunityStatus::Open) {
            throw new TransferException('Only open Contract decisions can be resolved.');
        }
        if ($opportunity->expiryDate() !== null && $date->isAfter($opportunity->expiryDate())) {
            $this->setStatus($database, $opportunity, CareerOpportunityStatus::Expired, 'expired');
            throw new TransferException('Contract decision has expired.');
        }
        if (!in_array($opportunity->playerId()->value(), (new CareerPlayerRepository($database))->playerIds(), true)) {
            throw new TransferException('Contract decision no longer belongs to the controlled career Player.');
        }
        $context = $opportunity->context();
        $selected = null;
        foreach (($context['options'] ?? []) as $option) {
            if (is_array($option) && ($option['id'] ?? null) === $optionId) {
                $selected = $option;
                break;
            }
        }
        if (!is_array($selected)) {
            throw new TransferException('Contract decision option is stale or unknown.');
        }
        $season = (new SeasonRepository($database))->get(new SeasonId((string) ($context['season_id'] ?? '')));
        $player = (new PlayerRepository($database))->get($opportunity->playerId());
        if ($player->isRetired()) {
            throw new TransferException('Retired career Players cannot accept Contract decisions.');
        }
        $kind = (string) ($selected['kind'] ?? '');
        if ($kind === 'renew_current_club' || $kind === 'sign_with_club') {
            $clubId = new ClubId((string) ($selected['club_id'] ?? ''));
            $role = SquadRole::fromInput((string) ($selected['role'] ?? SquadRole::Prospect->value));
            $contractId = new ContractId($kind === 'renew_current_club'
                ? 'career-renewal-' . substr(hash('sha256', $opportunity->sourceKey()), 0, 40)
                : 'career-free-signing-' . substr(hash('sha256', $opportunity->sourceKey() . '|' . $clubId->value()), 0, 40));
            $this->transferService->signFreeAgent($database, $player, $clubId, $season, $date, $role, $contractId, (int) ($selected['wage'] ?? 100));
        } elseif ($kind !== 'enter_free_agency') {
            throw new TransferException('Unsupported Contract decision option.');
        }
        $resolvedContext = $this->withOfferStatus($context, 'resolved');
        $resolvedContext['selected_option'] = $optionId;
        $resolved = $opportunity->withStatusAndContext(CareerOpportunityStatus::Resolved, $resolvedContext);
        $this->saveStatus($database, $resolved);
        $this->events->dispatch(new GenericEvent('career.contract_decision_resolved', $resolved->toArray()));

        return $resolved;
    }

    /** Create bounded future offers for a controlled Player already in free agency. */
    public function evaluateFreeAgentOffers(DatabaseInterface $database, PlayerId|string $playerId, Season $season, SimulationDate $date, ClubId|string $originClubId): ?CareerOpportunity
    {
        $playerId = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $originClubId = $originClubId instanceof ClubId ? $originClubId : new ClubId($originClubId);
        $player = (new PlayerRepository($database))->get($playerId);
        if ($player->isRetired() || !in_array($playerId->value(), (new CareerPlayerRepository($database))->playerIds(), true) || $this->contractService->repository($database)->activeForPlayer($playerId) !== null || $this->currentMembership($database, $playerId, $season->id()) !== null) {
            return null;
        }
        $source = $this->clubService->repository($database)->get($originClubId);
        $sourceKey = implode('|', ['free-agent-contract-decision', $playerId->value(), $season->id()->value()]);
        $repository = new CareerOpportunityRepository($database);
        if ($repository->bySourceKey($sourceKey) !== null) {
            return $repository->bySourceKey($sourceKey);
        }
        $options = [];
        foreach ($this->freeAgentCandidates($database, $player, $season, $source) as $candidate) {
            $options[] = ['id' => 'sign-' . $candidate['club']->id()->value(), 'kind' => 'sign_with_club', 'club_id' => $candidate['club']->id()->value(), 'contract_end_date' => $season->endDate()->addDays(365)->toIsoString(), 'wage' => max(100, $candidate['club']->reputation() * 10 + $player->overallRating() * 5), 'role' => $candidate['role']->value, 'interest_score' => $candidate['score'], 'reasons' => $candidate['reasons']];
        }
        if ($options === []) {
            return null;
        }
        $options[] = ['id' => 'remain-free', 'kind' => 'enter_free_agency', 'club_id' => null];
        $opportunity = new CareerOpportunity('free-agent-contract-' . substr(hash('sha256', $sourceKey), 0, 24), $playerId, CareerOpportunityType::ContractRenewal, $originClubId, null, $date, $season->startDate()->addDays(14), CareerOpportunityStatus::Open, ['decision_kind' => 'free_agent_contract', 'offer_status' => 'open', 'season_id' => $season->id()->value(), 'options' => $options], $sourceKey);
        $database->transaction(function () use ($repository, $opportunity): void { $repository->saveInTransaction($opportunity); });

        return $opportunity;
    }

    /** @return list<CareerOpportunity> */
    public function openOffers(DatabaseInterface $database, PlayerId|string $playerId, SimulationDate $date): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $repository = new CareerOpportunityRepository($database);
        foreach ($repository->openForPlayer($id) as $opportunity) {
            if ($opportunity->type() !== CareerOpportunityType::TransferInterest || $opportunity->expiryDate() === null || !$date->isAfter($opportunity->expiryDate())) {
                continue;
            }
            $expired = $opportunity->withStatusAndContext(CareerOpportunityStatus::Expired, $this->withOfferStatus($opportunity->context(), 'expired'));
            $database->transaction(function () use ($repository, $expired): void { $repository->updateStatusInTransaction($expired, CareerOpportunityStatus::Expired); });
        }

        return array_values(array_filter($repository->openForPlayer($id, $date), static fn (CareerOpportunity $opportunity): bool => $opportunity->type() === CareerOpportunityType::TransferInterest));
    }

    public function inspect(DatabaseInterface $database, string $offerId): CareerOpportunity
    {
        $offer = (new CareerOpportunityRepository($database))->get($offerId);
        if ($offer === null || $offer->type() !== CareerOpportunityType::TransferInterest) {
            throw new TransferException(sprintf('Transfer offer "%s" was not found.', $offerId));
        }

        return $offer;
    }

    public function decline(DatabaseInterface $database, string $offerId, SimulationDate $date): CareerOpportunity
    {
        $offer = $this->inspect($database, $offerId);
        if ($offer->status() !== CareerOpportunityStatus::Open) {
            throw new TransferException('Only open transfer offers can be declined.');
        }
        if ($offer->expiryDate() !== null && $date->isAfter($offer->expiryDate())) {
            $this->setStatus($database, $offer, CareerOpportunityStatus::Expired, 'expired');
            throw new TransferException('Transfer offer has expired.');
        }
        $declined = $offer->withStatusAndContext(CareerOpportunityStatus::Declined, $this->withOfferStatus($offer->context(), 'declined'));
        $this->saveStatus($database, $declined);
        $this->clearRequestForOpportunity($database, $declined);
        $this->events->dispatch(new GenericEvent('career.transfer_offer_declined', $declined->toArray()));

        return $declined;
    }

    public function accept(DatabaseInterface $database, string $offerId, SimulationDate $date): CareerOpportunity
    {
        $offer = $this->inspect($database, $offerId);
        if (($offer->context()['decision_kind'] ?? null) === 'controlled_transfer') {
            throw new TransferException('Controlled transfer decisions require an explicit option.');
        }
        if ($offer->status() === CareerOpportunityStatus::Resolved && isset($offer->context()['completed_transfer_id'])) {
            return $offer;
        }
        if (!in_array($offer->status(), [CareerOpportunityStatus::Open, CareerOpportunityStatus::Accepted], true)) {
            throw new TransferException('Only open or pending transfer offers can be accepted.');
        }
        if ($offer->expiryDate() !== null && $date->isAfter($offer->expiryDate())) {
            $this->setStatus($database, $offer, CareerOpportunityStatus::Expired, 'expired');
            throw new TransferException('Transfer offer has expired.');
        }
        $context = $offer->context();
        $player = (new PlayerRepository($database))->get($offer->playerId());
        $membership = $this->currentMembership($database, $offer->playerId(), new SeasonId((string) ($context['season_id'] ?? '')));
        if ($membership === null || $membership->clubId()->value() !== $offer->sourceClubId()->value()) {
            throw new TransferException('Transfer offer is stale because the Player is no longer with the source Club.');
        }
        $sourceContract = $this->contractService->repository($database)->activeForPlayer($offer->playerId());
        if ($sourceContract === null || $sourceContract->clubId()->value() !== $offer->sourceClubId()->value()) {
            throw new TransferException('Transfer offer is stale because the source Contract is no longer active.');
        }
        $destination = $offer->targetClubId();
        if ($destination === null || !$this->clubService->repository($database)->exists($destination) || $destination->value() === $membership->clubId()->value()) {
            throw new TransferException('Transfer offer has an invalid destination Club.');
        }
        $seasonId = new SeasonId((string) $context['season_id']);
        $transferId = new TransferId((string) $context['transfer_id']);
        $transferRepository = $this->transferService->repository($database);
        $transfer = $transferRepository->exists($transferId)
            ? $transferRepository->get($transferId)
            : new Transfer($transferId, $player->id(), $offer->sourceClubId(), $destination, $seasonId, (int) $context['fee'], $date, TransferStatus::Agreed);
        if ($transfer->status() === TransferStatus::Completed) {
            $resolved = $offer->withStatusAndContext(CareerOpportunityStatus::Resolved, $this->withOfferStatus($context, 'completed') + ['completed_transfer_id' => $transfer->id()->value()]);
            $this->saveStatus($database, $resolved);
            return $resolved;
        }
        if ($transfer->status() !== TransferStatus::Agreed) {
            throw new TransferException('Transfer offer cannot be executed from its current Transfer state.');
        }
        if (!$transferRepository->exists($transferId)) {
            $this->transferService->save($database, $transfer);
        }
        if ($offer->status() === CareerOpportunityStatus::Open) {
            $accepted = $offer->withStatusAndContext(CareerOpportunityStatus::Accepted, $this->withOfferStatus($context, 'accepted'));
            $this->saveStatus($database, $accepted);
            $offer = $accepted;
            $this->events->dispatch(new GenericEvent('career.transfer_offer_accepted', $accepted->toArray()));
        }
        $role = SquadRole::fromInput((string) ($context['proposed_role'] ?? SquadRole::Prospect->value));
        $completed = $this->transferService->execute($database, $transfer, new TransferExecutionTerms(new ContractId((string) $context['destination_contract_id']), SimulationDate::fromIsoString((string) $context['contract_end_date']), (int) $context['wage'], $role));
        $resolved = $offer->withStatusAndContext(CareerOpportunityStatus::Resolved, $this->withOfferStatus($offer->context(), 'completed') + ['completed_transfer_id' => $completed->id()->value()]);
        $this->saveStatus($database, $resolved);

        return $resolved;
    }

    /** @return list<array{club:\Goal\Legacy\Modules\Club\Domain\Club,role:SquadRole,score:int,reasons:list<string>}> */
    private function contractBoundaryCandidates(DatabaseInterface $database, Player $player, Club $sourceClub, ClubSquadMembership $sourceMembership, array $currentMetrics, Season $currentSeason): array
    {
        $candidates = [];
        foreach ($this->clubService->repository($database)->all() as $targetClub) {
            if ($targetClub->id()->value() === $sourceClub->id()->value()) {
                continue;
            }
            if (count($this->clubService->squadRepository($database)->byClub($targetClub->id(), $currentSeason->id())) >= PlayerPopulationService::TARGET_SQUAD_SIZE) {
                continue;
            }
            $target = $this->targetMetrics($database, $player, $targetClub->id(), $currentSeason->id());
            if ($target['position_rank'] > 10) {
                continue;
            }
            $score = $this->interestScore($player, $sourceClub->reputation(), $sourceMembership->role(), $currentMetrics, $target, $targetClub->reputation());
            if ($score < 70) {
                continue;
            }
            $role = $this->roleForRank($target['position_rank'], $player->overallRating(), $target['position_average']);
            $reasons = [];
            if ($target['position_count'] < 4) { $reasons[] = 'positional_need'; }
            if ($targetClub->reputation() > $sourceClub->reputation() + 5) { $reasons[] = 'step_up'; }
            if ((int) $currentMetrics['minutes'] < 900) { $reasons[] = 'playing_time'; }
            if ($reasons === []) { $reasons[] = 'contract_fit'; }
            $candidates[] = ['club' => $targetClub, 'role' => $role, 'score' => $score, 'reasons' => $reasons];
        }
        usort($candidates, static fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: strcmp($left['club']->id()->value(), $right['club']->id()->value()));

        return array_slice($candidates, 0, self::MAX_OFFERS);
    }

    /** @return list<array{club:\Goal\Legacy\Modules\Club\Domain\Club,role:SquadRole,score:int,reasons:list<string>}> */
    private function freeAgentCandidates(DatabaseInterface $database, Player $player, Season $season, Club $originClub): array
    {
        $candidates = [];
        foreach ($this->clubService->repository($database)->all() as $targetClub) {
            if (count($this->clubService->squadRepository($database)->byClub($targetClub->id(), $season->id())) >= \Goal\Legacy\Modules\Player\PlayerPopulationService::TARGET_SQUAD_SIZE) {
                continue;
            }
            $target = $this->targetMetrics($database, $player, $targetClub->id(), $season->id());
            if ($target['position_rank'] > 8) {
                continue;
            }
            $score = max(0, 80 - ($target['position_rank'] * 5)) + max(0, 25 - $target['position_count'] * 4) + max(0, 20 - abs($player->overallRating() - $targetClub->reputation())) + max(0, $targetClub->reputation() - $originClub->reputation());
            if ($score < 70) {
                continue;
            }
            $role = $this->roleForRank($target['position_rank'], $player->overallRating(), $target['position_average']);
            $candidates[] = ['club' => $targetClub, 'role' => $role, 'score' => $score, 'reasons' => ['free_agent_fit']];
        }
        usort($candidates, static fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: strcmp($left['club']->id()->value(), $right['club']->id()->value()));

        return array_slice($candidates, 0, self::MAX_OFFERS);
    }

    private function sourceCanReleaseControlledPlayer(DatabaseInterface $database, ClubId $sourceClubId, Player $player, Season $season): bool
    {
        $squad = $this->clubService->squadRepository($database)->byClub($sourceClubId, $season->id());
        if (count($squad) <= 11) {
            return false;
        }
        $players = new PlayerRepository($database);
        $counts = array_fill_keys(array_keys(self::GROUP_MINIMUMS), 0);
        foreach ($squad as $membership) {
            if ($membership->playerId()->value() === $player->id()->value()) {
                continue;
            }
            ++$counts[$this->positionGroup($players->get($membership->playerId()))];
        }

        return ($counts[$this->positionGroup($player)] ?? 0) >= self::GROUP_MINIMUMS[$this->positionGroup($player)];
    }

    /** @param array<string, mixed> $context */
    private function withOfferStatus(array $context, string $status): array
    {
        $context['offer_status'] = $status;

        return $context;
    }

    private function setStatus(DatabaseInterface $database, CareerOpportunity $offer, CareerOpportunityStatus $status, string $offerStatus): void
    {
        $this->saveStatus($database, $offer->withStatusAndContext($status, $this->withOfferStatus($offer->context(), $offerStatus)));
    }

    private function saveStatus(DatabaseInterface $database, CareerOpportunity $offer): void
    {
        $repository = new CareerOpportunityRepository($database);
        $database->transaction(function () use ($repository, $offer): void { $repository->updateStatusInTransaction($offer, $offer->status()); });
    }

    private function clearRequestForOpportunity(DatabaseInterface $database, CareerOpportunity $opportunity): void
    {
        if (($opportunity->context()['origin'] ?? null) !== 'player_request') {
            return;
        }
        $careers = new CareerPlayerRepository($database);
        $reference = $careers->byPlayer($opportunity->playerId());
        if ($reference === null || $reference->transferRequestStatus() === CareerTransferRequestStatus::None) {
            return;
        }
        $cleared = $reference->withoutTransferRequest();
        $database->transaction(function () use ($careers, $cleared): void { $careers->save($cleared); });
    }

    private function currentMembership(DatabaseInterface $database, PlayerId $playerId, SeasonId $seasonId): ?ClubSquadMembership
    {
        return $this->clubService->squadRepository($database)->byPlayer($playerId, $seasonId)[0] ?? null;
    }

    private function competitionForClub(DatabaseInterface $database, ClubId $clubId, SeasonId $seasonId): ?string
    {
        foreach ($this->clubService->membershipRepository($database)->byClub($clubId) as $membership) {
            if ($membership->seasonId()->value() === $seasonId->value()) {
                return $membership->competitionId()->value();
            }
        }

        return null;
    }

    /** @return array<string, int|float|string> */
    private function playerMetrics(DatabaseInterface $database, Player $player, ClubId $clubId, SeasonId $seasonId): array
    {
        $stats = ['appearances' => 0, 'starts' => 0, 'minutes' => 0];
        $matches = new MatchRepository($database);
        foreach ((new PlayerMatchStatRepository($database))->byPlayer($player->id()) as $stat) {
            $match = $matches->get($stat->matchId());
            if ($match->seasonId()->value() !== $seasonId->value() || $stat->clubId()->value() !== $clubId->value() || !$stat->appeared()) {
                continue;
            }
            ++$stats['appearances'];
            $stats['starts'] += $stat->started() ? 1 : 0;
            $stats['minutes'] += $stat->minutes();
        }
        $rank = $this->positionRank($database, $player, $clubId, $seasonId);
        $stats['position_rank'] = $rank;
        $stats['form'] = (new PlayerFormService())->recent($database, $player->id())['average_score'];
        $performance = (new PlayerSeasonPerformanceService())->assess($database, $player->id(), $seasonId, $clubId);
        $stats['performance'] = $performance->classification();
        $stats['performance_score'] = $performance->score();

        return $stats;
    }

    /** @return array{position_rank:int,position_average:float,position_count:int} */
    private function targetMetrics(DatabaseInterface $database, Player $player, ClubId $clubId, SeasonId $seasonId): array
    {
        $repository = new PlayerRepository($database);
        $players = [];
        foreach ($this->clubService->squadRepository($database)->byClub($clubId, $seasonId) as $membership) {
            $candidate = $repository->get($membership->playerId());
            if ($this->positionGroup($candidate) !== $this->positionGroup($player)) {
                continue;
            }
            $players[] = ['player' => $candidate, 'score' => $candidate->overallRating() * 100 + $membership->role()->weight()];
        }
        usort($players, static fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: strcmp($left['player']->id()->value(), $right['player']->id()->value()));
        $rank = 1;
        foreach ($players as $entry) {
            if ($entry['score'] > $player->overallRating() * 100) {
                ++$rank;
            }
        }
        $values = array_map(static fn (array $entry): int => $entry['player']->overallRating(), $players);

        return ['position_rank' => $rank, 'position_average' => $values === [] ? 0.0 : array_sum($values) / count($values), 'position_count' => count($players)];
    }

    private function positionRank(DatabaseInterface $database, Player $player, ClubId $clubId, SeasonId $seasonId): int
    {
        return $this->targetMetrics($database, $player, $clubId, $seasonId)['position_rank'];
    }

    /** @param array<string, int|float|string> $current @param array{position_rank:int,position_average:float,position_count:int} $target */
    private function interestScore(Player $player, int $currentReputation, SquadRole $role, array $current, array $target, int $targetReputation): int
    {
        $playingTime = max(0, 40 - (($target['position_rank'] - 1) * 5));
        $need = max(0, 25 - $target['position_count'] * 4) + max(0, (int) round(70 - $target['position_average']));
        $quality = max(0, 30 - abs($player->overallRating() - (int) round($target['position_average'])));
        $form = max(0, (int) $current['form'] - 60);
        $performance = match ((string) ($current['performance'] ?? 'insufficient_evidence')) {
            'breakout' => 12,
            'strong' => 8,
            'steady' => 2,
            default => 0,
        };
        $potential = max(0, $player->potential() - $player->overallRating());
        $levelFit = max(0, 20 - abs($targetReputation - $player->overallRating()));
        $pressure = ($current['position_rank'] > 8 ? 20 : 0) + ((int) $current['minutes'] < 900 ? 15 : 0) + ($role === SquadRole::Prospect ? 8 : 0);

        return (int) round($playingTime + $need + $quality + $form + $performance + min(20, $potential) + $levelFit + $pressure + max(0, $targetReputation - $currentReputation) / 2);
    }

    /** @param array<string, int|float|string> $current @param array{position_rank:int,position_average:float,position_count:int} $target */
    private function isJustified(array $current, array $target, int $targetReputation, int $currentReputation, int $score): bool
    {
        if ($score < 78) {
            return false;
        }
        if ((int) $current['position_rank'] <= 8 && (int) $current['appearances'] >= 5 && (int) $current['form'] < 75 && $targetReputation > $currentReputation + 5) {
            return false;
        }

        return true;
    }

    /** @param array<string, int|float|string> $current @param array{position_rank:int,position_average:float,position_count:int} $target @return array<string, mixed> */
    private function offerContext(Player $player, int $currentReputation, SquadRole $currentRole, array $current, int $targetReputation, array $target, int $score, SimulationDate $date, string $sourceKey, SeasonId $seasonId): array
    {
        $reasons = [];
        if ((int) $current['position_rank'] > $target['position_rank'] || (int) $current['minutes'] < 900) { $reasons[] = 'playing_time'; }
        if ($target['position_count'] < 4 || $target['position_average'] < 70) { $reasons[] = 'positional_need'; }
        if ((int) $current['form'] >= 75) { $reasons[] = 'strong_form'; }
        if (in_array((string) ($current['performance'] ?? ''), ['breakout', 'strong'], true)) { $reasons[] = 'season_performance'; }
        if ($player->potential() - $player->overallRating() >= 15) { $reasons[] = 'high_potential'; }
        if ($targetReputation > $currentReputation + 5) { $reasons[] = 'step_up'; }
        if ($reasons === []) { $reasons[] = 'squad_depth_upgrade'; }
        $role = $this->roleForRank($target['position_rank'], $player->overallRating(), $target['position_average']);
        $wage = max(100, $player->overallRating() * 10 + $targetReputation * 2);
        $fee = max(0, $player->overallRating() * 1000 + $player->potential() * 500 + max(0, $targetReputation - $currentReputation) * 10000);
        return [
            'offer_status' => 'open',
            'season_id' => $seasonId->value(),
            'transfer_id' => 'offer-transfer-' . substr(hash('sha256', $sourceKey . '|transfer'), 0, 24),
            'destination_contract_id' => 'offer-contract-' . substr(hash('sha256', $sourceKey . '|contract'), 0, 24),
            'contract_end_date' => $date->addDays(730)->toIsoString(),
            'fee' => $fee,
            'wage' => $wage,
            'proposed_role' => $role->value,
            'reasons' => $reasons,
            'interest_score' => $score,
            'current_position_rank' => (int) $current['position_rank'],
            'target_position_rank' => $target['position_rank'],
            'current_minutes' => (int) $current['minutes'],
            'performance_classification' => (string) ($current['performance'] ?? 'insufficient_evidence'),
            'performance_score' => (int) ($current['performance_score'] ?? 0),
            'target_position_average' => round($target['position_average'], 1),
            'expected_playing_time' => $role === SquadRole::KeyPlayer || $role === SquadRole::Regular ? 'regular' : 'rotation',
            'current_role' => $currentRole->value,
        ];
    }

    private function roleForRank(int $rank, int $ovr, float $average): SquadRole
    {
        if ($rank <= 2 && $ovr >= $average + 4) { return SquadRole::KeyPlayer; }
        if ($rank <= 5) { return SquadRole::Regular; }
        if ($rank <= 8) { return SquadRole::Rotation; }

        return SquadRole::Prospect;
    }

    private function positionGroup(Player $player): string
    {
        return match ($player->primaryPosition()->value) {
            'GK' => 'goalkeeper',
            'CB', 'LB', 'RB' => 'defensive',
            'DM', 'CM', 'AM' => 'midfield',
            'LW', 'RW', 'ST' => 'attacking',
            default => 'midfield',
        };
    }
}
