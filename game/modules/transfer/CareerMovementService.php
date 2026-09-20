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
use Goal\Legacy\Modules\Club\Persistence\ClubMembershipRepository;
use Goal\Legacy\Modules\Club\Persistence\ClubSquadRepository;
use Goal\Legacy\Modules\Competition\CompetitionService;
use Goal\Legacy\Modules\Competition\Persistence\CompetitionRepository;
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
use Goal\Legacy\Modules\Contract\ContractService;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\PlayerFormService;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\Player\PlayerSeasonPerformanceService;
use Goal\Legacy\Modules\Player\PlayerPopulationService;
use Goal\Legacy\Modules\Player\PositionDevelopmentService;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunity;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunityStatus;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunityType;
use Goal\Legacy\Modules\Player\Domain\CareerTransferRequestStatus;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerOpportunityRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerLegacyRepository;
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

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $marketProfileCache = [];

    public function __construct(
        private readonly TransferService $transferService,
        private readonly ContractService $contractService,
        private readonly ClubService $clubService,
        private readonly CompetitionService $competitionService,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /**
     * Add factual comparison context to a Contract boundary option.
     * @param array<string, mixed> $option
     * @param array<string, mixed> $careerContext
     * @param array<string, mixed>|null $sourceProfile
     * @param array<string, mixed>|null $targetProfile
     * @return array<string, mixed>
     */
    private function decorateContractOption(array $option, array $careerContext, Club $sourceClub, Club $targetClub, ?array $sourceProfile, ?array $targetProfile, ?string $currentRole, ?int $currentWage): array
    {
        $attachment = is_array($careerContext['attachment'] ?? null) ? $careerContext['attachment'] : [];
        $formerIds = array_map('strval', (array) ($careerContext['former_club_ids'] ?? []));
        $targetId = $targetClub->id()->value();
        $return = in_array($targetId, $formerIds, true);
        $sameClub = $targetId === $sourceClub->id()->value();
        $targetLevel = (string) ($targetProfile['level'] ?? 'Offering Club');
        $tradeOffs = $sameClub
            ? ['Keep the current Club relationship and existing role context.']
            : ['A new Contract would start a new Club chapter.'];
        if (($option['role'] ?? null) !== null && $currentRole !== null && $option['role'] !== $currentRole) {
            $tradeOffs[] = 'The proposed role differs from the current role.';
        }
        if ($return) {
            $tradeOffs[] = 'This would return the Player to a former Club.';
        }
        if ($currentWage !== null && (int) ($option['wage'] ?? 0) < $currentWage) {
            $tradeOffs[] = 'The proposed wage is below the current wage.';
        } elseif ($currentWage !== null && (int) ($option['wage'] ?? 0) > $currentWage) {
            $tradeOffs[] = 'The proposed wage is above the current wage.';
        }

        return $option + [
            'current_club_name' => $sourceClub->canonicalName(),
            'target_club_name' => $targetClub->canonicalName(),
            'current_club_level' => $sourceProfile['level'] ?? null,
            'target_club_level' => $targetLevel,
            'current_competition_name' => $sourceProfile['competition_name'] ?? null,
            'target_competition_name' => $targetProfile['competition_name'] ?? null,
            'current_wage' => $currentWage,
            'european_qualification' => (bool) ($targetProfile['has_europe'] ?? false),
            'current_european_qualification' => (bool) ($sourceProfile['has_europe'] ?? false),
            'return_to_former_club' => $return,
            'journey_context' => $return ? 'Return to former Club' : null,
            'attachment_state' => $attachment['state'] ?? null,
            'attachment_label' => $attachment['label'] ?? null,
            'trade_offs' => array_values(array_unique($tradeOffs)),
        ];
    }

    /**
     * Read-only market context for the controlled Player. It is deliberately
     * derived from football facts rather than stored as a second rating.
     * @return array<string, mixed>
     */
    public function marketContext(DatabaseInterface $database, PlayerId|string $playerId, SeasonId|string $seasonId, ?SimulationDate $date = null): array
    {
        $playerId = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $seasonId = $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId);
        $player = (new PlayerRepository($database))->get($playerId);
        $season = (new SeasonRepository($database))->get($seasonId);
        $date ??= $season->startDate();
        $membership = $this->currentMembership($database, $playerId, $seasonId);
        $club = $membership === null ? null : $this->clubService->repository($database)->get($membership->clubId());
        $metrics = $club === null ? ['appearances' => 0, 'starts' => 0, 'minutes' => 0, 'position_rank' => 99, 'form' => 0, 'performance' => 'insufficient_evidence', 'performance_score' => 0] : $this->playerMetrics($database, $player, $club->id(), $seasonId);
        $legacy = new CareerLegacyRepository($database, false);
        $awards = $legacy->awardsForPlayer($playerId->value());
        $honours = $legacy->honoursForPlayer($playerId->value());
        $international = $this->internationalMarketStats($database, $playerId->value());
        $market = $this->marketAssessment($player, $metrics, $membership?->role(), $awards, $honours, $international, $date);
        $positionContext = (new PositionDevelopmentService())->context($database, $playerId, $date);
        $profiles = $this->clubMarketProfiles($database, $seasonId);
        $clubProfile = $club === null ? null : ($profiles[$club->id()->value()] ?? null);

        return [
            'player_id' => $playerId->value(),
            'label' => $market['label'],
            'market_stature' => $market['label'],
            'market_band' => $market['band'],
            'age' => $player->ageAt($date),
            'overall' => $player->overallRating(),
            'potential' => $player->potential(),
            'role' => $membership?->role()->value,
            'position_context' => [
                'primary' => $positionContext['primary_position'],
                'secondary' => $positionContext['secondary_positions'],
                'developing' => $positionContext['developing_position'],
            ],
            'appearances' => (int) $metrics['appearances'],
            'minutes' => (int) $metrics['minutes'],
            'recent_form' => (int) $metrics['form'],
            'performance' => (string) $metrics['performance'],
            'performance_score' => (int) $metrics['performance_score'],
            'awards' => count($awards),
            'honours' => count($honours),
            'international_caps' => (int) ($international['caps'] ?? 0),
            'international_goals' => (int) ($international['goals'] ?? 0),
            'current_club' => $club?->canonicalName(),
            'current_club_id' => $club?->id()->value(),
            'current_club_level' => $clubProfile['level'] ?? null,
            'current_club_level_score' => $clubProfile['level_score'] ?? null,
            'current_competition' => $clubProfile['competition_name'] ?? null,
            'score' => $market['score'],
            'evidence' => 'Derived from OVR, canonical playing evidence, role, age, recognition, and international aggregates.',
        ];
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
        if ($this->hasOpenRetirementDecision($database, $playerId, $date)) {
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
        $legacy = new CareerLegacyRepository($database, false);
        $market = $this->marketAssessment($player, $currentMetrics, $sourceMembership->role(), $legacy->awardsForPlayer($playerId->value()), $legacy->honoursForPlayer($playerId->value()), $this->internationalMarketStats($database, $playerId->value()), $date);
        $profiles = $this->clubMarketProfiles($database, $seasonId);
        $careerContext = null;
        if (in_array($playerId->value(), (new CareerPlayerRepository($database))->playerIds(), true)) {
            $careerSummary = (new PlayerCareerProgressionQuery($this->clubService))->summary($database, $playerId, $date, $seasonId);
            $careerContext = is_array($careerSummary['career_context'] ?? null) ? $careerSummary['career_context'] : [];
        }
        $candidates = [];
        foreach ($this->clubService->byCompetition($database, $competitionId, $seasonId) as $targetClub) {
            if ($targetClub->id()->value() === $currentClub->id()->value()) {
                continue;
            }
            $target = $this->targetMetrics($database, $player, $targetClub->id(), $seasonId, $profiles);
            if ($target['position_rank'] > 8) {
                continue;
            }
            $score = $this->interestScore($player, $currentClub->reputation(), $sourceMembership->role(), $currentMetrics, $target, $targetClub->reputation(), $market);
            if (!$this->isJustified($currentMetrics, $target, $targetClub->reputation(), $currentClub->reputation(), $score, $market)) {
                continue;
            }
            $candidates[] = ['club' => $targetClub, 'metrics' => $target, 'score' => $score];
        }
        usort($candidates, static fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: strcmp($left['club']->id()->value(), $right['club']->id()->value()));
        $candidates = $this->selectCandidateSet($candidates, (int) $market['score']);
        $created = [];
        foreach (array_slice($candidates, 0, self::MAX_OFFERS) as $candidate) {
            $targetClub = $candidate['club'];
            $sourceKey = implode('|', ['transfer-offer', $playerId->value(), $sourceMembership->clubId()->value(), $targetClub->id()->value(), $date->year() . '-' . str_pad((string) $date->month(), 2, '0', STR_PAD_LEFT)]);
            $repository = new CareerOpportunityRepository($database);
            if ($repository->bySourceKey($sourceKey) !== null) {
                continue;
            }
            $context = $this->offerContext($player, $currentClub->reputation(), $sourceMembership->role(), $currentMetrics, $targetClub->reputation(), $candidate['metrics'], $candidate['score'], $date, $sourceKey, $seasonId, $market);
            if ($careerContext !== null) {
                $context = $this->decorateOfferContext($context, $careerContext, $currentClub, $targetClub, $profiles[$currentClub->id()->value()] ?? null, $profiles[$targetClub->id()->value()] ?? null, $sourceContract->wage());
            }
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
        if ($this->hasOpenRetirementDecision($database, $playerId, $date)) {
            throw new TransferException('Resolve the retirement decision before requesting a transfer.');
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
        $this->transferService->socialService()?->recordTransferRequest($database, $playerId, $date);
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
        $this->transferService->socialService()?->recordTransferWithdrawal($database, $playerId, $date);
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
        $legacy = new CareerLegacyRepository($database, false);
        $market = $this->marketAssessment($player, $currentMetrics, $sourceMembership->role(), $legacy->awardsForPlayer($playerId->value()), $legacy->honoursForPlayer($playerId->value()), $this->internationalMarketStats($database, $playerId->value()), $date);
        $profiles = $this->clubMarketProfiles($database, $season->id());
        $candidates = [];
        foreach ($this->clubService->repository($database)->all() as $targetClub) {
            if ($targetClub->id()->value() === $sourceClub->id()->value()) {
                continue;
            }
            if ($this->competitionForClub($database, $targetClub->id(), $season->id()) === null) {
                continue;
            }
            if (($profiles[$targetClub->id()->value()]['squad_count'] ?? PlayerPopulationService::TARGET_SQUAD_SIZE) >= PlayerPopulationService::TARGET_SQUAD_SIZE) {
                continue;
            }
            $target = $this->targetMetrics($database, $player, $targetClub->id(), $season->id(), $profiles);
            if ($target['position_rank'] > 8) {
                continue;
            }
            $score = $this->interestScore($player, $sourceClub->reputation(), $sourceMembership->role(), $currentMetrics, $target, $targetClub->reputation(), $market);
            if (!$this->isJustified($currentMetrics, $target, $targetClub->reputation(), $sourceClub->reputation(), $score, $market)) {
                continue;
            }
            $candidates[] = ['club' => $targetClub, 'metrics' => $target, 'score' => $score];
        }
        usort($candidates, static fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: strcmp($left['club']->id()->value(), $right['club']->id()->value()));
        $candidates = $this->selectCandidateSet($candidates, (int) $market['score']);
        if ($candidates === []) {
            return null;
        }

        $careerReference = (new CareerPlayerRepository($database))->byPlayer($playerId);
        $requestActive = $careerReference?->hasActiveTransferRequest($season->id()) ?? false;
        $careerSummary = (new PlayerCareerProgressionQuery($this->clubService))->summary($database, $playerId, $date, $season->id());
        $careerContext = is_array($careerSummary['career_context'] ?? null) ? $careerSummary['career_context'] : [];
        $sourceProfile = $profiles[$sourceClub->id()->value()] ?? null;
        $stayAttachment = is_array($careerContext['attachment'] ?? null) ? $careerContext['attachment'] : [];
        $stayDirection = is_array($careerContext['direction'] ?? null) ? $careerContext['direction'] : [];
        $options = [[
            'id' => 'stay',
            'kind' => 'stay',
            'club_id' => $sourceClub->id()->value(),
            'club_name' => $sourceClub->canonicalName(),
            'role' => $sourceMembership->role()->value,
            'wage' => $sourceContract->wage(),
            'current_club_level' => $sourceProfile['level'] ?? null,
            'competition_name' => $sourceProfile['competition_name'] ?? null,
            'european_qualification' => (bool) ($sourceProfile['has_europe'] ?? false),
            'attachment_state' => $stayAttachment['state'] ?? null,
            'attachment_label' => $stayAttachment['label'] ?? null,
            'trade_offs' => ['Keep the current Club role and existing Career connection.'],
        ]];
        foreach (array_slice($candidates, 0, self::MAX_OFFERS) as $candidate) {
            $targetClub = $candidate['club'];
            $optionKey = $sourceKey . '|' . $targetClub->id()->value();
            $context = $this->offerContext($player, $sourceClub->reputation(), $sourceMembership->role(), $currentMetrics, $targetClub->reputation(), $candidate['metrics'], $candidate['score'], $date, $optionKey, $season->id(), $market);
            $context = $this->decorateOfferContext($context, $careerContext, $sourceClub, $targetClub, $sourceProfile, $profiles[$targetClub->id()->value()] ?? null, $sourceContract->wage());
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
                'target_club_level' => $context['target_club_level'],
                'competition_name' => $context['competition_name'],
                'european_qualification' => $context['european_qualification'],
                'projected_role' => $context['projected_role'],
                'market_path' => $context['market_path'],
                'target_club_name' => $context['target_club_name'],
                'current_club_name' => $context['current_club_name'],
                'current_club_level' => $context['current_club_level'],
                'current_competition_name' => $context['current_competition_name'],
                'current_wage' => $context['current_wage'],
                'return_to_former_club' => $context['return_to_former_club'],
                'journey_context' => $context['journey_context'],
                'trade_offs' => $context['trade_offs'],
                'attachment_state' => $context['attachment_state'],
                'attachment_label' => $context['attachment_label'],
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
                'current_club_context' => [
                    'attachment' => $stayAttachment,
                    'direction' => $stayDirection,
                    'role' => $sourceMembership->role()->value,
                    'playing_time' => $careerSummary['manager_context']['playing_time_status'] ?? null,
                    'club_objective' => $careerSummary['club_season']['expectation'] ?? null,
                ],
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
        $legacy = new CareerLegacyRepository($database, false);
        $market = $this->marketAssessment($player, $currentMetrics, $currentMembership->role(), $legacy->awardsForPlayer($playerId->value()), $legacy->honoursForPlayer($playerId->value()), $this->internationalMarketStats($database, $playerId->value()), $date);
        $careerSummary = (new PlayerCareerProgressionQuery($this->clubService))->summary($database, $playerId, $date, $currentSeason->id());
        $careerContext = is_array($careerSummary['career_context'] ?? null) ? $careerSummary['career_context'] : [];
        $profiles = $this->clubMarketProfiles($database, $currentSeason->id());
        $sourceProfile = $profiles[$sourceClub->id()->value()] ?? null;
        $currentWage = $this->contractService->repository($database)->activeForPlayer($playerId)?->wage();
        $options = [];
        if ($currentClubOffersRenewal) {
            $renewal = [
                'id' => 'renew-current-club',
                'kind' => 'renew_current_club',
                'club_id' => $sourceClub->id()->value(),
                'contract_end_date' => $nextSeason->endDate()->addDays(365)->toIsoString(),
                'wage' => max(100, min(5000, ($sourceClub->reputation() * 10) + ($player->overallRating() * 5))),
                'role' => ($nextSeasonRole ?? $currentMembership->role())->value,
            ];
            $options[] = $this->decorateContractOption($renewal, $careerContext, $sourceClub, $sourceClub, $sourceProfile, $sourceProfile, null, $currentWage);
        }
        foreach ($this->contractBoundaryCandidates($database, $player, $sourceClub, $currentMembership, $currentMetrics, $currentSeason) as $candidate) {
            $target = $candidate['club'];
            $option = [
                'id' => 'sign-' . $target->id()->value(),
                'kind' => 'sign_with_club',
                'club_id' => $target->id()->value(),
                'contract_end_date' => $nextSeason->endDate()->addDays(365)->toIsoString(),
                'wage' => $this->offerWage($player, $candidate['target'], $market),
                'role' => $candidate['role']->value,
                'interest_score' => $candidate['score'],
                'reasons' => $candidate['reasons'],
            ];
            $options[] = $this->decorateContractOption($option, $careerContext, $sourceClub, $target, $sourceProfile, $profiles[$target->id()->value()] ?? null, $currentMembership->role()->value, $currentWage);
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
            'current_club_context' => [
                'attachment' => $careerContext['attachment'] ?? null,
                'direction' => $careerContext['direction'] ?? null,
                'role' => $currentMembership->role()->value,
                'playing_time' => $careerSummary['manager_context']['playing_time_status'] ?? null,
                'club_objective' => $careerSummary['club_season']['expectation'] ?? null,
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
            $this->transferService->signFreeAgent($database, $player, $clubId, $season, $date, $role, $contractId, (int) ($selected['wage'] ?? 100), $kind === 'renew_current_club' ? (string) ($context['current_club_id'] ?? '') : null);
        } elseif ($kind === 'enter_free_agency') {
            $this->transferService->socialService()?->recordTransfer($database, $player->id(), (string) ($context['current_club_id'] ?? '') ?: null, null, $date);
        } else {
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
        if ($player->isRetired() || $this->hasOpenRetirementDecision($database, $playerId, $date) || !in_array($playerId->value(), (new CareerPlayerRepository($database))->playerIds(), true) || $this->contractService->repository($database)->activeForPlayer($playerId) !== null || $this->currentMembership($database, $playerId, $season->id()) !== null) {
            return null;
        }
        $source = $this->clubService->repository($database)->get($originClubId);
        $sourceKey = implode('|', ['free-agent-contract-decision', $playerId->value(), $season->id()->value()]);
        $repository = new CareerOpportunityRepository($database);
        if ($repository->bySourceKey($sourceKey) !== null) {
            return $repository->bySourceKey($sourceKey);
        }
        $careerSummary = (new PlayerCareerProgressionQuery($this->clubService))->summary($database, $playerId, $date, $season->id());
        $careerContext = is_array($careerSummary['career_context'] ?? null) ? $careerSummary['career_context'] : [];
        $options = [];
        $freeAgentMarket = $this->marketAssessment($player, ['form' => 0, 'appearances' => 0, 'minutes' => 0, 'performance' => 'insufficient_evidence'], null, [], [], $this->internationalMarketStats($database, $playerId->value()), $date);
        foreach ($this->freeAgentCandidates($database, $player, $season, $source) as $candidate) {
            $age = (int) ($freeAgentMarket['age'] ?? 25);
            $duration = $age >= 31 ? 365 : ($age <= 23 ? 1095 : 730);
            $options[] = ['id' => 'sign-' . $candidate['club']->id()->value(), 'kind' => 'sign_with_club', 'club_id' => $candidate['club']->id()->value(), 'target_club_name' => $candidate['club']->canonicalName(), 'contract_end_date' => $date->addDays($duration)->toIsoString(), 'wage' => $this->offerWage($player, $candidate['target'], $freeAgentMarket), 'role' => $candidate['role']->value, 'interest_score' => $candidate['score'], 'reasons' => $candidate['reasons'], 'target_club_level' => $candidate['target']['club_level'], 'target_competition_name' => $candidate['target']['competition_name'] ?? null, 'european_qualification' => (bool) ($candidate['target']['has_europe'] ?? false), 'projected_role' => $candidate['role']->value, 'trade_offs' => ['A new Contract would start a new Club chapter after free agency.']];
        }
        if ($options === []) {
            return null;
        }
        $options[] = ['id' => 'remain-free', 'kind' => 'enter_free_agency', 'club_id' => null];
        $opportunity = new CareerOpportunity('free-agent-contract-' . substr(hash('sha256', $sourceKey), 0, 24), $playerId, CareerOpportunityType::ContractRenewal, $originClubId, null, $date, $season->startDate()->addDays(14), CareerOpportunityStatus::Open, ['decision_kind' => 'free_agent_contract', 'offer_status' => 'open', 'season_id' => $season->id()->value(), 'free_agency_context' => 'Contract expiry or departure has left the Player without an active Club; no offer is guaranteed.', 'career_context' => $careerContext, 'options' => $options], $sourceKey);
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

    private function hasOpenRetirementDecision(DatabaseInterface $database, PlayerId $playerId, SimulationDate $date): bool
    {
        foreach ((new CareerOpportunityRepository($database))->openForPlayer($playerId, $date) as $opportunity) {
            if ($opportunity->type() === CareerOpportunityType::Retirement) {
                return true;
            }
        }

        return false;
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
        $profiles = $this->clubMarketProfiles($database, $currentSeason->id());
        $legacy = new CareerLegacyRepository($database, false);
        $market = $this->marketAssessment($player, $currentMetrics, $sourceMembership->role(), $legacy->awardsForPlayer($player->id()->value()), $legacy->honoursForPlayer($player->id()->value()), $this->internationalMarketStats($database, $player->id()->value()), $currentSeason->startDate());
        $candidates = [];
        foreach ($this->clubService->repository($database)->all() as $targetClub) {
            if ($targetClub->id()->value() === $sourceClub->id()->value()) {
                continue;
            }
            if (($profiles[$targetClub->id()->value()]['squad_count'] ?? PlayerPopulationService::TARGET_SQUAD_SIZE) >= PlayerPopulationService::TARGET_SQUAD_SIZE) {
                continue;
            }
            $target = $this->targetMetrics($database, $player, $targetClub->id(), $currentSeason->id(), $profiles);
            if ($target['position_rank'] > 10) {
                continue;
            }
            $score = $this->interestScore($player, $sourceClub->reputation(), $sourceMembership->role(), $currentMetrics, $target, $targetClub->reputation(), $market);
            if ($score < 70) {
                continue;
            }
            $role = $this->roleForRank($target['position_rank'], $player->overallRating(), $target['position_average']);
            $reasons = [];
            if ($target['position_count'] < 4) { $reasons[] = 'positional_need'; }
            if ($targetClub->reputation() > $sourceClub->reputation() + 5) { $reasons[] = 'step_up'; }
            if ((int) $currentMetrics['minutes'] < 900) { $reasons[] = 'playing_time'; }
            if ($reasons === []) { $reasons[] = 'contract_fit'; }
            $candidates[] = ['club' => $targetClub, 'role' => $role, 'score' => $score, 'reasons' => $reasons, 'target' => $target];
        }
        usort($candidates, static fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: strcmp($left['club']->id()->value(), $right['club']->id()->value()));

        return array_slice($candidates, 0, self::MAX_OFFERS);
    }

    /** @return list<array{club:\Goal\Legacy\Modules\Club\Domain\Club,role:SquadRole,score:int,reasons:list<string>}> */
    private function freeAgentCandidates(DatabaseInterface $database, Player $player, Season $season, Club $originClub): array
    {
        $profiles = $this->clubMarketProfiles($database, $season->id());
        $metrics = $this->playerMetrics($database, $player, $originClub->id(), $season->id());
        $market = $this->marketAssessment($player, $metrics, null, [], [], $this->internationalMarketStats($database, $player->id()->value()), $season->startDate());
        $candidates = [];
        foreach ($this->clubService->repository($database)->all() as $targetClub) {
            if (($profiles[$targetClub->id()->value()]['squad_count'] ?? PlayerPopulationService::TARGET_SQUAD_SIZE) >= PlayerPopulationService::TARGET_SQUAD_SIZE) {
                continue;
            }
            $target = $this->targetMetrics($database, $player, $targetClub->id(), $season->id(), $profiles);
            if ($target['position_rank'] > 8) {
                continue;
            }
            $score = max(0, 80 - ($target['position_rank'] * 5)) + max(0, 25 - $target['position_count'] * 4) + max(0, 20 - abs($player->overallRating() - $targetClub->reputation())) + max(0, $targetClub->reputation() - $originClub->reputation()) + min(10, (int) ($market['recognition'] ?? 0));
            if ($score < 70) {
                continue;
            }
            $role = $this->roleForRank($target['position_rank'], $player->overallRating(), $target['position_average']);
            $candidates[] = ['club' => $targetClub, 'role' => $role, 'score' => $score, 'reasons' => ['free_agent_fit'], 'target' => $target];
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
        $competitions = $this->competitionService->repository($database);
        $candidates = [];
        foreach ($this->clubService->membershipRepository($database)->byClub($clubId) as $membership) {
            if ($membership->seasonId()->value() !== $seasonId->value()) { continue; }
            $competition = $competitions->get($membership->competitionId());
            $candidates[] = [$competition->type() === CompetitionType::DomesticLeague ? 0 : 1, $competition->id()->value()];
        }

        usort($candidates, static fn (array $left, array $right): int => ($left[0] <=> $right[0]) ?: strcmp($left[1], $right[1]));

        return $candidates[0][1] ?? null;
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

    /** @return array<string, mixed> */
    private function targetMetrics(DatabaseInterface $database, Player $player, ClubId $clubId, SeasonId $seasonId, ?array $profiles = null): array
    {
        $profiles ??= $this->clubMarketProfiles($database, $seasonId);
        $profile = $profiles[$clubId->value()] ?? ['players' => [], 'squad_count' => 0, 'capacity' => PlayerPopulationService::TARGET_SQUAD_SIZE, 'level' => 'Lower Level', 'level_score' => 0, 'competition_id' => null, 'competition_name' => null, 'has_europe' => false];
        $groups = $this->positionGroups($database, $player);
        $players = array_values(array_filter((array) ($profile['players'] ?? []), static fn (array $candidate): bool => in_array(($candidate['group'] ?? ''), $groups, true)));
        usort($players, static fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: strcmp((string) $left['player_id'], (string) $right['player_id']));
        $rank = 1;
        foreach ($players as $entry) {
            if ($entry['score'] > $player->overallRating() * 100) {
                ++$rank;
            }
        }
        $values = array_map(static fn (array $entry): int => (int) $entry['overall'], $players);
        $average = $values === [] ? 0.0 : array_sum($values) / count($values);
        $projected = $this->roleForRank($rank, $player->overallRating(), $average);

        return [
            'position_rank' => $rank,
            'position_average' => $average,
            'position_count' => count($players),
            'club_level' => (string) ($profile['level'] ?? 'Lower Level'),
            'club_level_score' => (int) ($profile['level_score'] ?? 0),
            'position_need' => count($players) < 4 || $average < max(0, $player->overallRating() - 5),
            'squad_count' => (int) ($profile['squad_count'] ?? 0),
            'capacity' => (int) ($profile['capacity'] ?? PlayerPopulationService::TARGET_SQUAD_SIZE),
            'competition_id' => $profile['competition_id'] ?? null,
            'competition_name' => $profile['competition_name'] ?? null,
            'has_europe' => (bool) ($profile['has_europe'] ?? false),
            'projected_role' => $projected->value,
            'position_groups' => $groups,
        ];
    }

    private function positionRank(DatabaseInterface $database, Player $player, ClubId $clubId, SeasonId $seasonId): int
    {
        return $this->targetMetrics($database, $player, $clubId, $seasonId)['position_rank'];
    }

    /** @param array<string, int|float|string> $current @param array<string, mixed> $target @param array<string, mixed> $market */
    private function interestScore(Player $player, int $currentReputation, SquadRole $role, array $current, array $target, int $targetReputation, ?array $market = null): int
    {
        $marketScore = (int) ($market['score'] ?? $player->overallRating());
        $playingTime = max(0, 34 - (($target['position_rank'] - 1) * 4));
        $need = max(0, 24 - $target['position_count'] * 4) + max(0, (int) round(68 - $target['position_average']));
        $quality = max(0, 26 - abs($player->overallRating() - (int) round($target['position_average'])));
        $form = max(-6, min(10, (int) round(((int) $current['form'] - 60) * 0.2)));
        $performance = match ((string) ($current['performance'] ?? 'insufficient_evidence')) {
            'breakout' => 12,
            'strong' => 8,
            'steady' => 2,
            default => 0,
        };
        $potential = ($market !== null && (int) ($market['age'] ?? 30) <= 23 && (int) ($current['minutes'] ?? 0) < 1200)
            ? min(8, (int) round(max(0, $player->potential() - $player->overallRating()) * 0.25))
            : 0;
        $targetLevel = (int) ($target['club_level_score'] ?? $targetReputation);
        $levelFit = max(0, 18 - (int) round(abs($targetLevel - $marketScore) * 0.18));
        $pressure = ($current['position_rank'] > 8 ? 20 : 0) + ((int) $current['minutes'] < 900 ? 15 : 0) + ($role === SquadRole::Prospect ? 8 : 0);
        $recognition = min(10, (int) ($market['recognition'] ?? 0));

        return max(0, (int) round(18 + $playingTime + $need + $quality + $form + $performance + $potential + $levelFit + $pressure + $recognition + max(0, $targetReputation - $currentReputation) / 3));
    }

    /** @param array<string, int|float|string> $current @param array<string, mixed> $target @param array<string, mixed> $market */
    private function isJustified(array $current, array $target, int $targetReputation, int $currentReputation, int $score, ?array $market = null): bool
    {
        if ($score < 70) {
            return false;
        }
        if ($market !== null && (int) ($market['score'] ?? 0) < 60 && (int) ($target['club_level_score'] ?? $targetReputation) > (int) ($market['score'] ?? 0) + 24 && (string) ($current['performance'] ?? '') !== 'breakout' && (int) ($market['recognition'] ?? 0) < 4) {
            return false;
        }
        if ((int) $current['position_rank'] <= 8 && (int) $current['appearances'] >= 5 && (int) $current['form'] < 75 && $targetReputation > $currentReputation + 5) {
            return false;
        }

        return true;
    }

    /**
     * Add factual comparison context to an existing market offer.  Transfer
     * eligibility and all scoring remain owned by this service; these fields
     * only make the already-legitimate choice legible to the Player.
     * @param array<string, mixed> $context
     * @param array<string, mixed> $careerContext
     * @param array<string, mixed>|null $sourceProfile
     * @param array<string, mixed>|null $targetProfile
     * @return array<string, mixed>
     */
    private function decorateOfferContext(array $context, array $careerContext, Club $sourceClub, Club $targetClub, ?array $sourceProfile, ?array $targetProfile, ?int $currentWage): array
    {
        $attachment = is_array($careerContext['attachment'] ?? null) ? $careerContext['attachment'] : [];
        $formerIds = array_map('strval', (array) ($careerContext['former_club_ids'] ?? []));
        $targetId = $targetClub->id()->value();
        $return = in_array($targetId, $formerIds, true);
        $sourceLevel = (string) ($sourceProfile['level'] ?? 'Current Club');
        $targetLevel = (string) ($targetProfile['level'] ?? $context['target_club_level'] ?? 'Offering Club');
        $sourceCompetition = $sourceProfile['competition_name'] ?? null;
        $targetCompetition = $targetProfile['competition_name'] ?? $context['competition_name'] ?? null;
        $sourceEurope = (bool) ($sourceProfile['has_europe'] ?? false);
        $targetEurope = (bool) ($targetProfile['has_europe'] ?? $context['european_qualification'] ?? false);
        $tradeOffs = [];
        if (($targetProfile['level_score'] ?? 0) > ($sourceProfile['level_score'] ?? $sourceClub->reputation()) + 5) {
            $tradeOffs[] = $targetLevel . ' football may bring a higher competition level.';
        } elseif (($targetProfile['level_score'] ?? 0) + 5 < ($sourceProfile['level_score'] ?? $sourceClub->reputation())) {
            $tradeOffs[] = 'The move may trade Club stature for a different opportunity.';
        }
        if (($context['expected_playing_time'] ?? null) === 'regular') {
            $tradeOffs[] = 'The projected role offers a regular route into the team.';
        } elseif (($context['expected_playing_time'] ?? null) !== null) {
            $tradeOffs[] = 'The projected role is not a guaranteed starting place.';
        }
        if ($targetEurope && !$sourceEurope) {
            $tradeOffs[] = 'European football is available at the offering Club.';
        } elseif (!$targetEurope && $sourceEurope) {
            $tradeOffs[] = 'The current Club has European football that may not follow the move.';
        }
        if ($currentWage !== null && (int) ($context['wage'] ?? 0) < $currentWage) {
            $tradeOffs[] = 'The offered wage is below the current wage.';
        } elseif ($currentWage !== null && (int) ($context['wage'] ?? 0) > $currentWage) {
            $tradeOffs[] = 'The offered wage is above the current wage.';
        }
        if ($tradeOffs === []) {
            $tradeOffs[] = 'The move changes the Club context while the Player keeps agency over the choice.';
        }

        $context['current_club_name'] = $sourceClub->canonicalName();
        $context['target_club_name'] = $targetClub->canonicalName();
        $context['current_club_level'] = $sourceLevel;
        $context['current_competition_name'] = $sourceCompetition;
        $context['current_european_qualification'] = $sourceEurope;
        $context['current_wage'] = $currentWage;
        $context['target_competition_name'] = $targetCompetition;
        $context['target_european_qualification'] = $targetEurope;
        $context['return_to_former_club'] = $return;
        $context['journey_context'] = $return ? 'Return to former Club' : null;
        $context['attachment_state'] = $attachment['state'] ?? null;
        $context['attachment_label'] = $attachment['label'] ?? null;
        $context['trade_offs'] = array_values(array_unique($tradeOffs));

        return $context;
    }

    /** @param array<string, int|float|string> $current @param array<string, mixed> $target @param array<string, mixed> $market @return array<string, mixed> */
    private function offerContext(Player $player, int $currentReputation, SquadRole $currentRole, array $current, int $targetReputation, array $target, int $score, SimulationDate $date, string $sourceKey, SeasonId $seasonId, ?array $market = null): array
    {
        $reasons = [];
        if ((int) $current['position_rank'] > $target['position_rank'] || (int) $current['minutes'] < 900) { $reasons[] = 'playing_time'; }
        if (($target['position_need'] ?? false) || $target['position_count'] < 4 || $target['position_average'] < 70) { $reasons[] = 'positional_need'; }
        if ((int) $current['form'] >= 75) { $reasons[] = 'strong_form'; }
        if (in_array((string) ($current['performance'] ?? ''), ['breakout', 'strong'], true)) { $reasons[] = 'season_performance'; }
        if (($market['age'] ?? 30) <= 23 && $player->potential() - $player->overallRating() >= 15) { $reasons[] = 'high_potential'; }
        if ($targetReputation > $currentReputation + 5) { $reasons[] = 'step_up'; }
        if ($reasons === []) { $reasons[] = 'squad_depth_upgrade'; }
        $role = $this->roleForRank($target['position_rank'], $player->overallRating(), $target['position_average']);
        $wage = $this->offerWage($player, $target, $market);
        $fee = max(0, $player->overallRating() * 1000 + $player->potential() * 500 + max(0, $targetReputation - $currentReputation) * 10000);
        $age = (int) ($market['age'] ?? 30);
        $durationDays = $age >= 31 ? 365 : ($age <= 23 ? 1095 : 730);
        return [
            'offer_status' => 'open',
            'season_id' => $seasonId->value(),
            'transfer_id' => 'offer-transfer-' . substr(hash('sha256', $sourceKey . '|transfer'), 0, 24),
            'destination_contract_id' => 'offer-contract-' . substr(hash('sha256', $sourceKey . '|contract'), 0, 24),
            'contract_end_date' => $date->addDays($durationDays)->toIsoString(),
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
            'market_stature' => $market['label'] ?? null,
            'target_club_level' => $target['club_level'] ?? 'Lower Level',
            'target_club_level_score' => $target['club_level_score'] ?? 0,
            'competition_id' => $target['competition_id'] ?? null,
            'competition_name' => $target['competition_name'] ?? null,
            'european_qualification' => (bool) ($target['has_europe'] ?? false),
            'projected_role' => $role->value,
            'market_path' => $this->marketPath((int) ($target['club_level_score'] ?? $targetReputation), (int) ($market['score'] ?? $player->overallRating())),
        ];
    }

    private function roleForRank(int $rank, int $ovr, float $average): SquadRole
    {
        if ($rank <= 2 && $ovr >= $average + 4) { return SquadRole::KeyPlayer; }
        if ($rank <= 5) { return SquadRole::Regular; }
        if ($rank <= 8) { return SquadRole::Rotation; }

        return SquadRole::Prospect;
    }

    /** @return array<string, array<string, mixed>> */
    private function clubMarketProfiles(DatabaseInterface $database, SeasonId $seasonId): array
    {
        $cacheKey = spl_object_id($database) . '|' . $seasonId->value();
        if (isset($this->marketProfileCache[$cacheKey])) {
            return $this->marketProfileCache[$cacheKey];
        }
        $clubs = $this->clubService->repository($database)->all();
        $competitions = new CompetitionRepository($database);
        $memberships = new ClubMembershipRepository($database);
        $squads = new ClubSquadRepository($database);
        $playersRepository = new PlayerRepository($database);
        $players = $playersRepository->all();
        $playersById = [];
        foreach ($players as $player) { $playersById[$player->id()->value()] = $player; }
        $squadsByClub = [];
        foreach ($squads->bySeason($seasonId) as $membership) { $squadsByClub[$membership->clubId()->value()][] = $membership; }
        $membershipsByClub = [];
        foreach ($memberships->bySeason($seasonId) as $membership) { $membershipsByClub[$membership->clubId()->value()][] = $membership; }
        $competitionsById = [];
        $profiles = [];
        foreach ($clubs as $club) {
            $competitionRows = [];
            $hasEurope = false;
            foreach ($membershipsByClub[$club->id()->value()] ?? [] as $membership) {
                $competitionId = $membership->competitionId()->value();
                $competition = $competitionsById[$competitionId] ??= $competitions->get($membership->competitionId());
                if ($competition->type() === CompetitionType::Continental) { $hasEurope = true; }
                $competitionRows[] = $competition;
            }
            usort($competitionRows, static fn ($left, $right): int => (($left->type() === CompetitionType::DomesticLeague ? 0 : 1) <=> ($right->type() === CompetitionType::DomesticLeague ? 0 : 1)) ?: ($left->tier() <=> $right->tier()) ?: strcmp($left->id()->value(), $right->id()->value()));
            $primary = $competitionRows[0] ?? null;
            $levelScore = $club->reputation() + ($primary?->tier() === 1 ? 4 : ($primary?->tier() === 2 ? 2 : 0)) + ($hasEurope ? 4 : 0);
            $level = $levelScore >= 92 ? 'Elite' : ($levelScore >= 78 ? 'Upper Level' : ($levelScore >= 62 ? 'Mid Level' : 'Lower Level'));
            $squadRows = $squadsByClub[$club->id()->value()] ?? [];
            $playerRows = [];
            $positionCounts = [];
            foreach ($squadRows as $membership) {
                $candidate = $playersById[$membership->playerId()->value()] ?? null;
                if ($candidate === null) { continue; }
                $group = $this->positionGroup($candidate);
                $positionCounts[$group] = ($positionCounts[$group] ?? 0) + 1;
                $playerRows[] = ['player_id' => $candidate->id()->value(), 'overall' => $candidate->overallRating(), 'group' => $group, 'score' => $candidate->overallRating() * 100 + $membership->role()->weight()];
            }
            $profiles[$club->id()->value()] = [
                'club_id' => $club->id()->value(),
                'level' => $level,
                'level_score' => min(100, $levelScore),
                'competition_id' => $primary?->id()->value(),
                'competition_name' => $primary?->name(),
                'competition_tier' => $primary?->tier(),
                'has_europe' => $hasEurope,
                'squad_count' => count($squadRows),
                'capacity' => PlayerPopulationService::TARGET_SQUAD_SIZE,
                'position_counts' => $positionCounts,
                'players' => $playerRows,
            ];
        }
        $this->marketProfileCache[$cacheKey] = $profiles;

        return $profiles;
    }

    /** @param list<array<string, mixed>> $awards @param list<array<string, mixed>> $honours @param array<string, int> $international @return array{score:int,label:string,band:string,recognition:int,age:int} */
    private function marketAssessment(Player $player, array $metrics, ?SquadRole $role, array $awards, array $honours, array $international, SimulationDate $date): array
    {
        $age = $player->ageAt($date);
        $appearances = (int) ($metrics['appearances'] ?? 0);
        $minutes = (int) ($metrics['minutes'] ?? 0);
        $evidence = min(12, (int) round($appearances * 0.6) + (int) round(min(1800, $minutes) / 300));
        $form = max(-6, min(7, (int) round(((int) ($metrics['form'] ?? 0) - 60) * 0.15)));
        $performance = match ((string) ($metrics['performance'] ?? 'insufficient_evidence')) { 'breakout' => 7, 'strong' => 4, 'steady' => 2, 'stagnant' => -3, default => 0 };
        $recognition = min(10, count($awards) * 2 + count($honours) + min(4, (int) (($international['caps'] ?? 0) / 10)));
        $roleSignal = match ($role) { SquadRole::KeyPlayer => 4, SquadRole::Regular => 2, SquadRole::Rotation => 1, default => 0 };
        $ageSignal = $age <= 23 ? min(5, (int) round(max(0, $player->potential() - $player->overallRating()) * 0.15)) : ($age >= 31 ? -min(6, $age - 30) : 2);
        $score = max(0, min(100, (int) round($player->overallRating() * 0.75 + $evidence + $form + $performance + $recognition + $roleSignal + $ageSignal)));
        $label = $score >= 88 ? 'Elite Target' : ($score >= 76 ? 'Top-Level Player' : ($score >= 62 ? 'Established Professional' : ($score >= 48 ? 'Squad-Level Player' : 'Developing Prospect')));
        $band = $score >= 88 ? 'elite' : ($score >= 76 ? 'upper' : ($score >= 62 ? 'established' : ($score >= 48 ? 'squad' : 'developing')));

        return ['score' => $score, 'label' => $label, 'band' => $band, 'recognition' => $recognition, 'age' => $age];
    }

    /** @return array{caps:int,goals:int} */
    private function internationalMarketStats(DatabaseInterface $database, string $playerId): array
    {
        if ((int) $database->connection()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'international_player_statistics'")->fetchColumn() === 0) {
            return ['caps' => 0, 'goals' => 0];
        }
        $statement = $database->connection()->prepare('SELECT COALESCE(SUM(caps), 0) caps, COALESCE(SUM(goals), 0) goals FROM international_player_statistics WHERE player_id = :player_id');
        $statement->execute(['player_id' => $playerId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC) ?: [];

        return ['caps' => (int) ($row['caps'] ?? 0), 'goals' => (int) ($row['goals'] ?? 0)];
    }

    private function offerWage(Player $player, array $target, ?array $market): int
    {
        $base = $player->overallRating() * 8;
        $club = (int) ($target['club_level_score'] ?? 60);
        $role = match ((string) ($target['projected_role'] ?? SquadRole::Prospect->value)) { SquadRole::KeyPlayer->value => 320, SquadRole::Regular->value => 180, SquadRole::Rotation->value => 90, default => 30 };
        $age = (int) ($market['age'] ?? 25);
        $ageAdjustment = $age >= 31 ? -min(120, ($age - 30) * 20) : 0;

        return max(100, min(5000, (int) round($base + ($club * 2) + $role + $ageAdjustment)));
    }

    private function marketPath(int $targetLevel, int $marketScore): string
    {
        return $targetLevel >= $marketScore + 8 ? 'upward_step' : ($targetLevel <= $marketScore - 8 ? 'opportunity_move' : 'sideways_move');
    }

    /** @param list<array<string, mixed>> $candidates @return list<array<string, mixed>> */
    private function selectCandidateSet(array $candidates, int $marketScore): array
    {
        $selected = [];
        $selectedIds = [];
        foreach (['upward_step', 'sideways_move', 'opportunity_move'] as $path) {
            foreach ($candidates as $candidate) {
                $target = (array) ($candidate['metrics'] ?? $candidate['target'] ?? []);
                $candidatePath = $this->marketPath((int) ($target['club_level_score'] ?? 0), $marketScore);
                $clubId = $candidate['club']->id()->value();
                if ($candidatePath === $path && !isset($selectedIds[$clubId])) {
                    $selected[] = $candidate;
                    $selectedIds[$clubId] = true;
                    break;
                }
            }
        }
        foreach ($candidates as $candidate) {
            $clubId = $candidate['club']->id()->value();
            if (!isset($selectedIds[$clubId])) {
                $selected[] = $candidate;
                $selectedIds[$clubId] = true;
            }
            if (count($selected) >= self::MAX_OFFERS) { break; }
        }

        return array_slice($selected, 0, self::MAX_OFFERS);
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

    /** @return list<string> */
    private function positionGroups(DatabaseInterface $database, Player $player): array
    {
        $positions = (new PositionDevelopmentService())->capabilityValues($database, $player);
        $groups = [];
        foreach ($positions as $value) {
            $group = match ($value) {
                'GK' => 'goalkeeper',
                'CB', 'LB', 'RB' => 'defensive',
                'DM', 'CM', 'AM' => 'midfield',
                'LW', 'RW', 'ST' => 'attacking',
                default => null,
            };
            if ($group !== null && !in_array($group, $groups, true)) {
                $groups[] = $group;
            }
        }

        return $groups === [] ? [$this->positionGroup($player)] : $groups;
    }
}
