<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Persistence\ClubSquadRepository;
use Goal\Legacy\Modules\Competition\Persistence\PlayerRegistrationRepository;
use Goal\Legacy\Modules\Contract\ContractService;
use Goal\Legacy\Modules\Contract\Domain\Contract;
use Goal\Legacy\Modules\Contract\Persistence\ContractRepository;
use Goal\Legacy\Modules\Player\Domain\CareerEvent;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunity;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunityStatus;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunityType;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerCareerState;
use Goal\Legacy\Modules\Player\Domain\PlayerException;
use Goal\Legacy\Modules\Player\Domain\SeasonPerformanceAssessment;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Persistence\CareerEventRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerOpportunityRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerDevelopmentRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRetirementRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

/** Owns the season-boundary lifecycle of ordinary and controlled Players. */
final class PlayerLifecycleService
{
    private const MIN_RETIREMENT_AGE = 34;
    private const FORCED_RETIREMENT_AGE = 41;

    public function __construct(private readonly PlayerDevelopmentService $development, private readonly ContractService $contracts, private readonly ?FootballSocialService $social = null)
    {
    }

    public static function careerPhase(Player $player, SimulationDate $date): string
    {
        return match (true) {
            $player->isRetired() => 'retired',
            $player->ageAt($date) < 20 => 'youth',
            $player->ageAt($date) <= 23 => 'development',
            $player->ageAt($date) <= 29 => 'prime',
            $player->ageAt($date) <= 32 => 'experienced',
            $player->ageAt($date) <= 35 => 'veteran',
            default => 'decline',
        };
    }

    /** @return array{eligible:bool,forced:bool,age:int,phase:string,roll:int,threshold:int,reason:string} */
    public function retirementAssessment(DatabaseInterface $database, Player $player, SimulationDate $date, ?Contract $knownContract = null, ?SeasonPerformanceAssessment $performance = null): array
    {
        $age = $player->ageAt($date);
        $phase = self::careerPhase($player, $date);
        if ($player->isRetired() || $age < self::MIN_RETIREMENT_AGE) {
            return ['eligible' => false, 'forced' => false, 'age' => $age, 'phase' => $phase, 'roll' => 99, 'threshold' => 0, 'reason' => 'career_is_active'];
        }
        if ($age >= self::FORCED_RETIREMENT_AGE) {
            return ['eligible' => true, 'forced' => true, 'age' => $age, 'phase' => $phase, 'roll' => 0, 'threshold' => 100, 'reason' => 'maximum_playing_age'];
        }
        $threshold = match (true) {
            $age === 34 => 8,
            $age === 35 => 18,
            $age === 36 => 32,
            $age === 37 => 48,
            default => 66,
        };
        $ovr = $player->overallRating();
        $threshold += $ovr < 58 ? 12 : ($ovr >= 80 ? -10 : ($ovr >= 70 ? -5 : 0));
        if ($performance !== null) {
            $threshold += match ($performance->classification()) {
                'breakout', 'strong' => -12,
                'steady' => -5,
                'limited', 'insufficient_evidence' => 8,
                default => 0,
            };
        }
        $contract = $knownContract ?? $this->contracts->repository($database)->activeForPlayer($player->id());
        $threshold += $contract === null ? 7 : -3;
        if ($player->primaryPosition()->value === 'GK') {
            $threshold -= 6;
        }
        $threshold = max(0, min(92, $threshold));
        $roll = hexdec(substr(hash('sha256', 'retirement:v2|' . $player->id()->value() . '|' . $date->toIsoString()), 0, 8)) % 100;

        return ['eligible' => $roll < $threshold, 'forced' => false, 'age' => $age, 'phase' => $phase, 'roll' => $roll, 'threshold' => $threshold, 'reason' => $performance?->reason() ?? 'career_context'];
    }

    /** @return array{processed:int,retired:int,declined:int,retirement_decisions:int} */
    /** @param array<string, SeasonPerformanceAssessment> $performanceByPlayer */
    public function processSeasonBoundaryInTransaction(DatabaseInterface $database, Season $nextSeason, array $performanceByPlayer = []): array
    {
        $players = new PlayerRepository($database);
        $controlled = array_fill_keys((new CareerPlayerRepository($database))->playerIds(), true);
        $opportunities = new CareerOpportunityRepository($database);
        $retired = 0;
        $declined = 0;
        $decisions = 0;
        $processed = 0;
        $contractRepository = $this->contracts->repository($database);
        $activeContracts = [];
        foreach ($contractRepository->all() as $contract) {
            if ($contract->status()->value === 'active') {
                $activeContracts[$contract->playerId()->value()] = $contract;
            }
        }
        $developmentRepository = new PlayerDevelopmentRepository($database);
        $processedSources = $developmentRepository->bySourceId('season_lifecycle', $nextSeason->id()->value());
        $knownStates = $developmentRepository->allStates();
        foreach ($players->all() as $player) {
            if ($player->isRetired()) {
                continue;
            }
            ++$processed;
            $assessment = $performanceByPlayer[$player->id()->value()] ?? null;
            $result = $this->development->applySeasonLifecycleInTransaction($database, $player->id(), $nextSeason->startDate(), $nextSeason->id()->value(), $player, $processedSources, $knownStates, $developmentRepository, $assessment);
            if ($result->applied() && $player->ageAt($nextSeason->startDate()) >= 31) {
                ++$declined;
            }
            $current = $player;
            if ($result->applied() && $result->attributeDeltas() !== []) {
                $current = $players->get($player->id());
            }
            $active = $activeContracts[$current->id()->value()] ?? null;
            $retirement = $this->retirementAssessment($database, $current, $nextSeason->startDate(), $active, $assessment);
            if (!$retirement['eligible']) {
                continue;
            }
            if (!isset($controlled[$current->id()->value()])) {
                $this->retireNpcInTransaction($players, $contractRepository, $current, $active);
                unset($activeContracts[$current->id()->value()]);
                ++$retired;
                continue;
            }
            if ($retirement['forced']) {
                $this->retireControlledInTransaction($database, $players, $contractRepository, $current, $active, $nextSeason, 'maximum_playing_age', true);
                unset($activeContracts[$current->id()->value()]);
                ++$retired;
                continue;
            }
            // Contract expiry, free agency, and an already-open Career
            // decision own the immediate boundary.  Do not put a second
            // choice in front of the Player or strand a continuing Career
            // without a next-Season Club/Contract.  Retirement is evaluated
            // again at the next boundary if the football path remains open.
            if ($active === null || $active->endDate()->isBefore($nextSeason->startDate()) || $opportunities->openForPlayer($current->id(), $nextSeason->startDate()) !== []) {
                continue;
            }
            $sourceKey = 'retirement|' . $current->id()->value() . '|' . $nextSeason->id()->value();
            if ($opportunities->bySourceKey($sourceKey) !== null) {
                continue;
            }
            $clubId = $active?->clubId()->value();
            $context = [
                'decision_kind' => 'retirement', 'season_id' => $nextSeason->id()->value(), 'age' => $retirement['age'], 'phase' => $retirement['phase'],
                'ovr' => $current->overallRating(), 'role' => $this->latestRole($database, $current->id()), 'performance' => $assessment?->toArray(),
                'final_club_id' => $clubId, 'forced' => false, 'reason' => $retirement['reason'],
                'options' => [['id' => 'continue-playing', 'label' => 'Continue playing'], ['id' => 'retire', 'label' => 'Retire']],
            ];
            $opportunities->saveInTransaction(new CareerOpportunity(
                hash('sha256', $sourceKey), $current->id(), CareerOpportunityType::Retirement, new ClubId($clubId ?? 'free-agent'), null,
                $nextSeason->startDate(), $nextSeason->startDate()->addDays(30), CareerOpportunityStatus::Open, $context, $sourceKey,
            ));
            ++$decisions;
        }

        return ['processed' => $processed, 'retired' => $retired, 'declined' => $declined, 'retirement_decisions' => $decisions];
    }

    public function shouldRetire(DatabaseInterface $database, Player $player, SimulationDate $date, ?Contract $knownContract = null): bool
    {
        return $this->retirementAssessment($database, $player, $date, $knownContract)['eligible'];
    }

    /** @return list<string> */
    public function integrity(DatabaseInterface $database): array
    {
        $retirements = new PlayerRetirementRepository($database, false);
        if (!$retirements->available()) {
            return [];
        }
        $errors = [];
        $players = new PlayerRepository($database);
        $contracts = $this->contracts->repository($database);
        $opportunities = new CareerOpportunityRepository($database);
        $events = new CareerEventRepository($database);
        foreach ($retirements->all() as $record) {
            try {
                $player = $players->get(new PlayerId((string) $record['player_id']));
            } catch (\Throwable) {
                $errors[] = 'retirement record references missing Player=' . $record['player_id'];
                continue;
            }
            if (!$player->isRetired()) {
                $errors[] = 'retirement record belongs to active Player=' . $player->id()->value();
            }
            if ($contracts->activeForPlayer($player->id()) !== null) {
                $errors[] = 'retired Player has active Contract=' . $player->id()->value();
            }
            if ($opportunities->openForPlayer($player->id()) !== []) {
                $errors[] = 'retired Player has open Career opportunity=' . $player->id()->value();
            }
            $source = 'retirement|' . $player->id()->value() . '|' . (string) $record['retirement_season_id'];
            if ($events->bySourceKey($source) === null) {
                $errors[] = 'retired Player missing Career History landmark=' . $player->id()->value();
            }
            try {
                SimulationDate::fromIsoString((string) $record['retirement_date']);
            } catch (\Throwable) {
                $errors[] = 'retirement record has invalid date=' . $player->id()->value();
            }
        }

        return array_values(array_unique($errors));
    }

    public function resolveRetirementDecision(DatabaseInterface $database, string $opportunityId, string $optionId, SimulationDate $date): CareerOpportunity
    {
        return $database->transaction(function () use ($database, $opportunityId, $optionId, $date): CareerOpportunity {
            $opportunities = new CareerOpportunityRepository($database);
            $opportunity = $opportunities->get($opportunityId);
            if ($opportunity === null || $opportunity->type() !== CareerOpportunityType::Retirement) {
                throw new PlayerException('That retirement decision is no longer available.');
            }
            if ($opportunity->status() !== CareerOpportunityStatus::Open) {
                return $opportunity;
            }
            if ($opportunity->expiryDate() !== null && $date->isAfter($opportunity->expiryDate())) {
                $expired = $opportunity->withStatusAndContext(CareerOpportunityStatus::Expired, array_merge($opportunity->context(), ['decision_result' => 'expired']));
                $opportunities->updateStatusInTransaction($expired, CareerOpportunityStatus::Expired);
                throw new PlayerException('That retirement decision has expired.');
            }
            $playerRepository = new PlayerRepository($database);
            $player = $playerRepository->get($opportunity->playerId());
            if ($player->isRetired()) {
                return $opportunity->withStatus(CareerOpportunityStatus::Resolved);
            }
            $context = array_merge($opportunity->context(), ['decision_result' => $optionId]);
            if ($optionId === 'continue-playing') {
                $resolved = $opportunity->withStatusAndContext(CareerOpportunityStatus::Resolved, $context);
                $opportunities->updateStatusInTransaction($resolved, CareerOpportunityStatus::Resolved);
                return $resolved;
            }
            if ($optionId !== 'retire') {
                throw new PlayerException('Choose Continue Playing or Retire.');
            }
            $seasonId = new SeasonId((string) ($context['season_id'] ?? 'retirement'));
            $contracts = $this->contracts->repository($database);
            $this->retireControlledInTransaction($database, $playerRepository, $contracts, $player, $contracts->activeForPlayer($player->id()), null, 'player_choice', false, $seasonId, $date, $context['final_club_id'] ?? null);
            $resolved = $opportunity->withStatusAndContext(CareerOpportunityStatus::Resolved, $context);
            $opportunities->updateStatusInTransaction($resolved, CareerOpportunityStatus::Resolved);

            return $resolved;
        });
    }

    private function retireNpcInTransaction(PlayerRepository $players, ContractRepository $contracts, Player $player, ?Contract $active): void
    {
        $players->saveInTransaction($player->withCareerState(PlayerCareerState::Retired));
        if ($active !== null) {
            $contracts->saveInTransaction($active->terminate());
        }
    }

    private function retireControlledInTransaction(DatabaseInterface $database, PlayerRepository $players, ContractRepository $contracts, Player $player, ?Contract $active, ?Season $season, string $reason, bool $forced, ?SeasonId $seasonId = null, ?SimulationDate $date = null, ?string $finalClub = null): void
    {
        $date ??= $season?->startDate() ?? SimulationDate::fromIsoString('0001-01-01');
        $seasonId ??= $season?->id() ?? new SeasonId('retirement');
        $finalClub ??= $active?->clubId()->value();
        $players->saveInTransaction($player->withCareerState(PlayerCareerState::Retired));
        if ($active !== null) {
            $contracts->saveInTransaction($active->terminate());
        }
        $squads = new ClubSquadRepository($database);
        foreach ($squads->byPlayer($player->id(), $seasonId) as $membership) {
            $squads->remove($membership);
        }
        $registrations = new PlayerRegistrationRepository($database);
        foreach ($registrations->byPlayer($player->id()) as $registration) {
            if ($registration->seasonId()->value() === $seasonId->value()) {
                $registrations->unregister($registration);
            }
        }
        (new PlayerRetirementRepository($database))->saveInTransaction([
            'player_id' => $player->id()->value(), 'retirement_date' => $date->toIsoString(), 'retirement_season_id' => $seasonId->value(), 'final_club_id' => $finalClub, 'reason' => $reason, 'forced' => $forced,
        ]);
        $source = 'retirement|' . $player->id()->value() . '|' . $seasonId->value();
        $eventRepository = new CareerEventRepository($database);
        if ($eventRepository->bySourceKey($source) === null) {
            $finalLabel = $finalClub === null || $finalClub === '' ? 'as a free agent' : 'with their final Club';
            $event = CareerEvent::pending(hash('sha256', $source), $player->id(), $seasonId, $date, $source, 'retirement', 'playing_career_complete', 'Playing Career complete', 'Retired ' . $finalLabel . ' after completing a playing Career.', [], ['historyworthy' => true, 'newsworthy' => true, 'importance' => $forced ? 'major' : 'landmark', 'legacy_type' => 'retirement', 'final_club_id' => $finalClub])->resolved('record', ['history' => 'Playing Career complete', 'legacy_type' => 'retirement']);
            $eventRepository->saveInTransaction($event);
            $this->social?->recordAchievementInTransaction($database, $player->id(), $date, $source, 'Playing Career complete', $forced ? 'major' : 'landmark', $finalClub);
        }
    }

    private function latestClubId(DatabaseInterface $database, PlayerId $playerId): ?string
    {
        $statement = $database->connection()->prepare('SELECT club_id FROM club_squad_memberships WHERE player_id = :player_id ORDER BY season_id DESC, club_id ASC LIMIT 1');
        $statement->execute(['player_id' => $playerId->value()]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function latestRole(DatabaseInterface $database, PlayerId $playerId): ?string
    {
        $statement = $database->connection()->prepare('SELECT role FROM club_squad_memberships WHERE player_id = :player_id ORDER BY season_id DESC, club_id ASC LIMIT 1');
        $statement->execute(['player_id' => $playerId->value()]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }
}
