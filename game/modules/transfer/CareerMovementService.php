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
use Goal\Legacy\Modules\Contract\ContractService;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\PlayerFormService;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunity;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunityStatus;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunityType;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Persistence\CareerOpportunityRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Transfer\Domain\Transfer;
use Goal\Legacy\Modules\Transfer\Domain\TransferException;
use Goal\Legacy\Modules\Transfer\Domain\TransferExecutionTerms;
use Goal\Legacy\Modules\Transfer\Domain\TransferId;
use Goal\Legacy\Modules\Transfer\Domain\TransferStatus;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

final class CareerMovementService
{
    private const MAX_OFFERS = 3;

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
        $this->events->dispatch(new GenericEvent('career.transfer_offer_declined', $declined->toArray()));

        return $declined;
    }

    public function accept(DatabaseInterface $database, string $offerId, SimulationDate $date): CareerOpportunity
    {
        $offer = $this->inspect($database, $offerId);
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
        $potential = max(0, $player->potential() - $player->overallRating());
        $levelFit = max(0, 20 - abs($targetReputation - $player->overallRating()));
        $pressure = ($current['position_rank'] > 8 ? 20 : 0) + ((int) $current['minutes'] < 900 ? 15 : 0) + ($role === SquadRole::Prospect ? 8 : 0);

        return (int) round($playingTime + $need + $quality + $form + min(20, $potential) + $levelFit + $pressure + max(0, $targetReputation - $currentReputation) / 2);
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
