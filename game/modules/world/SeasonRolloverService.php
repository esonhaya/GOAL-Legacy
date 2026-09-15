<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\World;

use Goal\Legacy\Core\Events\EventDispatcherInterface;
use Goal\Legacy\Core\Events\GenericEvent;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Club\ClubRecruitmentService;
use Goal\Legacy\Modules\Club\Domain\Club;
use Goal\Legacy\Modules\Club\Domain\ClubCompetitionMembership;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Competition\CompetitionService;
use Goal\Legacy\Modules\Competition\PromotionRelegationService;
use Goal\Legacy\Modules\Competition\Domain\CompetitionDefinition;
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Competition\Persistence\PlayerRegistrationRepository;
use Goal\Legacy\Modules\Contract\ContractService;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Match\MatchService;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Player\PlayerPopulationService;
use Goal\Legacy\Modules\Player\PlayerLifecycleService;
use Goal\Legacy\Modules\Player\PlayerSeasonPerformanceService;
use Goal\Legacy\Modules\Player\ClubExpectationService;
use Goal\Legacy\Modules\Player\Domain\SeasonPerformanceAssessment;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunityType;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerOpportunityRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Transfer\TransferService;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SeasonStatus;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldException;
use Goal\Legacy\Modules\World\Domain\WorldEventNames;
use Goal\Legacy\Modules\World\Persistence\SeasonRepository;

final class SeasonRolloverService
{
    /** @var array{free_agent_signings:int,npc_transfers:int,newgens_avoided:int,position_needs_met:int,movement_budget:int,clubs_processed:int,candidates_evaluated:int,clubs_with_activity:int} */
    private array $lastRecruitment = ['free_agent_signings' => 0, 'npc_transfers' => 0, 'newgens_avoided' => 0, 'position_needs_met' => 0, 'movement_budget' => 0, 'clubs_processed' => 0, 'candidates_evaluated' => 0, 'clubs_with_activity' => 0];
    /** @var array{renewed:int,released:int,carried:int} */
    private array $lastLifecycle = ['renewed' => 0, 'released' => 0, 'carried' => 0];
    /** @var array{promoted:list<array{club_id:string,from_competition_id:string,to_competition_id:string,nation_id:string}>,relegated:list<array{club_id:string,from_competition_id:string,to_competition_id:string,nation_id:string}>} */
    private array $lastMovement = ['promoted' => [], 'relegated' => []];
    /** @var array<string, float> */
    private array $lastPhaseTimings = [];
    private readonly PromotionRelegationService $promotionRelegation;

    public function __construct(
        private readonly CompetitionService $competitionService,
        private readonly ClubService $clubService,
        private readonly ContractService $contractService,
        private readonly PlayerPopulationService $populationService,
        private readonly PlayerLifecycleService $playerLifecycle,
        private readonly ClubRecruitmentService $recruitment,
        private readonly MatchService $matchService,
        private readonly EventDispatcherInterface $events,
        private readonly ?TransferService $transferService = null,
    ) {
        $this->promotionRelegation = new PromotionRelegationService($clubService);
    }

    /** @return array{free_agent_signings:int,npc_transfers:int,newgens_avoided:int,position_needs_met:int,movement_budget:int,clubs_processed:int,candidates_evaluated:int,clubs_with_activity:int} */
    public function lastRecruitment(): array
    {
        return $this->lastRecruitment;
    }

    /** @return array{renewed:int,released:int,carried:int} */
    public function lastLifecycle(): array
    {
        return $this->lastLifecycle;
    }

    /** @return array{promoted:list<array{club_id:string,from_competition_id:string,to_competition_id:string,nation_id:string}>,relegated:list<array{club_id:string,from_competition_id:string,to_competition_id:string,nation_id:string}>} */
    public function lastMovement(): array
    {
        return $this->lastMovement;
    }

    /** @return array<string, float> */
    public function lastPhaseTimings(): array
    {
        return $this->lastPhaseTimings;
    }

    public function nextSeason(Season $season): Season
    {
        $startYear = $season->startDate()->year() + 1;
        $start = SimulationDate::fromIsoString(sprintf('%04d-08-01', $startYear));
        $end = SimulationDate::fromIsoString(sprintf('%04d-05-31', $startYear + 1));

        return new Season(new SeasonId(sprintf('season-%04d-%02d', $startYear, ($startYear + 1) % 100)), sprintf('%04d/%02d', $startYear, ($startYear + 1) % 100), $start, $end);
    }

    /** @return array{season: Season, renewed: int, released: int, carried: int, replenished: int, fixtures: int} */
    public function prepareNext(DatabaseInterface $database, World $world, Season $current, SimulationDate $asOfDate): array
    {
        if ($current->status() !== SeasonStatus::Completed) {
            throw new WorldException('Only completed Seasons can prepare a successor.');
        }
        $seasonRepository = new SeasonRepository($database);
        $next = $this->nextSeason($current);
        if ($seasonRepository->exists($next->id())) {
            $next = $seasonRepository->get($next->id());
            if ($next->status() !== SeasonStatus::Upcoming) {
                return ['season' => $next, 'renewed' => 0, 'released' => 0, 'carried' => 0, 'replenished' => 0, 'fixtures' => count((new MatchRepository($database))->byCompetition('premier-league', $next->id()))];
            }
        }
        $database->transaction(function () use ($seasonRepository, $next): void {
            $seasonRepository->save($next);
        });
        $performanceByPlayer = (new PlayerSeasonPerformanceService())->assessMany($database, $current->id());
        $phaseStart = hrtime(true);
        $database->transaction(fn (): array => $this->playerLifecycle->processSeasonBoundaryInTransaction($database, $next, $performanceByPlayer));
        $this->lastPhaseTimings['player_lifecycle_ms'] = $this->elapsedMilliseconds($phaseStart);

        $currentSquads = $this->clubService->squadRepository($database)->all();
        $currentSquads = array_values(array_filter($currentSquads, static fn (ClubSquadMembership $membership): bool => $membership->seasonId()->value() === $current->id()->value()));
        $byClub = [];
        foreach ($currentSquads as $membership) {
            $byClub[$membership->clubId()->value()][] = $membership;
        }
        $clubs = $this->clubService->repository($database)->all();
        $playersById = [];
        foreach ((new PlayerRepository($database))->all() as $player) {
            $playersById[$player->id()->value()] = $player;
        }
        $contractsById = [];
        $contractsByPlayer = [];
        foreach ($this->contractService->repository($database)->all() as $contract) {
            $contractsById[$contract->id()->value()] = $contract;
            $contractsByPlayer[$contract->playerId()->value()][] = $contract;
        }
        $activeContractsByPlayer = [];
        foreach ($contractsByPlayer as $playerId => $playerContracts) {
            foreach ($playerContracts as $contract) {
                if ($contract->status()->value === 'active') {
                    $activeContractsByPlayer[$playerId] = $contract;
                }
            }
        }
        $roleService = new ClubExpectationService($this->clubService);
        $nextRolesByPlayer = [];
        foreach ($byClub as $clubId => $memberships) {
            $squadPlayers = [];
            foreach ($memberships as $membership) {
                if (isset($playersById[$membership->playerId()->value()])) {
                    $squadPlayers[] = $playersById[$membership->playerId()->value()];
                }
            }
            foreach ($memberships as $membership) {
                $player = $playersById[$membership->playerId()->value()] ?? null;
                if ($player === null) {
                    continue;
                }
                $assessment = $performanceByPlayer[$player->id()->value()] ?? null;
                if ($assessment === null) {
                    continue;
                }
                $nextRolesByPlayer[$clubId . '|' . $player->id()->value()] = $roleService->roleAfterSeasonPerformance($player, $membership, $assessment, $squadPlayers);
            }
        }
        $controlledBoundaryPlayers = [];
        if ($this->transferService !== null) {
            $careerMovement = $this->transferService->careerMovement();
            $controlledIds = array_fill_keys((new CareerPlayerRepository($database))->playerIds(), true);
            foreach ($currentSquads as $membership) {
                $playerId = $membership->playerId()->value();
                if (!isset($controlledIds[$playerId])) {
                    continue;
                }
                $player = $playersById[$playerId] ?? null;
                $candidate = $activeContractsByPlayer[$playerId] ?? null;
                if ($candidate === null) {
                    foreach (array_reverse($contractsByPlayer[$playerId] ?? []) as $historical) {
                        if ($historical->clubId()->value() === $membership->clubId()->value()) {
                            $candidate = $historical;
                            break;
                        }
                    }
                }
                if ($player === null || $player->isRetired() || ($candidate !== null && !$candidate->endDate()->isBefore($next->startDate()))) {
                    continue;
                }
                $controlledBoundaryPlayers[$playerId] = true;
                $club = $this->clubService->repository($database)->get($membership->clubId());
                $nextRole = $nextRolesByPlayer[$membership->clubId()->value() . '|' . $playerId] ?? $membership->role();
                $careerMovement->prepareContractDecision($database, $player->id(), $current, $next, $asOfDate, $membership, $this->shouldRenew($club, $player, $membership, $next->startDate(), $performanceByPlayer[$playerId] ?? null), $nextRole);
            }
        }
        $renewed = 0;
        $released = 0;
        $carried = 0;
        $phaseStart = hrtime(true);
        foreach ($clubs as $club) {
            $result = $database->transaction(fn (): array => $this->continueClubSquadInTransaction($database, $club, $byClub[$club->id()->value()] ?? [], $next, $asOfDate, $playersById, $contractsByPlayer, $contractsById, $activeContractsByPlayer, $controlledBoundaryPlayers, $performanceByPlayer, $nextRolesByPlayer));
            $renewed += $result['renewed'];
            $released += $result['released'];
            $carried += $result['carried'];
        }
        $this->lastPhaseTimings['contract_squad_continuity_ms'] = $this->elapsedMilliseconds($phaseStart);
        $this->lastLifecycle = ['renewed' => $renewed, 'released' => $released, 'carried' => $carried];

        $this->events->dispatch(new GenericEvent(WorldEventNames::SEASON_CREATED, [
            'season_id' => $next->id()->value(),
            'previous_season_id' => $current->id()->value(),
            'renewed_contracts' => $renewed,
            'released_players' => $released,
            'replenished_players' => 0,
            'fixtures' => 0,
            'date' => $asOfDate->toIsoString(),
        ], timestamp: $asOfDate->atStartOfDay()));

        return ['season' => $next, 'renewed' => $renewed, 'released' => $released, 'carried' => $carried, 'replenished' => 0, 'fixtures' => 0];
    }

    /** @return array{memberships: int, replenished: int, fixtures: int, free_agent_signings: int, npc_transfers: int, newgens_avoided: int, position_needs_met: int} */
    public function materializeNext(DatabaseInterface $database, World $world, Season $previous, Season $next, SimulationDate $asOfDate): array
    {
        $definitions = array_values(array_filter($this->competitionService->loadSelected(), fn (CompetitionDefinition $definition): bool => in_array($definition->id()->value(), $world->competitionIds(), true)));
        $phaseStart = hrtime(true);
        $movement = $this->promotionRelegation->determine($database, $previous, $definitions);
        $this->lastPhaseTimings['promotion_standings_ms'] = $this->elapsedMilliseconds($phaseStart);
        $movesByClub = [];
        foreach (array_merge($movement['promoted'], $movement['relegated']) as $change) {
            $movesByClub[$change['club_id']] = $change;
        }

        $phaseStart = hrtime(true);
        $database->transaction(function () use ($database, $previous, $next, $definitions, $movesByClub): void {
            $this->competitionService->materializeInTransaction($database, $definitions, $next->id());
            $previousMemberships = $this->clubService->membershipRepository($database)->bySeason($previous->id());
            $memberships = $this->clubService->membershipRepository($database);
            foreach ($previousMemberships as $membership) {
                $clubId = $membership->clubId()->value();
                $competitionId = $membership->competitionId()->value();
                $change = $movesByClub[$clubId] ?? null;
                if ($change !== null && $change['from_competition_id'] === $competitionId) {
                    $competitionId = $change['to_competition_id'];
                }
                $continued = new ClubCompetitionMembership($membership->clubId(), new \Goal\Legacy\Modules\Competition\Domain\CompetitionId($competitionId), $next->id());
                if (!$memberships->exists($continued)) {
                    $memberships->save($continued);
                }
            }
            $this->assertNextMemberships($memberships->bySeason($next->id()), $previousMemberships, $movesByClub);
        });
        $this->lastPhaseTimings['membership_materialization_ms'] = $this->elapsedMilliseconds($phaseStart);
        $this->lastMovement = $movement;

        $previousSquads = array_filter($this->clubService->squadRepository($database)->all(), static fn (ClubSquadMembership $membership): bool => $membership->seasonId()->value() === $previous->id()->value());
        $population = ['players_generated' => 0];
        $recruitment = ['free_agents_signed' => 0, 'npc_transfers' => 0, 'newgens_avoided' => 0, 'position_needs_met' => 0, 'movement_budget' => 0, 'clubs_processed' => 0, 'candidates_evaluated' => 0, 'clubs_with_activity' => 0];
        if ($previousSquads !== []) {
            $phaseStart = hrtime(true);
            $recruitment = $this->recruitment->recruit($database, $next, $asOfDate);
            $this->lastPhaseTimings['recruitment_ms'] = $this->elapsedMilliseconds($phaseStart);
            $phaseStart = hrtime(true);
            $newgens = $this->populationService->generateNewgens($database, $next, $world->universeSeed(), $asOfDate);
            $this->lastPhaseTimings['newgens_ms'] = $this->elapsedMilliseconds($phaseStart);
            $phaseStart = hrtime(true);
            $fallback = $this->populationService->replenish($database, $next, $world->universeSeed(), $asOfDate);
            $this->lastPhaseTimings['replenishment_ms'] = $this->elapsedMilliseconds($phaseStart);
            $population['players_generated'] = (int) ($newgens['players_generated'] ?? 0) + (int) ($fallback['players_generated'] ?? 0);
        }
        $this->lastRecruitment = [
            'free_agent_signings' => (int) ($recruitment['free_agents_signed'] ?? 0),
            'npc_transfers' => (int) ($recruitment['npc_transfers'] ?? 0),
            'newgens_avoided' => (int) ($recruitment['newgens_avoided'] ?? 0),
            'position_needs_met' => (int) ($recruitment['position_needs_met'] ?? 0),
            'movement_budget' => (int) ($recruitment['movement_budget'] ?? 0),
            'clubs_processed' => (int) ($recruitment['clubs_processed'] ?? 0),
            'candidates_evaluated' => (int) ($recruitment['candidates_evaluated'] ?? 0),
            'clubs_with_activity' => (int) ($recruitment['clubs_with_activity'] ?? 0),
        ];
        $fixtures = 0;
        $phaseStart = hrtime(true);
        $matches = new MatchRepository($database);
        foreach ($world->competitionIds() as $competitionId) {
            if ($matches->byCompetition($competitionId, $previous->id()) === []) {
                continue;
            }
            $fixtures += count($this->matchService->generateFixtures($database, $competitionId, $next->id()));
        }
        $this->lastPhaseTimings['fixture_generation_ms'] = $this->elapsedMilliseconds($phaseStart);

        return ['memberships' => count($this->clubService->membershipRepository($database)->bySeason($next->id())), 'replenished' => (int) ($population['players_generated'] ?? 0), 'fixtures' => $fixtures, 'free_agent_signings' => (int) ($recruitment['free_agents_signed'] ?? 0), 'npc_transfers' => (int) ($recruitment['npc_transfers'] ?? 0), 'newgens_avoided' => (int) ($recruitment['newgens_avoided'] ?? 0), 'position_needs_met' => (int) ($recruitment['position_needs_met'] ?? 0)];
    }

    /** @return array{registrations: int} */
    public function activateNext(DatabaseInterface $database, Season $season): array
    {
        if ($season->status() !== SeasonStatus::Active) {
            throw new WorldException('Only active Seasons can register Players.');
        }
        $registrations = new PlayerRegistrationRepository($database);
        $squads = $this->clubService->squadRepository($database);
        $clubMemberships = $this->clubService->membershipRepository($database);
        $contracts = $this->contractService->repository($database);
        $players = [];
        foreach ((new PlayerRepository($database))->all() as $player) {
            $players[$player->id()->value()] = $player;
        }
        $activeContracts = [];
        foreach ($contracts->all() as $contract) {
            if ($contract->status()->value === 'active') {
                $activeContracts[$contract->playerId()->value()] = $contract;
            }
        }
        $competitionMemberships = [];
        foreach ($clubMemberships->bySeason($season->id()) as $membership) {
            $competitionMemberships[$membership->clubId()->value()][] = $membership;
        }
        $existingRegistrations = [];
        foreach ($registrations->bySeason($season->id()) as $registration) {
            $existingRegistrations[$registration->key()] = true;
        }
        $pending = [];
        $phaseStart = hrtime(true);
        $count = 0;
        foreach ($squads->all() as $squad) {
            if ($squad->seasonId()->value() !== $season->id()->value()) {
                continue;
            }
            $player = $players[$squad->playerId()->value()] ?? null;
            $contract = $activeContracts[$squad->playerId()->value()] ?? null;
            if ($player === null || $player->isRetired()) {
                continue;
            }
            if ($contract === null || $contract->clubId()->value() !== $squad->clubId()->value()) {
                continue;
            }
            foreach ($competitionMemberships[$squad->clubId()->value()] ?? [] as $membership) {
                $registration = new PlayerRegistration($season->id(), $membership->competitionId(), $membership->clubId(), $squad->playerId());
                if (!isset($existingRegistrations[$registration->key()])) {
                    $pending[] = $registration;
                }
            }
        }
        $count = $database->transaction(fn (): int => $registrations->registerManyInTransaction($pending));

        $this->lastPhaseTimings['registration_activation_ms'] = $this->elapsedMilliseconds($phaseStart);
        return ['registrations' => $count];
    }

    public function assertControlledContractDecisionsResolved(DatabaseInterface $database, Season $next): void
    {
        $repository = new CareerOpportunityRepository($database);
        foreach ((new CareerPlayerRepository($database))->playerIds() as $playerId) {
            foreach ($repository->openForPlayer(new PlayerId($playerId)) as $opportunity) {
                if ($opportunity->type() === CareerOpportunityType::ContractRenewal && ($opportunity->context()['season_id'] ?? null) === $next->id()->value()) {
                    throw new WorldException(sprintf('Controlled Contract decision for Player "%s" must be resolved before Season "%s" starts.', $playerId, $next->id()->value()));
                }
            }
        }
    }

    public function competitionsComplete(DatabaseInterface $database, World $world, Season $season): bool
    {
        $matches = new MatchRepository($database);
        foreach ($world->competitionIds() as $competitionId) {
            $records = $matches->byCompetition($competitionId, $season->id());
            if ($records !== [] && count(array_filter($records, static fn ($match): bool => $match->status()->value !== 'completed')) > 0) {
                return false;
            }
        }

        return true;
    }

    /** @param list<ClubSquadMembership> $memberships @return array{renewed: int, released: int, carried: int} */
    private function continueClubSquadInTransaction(DatabaseInterface $database, Club $club, array $memberships, Season $next, SimulationDate $asOfDate, array $playersById, array $contractsByPlayer, array &$contractsById, array &$activeContractsByPlayer, array $controlledBoundaryPlayers = [], array $performanceByPlayer = [], array $nextRolesByPlayer = []): array
    {
        $squads = $this->clubService->squadRepository($database);
        $contracts = $this->contractService->repository($database);
        $renewed = 0;
        $released = 0;
        $carried = 0;
        foreach ($memberships as $membership) {
            if (isset($controlledBoundaryPlayers[$membership->playerId()->value()])) {
                continue;
            }
            $player = $playersById[$membership->playerId()->value()] ?? null;
            if ($player === null) {
                ++$released;
                continue;
            }
            if ($player->isRetired()) {
                ++$released;
                continue;
            }
            $active = $activeContractsByPlayer[$player->id()->value()] ?? null;
            if ($active !== null && $active->clubId()->value() !== $club->id()->value()) {
                ++$released;
                continue;
            }
            $candidate = $active;
            if ($candidate === null) {
                foreach (array_reverse($contractsByPlayer[$player->id()->value()] ?? []) as $historical) {
                    if ($historical->clubId()->value() === $club->id()->value()) {
                        $candidate = $historical;
                        break;
                    }
                }
            }
            $needsRenewal = $candidate === null || $candidate->endDate()->isBefore($next->startDate());
            if ($needsRenewal) {
                if (!$this->shouldRenew($club, $player, $membership, $next->startDate(), $performanceByPlayer[$player->id()->value()] ?? null)) {
                    ++$released;
                    continue;
                }
                $renewalId = new ContractId('renewal-' . $next->id()->value() . '-' . substr(hash('sha256', $player->id()->value() . '|' . $club->id()->value()), 0, 24));
                if (!isset($contractsById[$renewalId->value()])) {
                    $oldWage = $candidate?->wage() ?? 0;
                    if ($candidate !== null && $candidate->status()->value === 'active') {
                        $contracts->saveInTransaction($candidate->terminate());
                    }
                    $wage = max($oldWage, ($club->reputation() * 10) + ($player->overallRating() * 5));
                    $renewal = $this->contractService->create(new ContractCreationRequest($renewalId, $player->id(), $club->id(), $next->startDate(), $next->endDate()->addDays(365), $wage, $asOfDate));
                    $contracts->saveInTransaction($renewal);
                    $contractsById[$renewal->id()->value()] = $renewal;
                    $contractsByPlayer[$player->id()->value()][] = $renewal;
                    $activeContractsByPlayer[$player->id()->value()] = $renewal;
                    ++$renewed;
                }
            }
            $nextRole = $nextRolesByPlayer[$club->id()->value() . '|' . $player->id()->value()] ?? $membership->role();
            $nextMembership = new ClubSquadMembership($club->id(), $player->id(), $next->id(), $nextRole);
            if (!$squads->exists($nextMembership)) {
                $squads->save($nextMembership);
            }
            ++$carried;
        }

        return ['renewed' => $renewed, 'released' => $released, 'carried' => $carried];
    }

    private function shouldRenew(Club $club, Player $player, ClubSquadMembership $membership, SimulationDate $date, ?SeasonPerformanceAssessment $performance = null): bool
    {
        $age = $player->ageAt($date);
        if ($age >= 34 && $player->overallRating() < max(60, $club->reputation() - 10)) {
            return false;
        }
        if ($performance !== null && in_array($performance->classification(), ['breakout', 'strong'], true) && $age < 34 && $player->overallRating() >= max(45, $club->reputation() - 30)) {
            return true;
        }
        if ($performance?->classification() === 'stagnant' && $age > 23 && $membership->role() !== SquadRole::KeyPlayer && $player->overallRating() < max(55, $club->reputation() - 18)) {
            return false;
        }
        if ($membership->role() === SquadRole::KeyPlayer || $membership->role() === SquadRole::Regular) {
            return true;
        }

        return $age <= 23 || $player->overallRating() >= max(55, $club->reputation() - 18);
    }

    /** @param list<ClubCompetitionMembership> $nextMemberships @param list<ClubCompetitionMembership> $previousMemberships @param array<string, array{club_id:string,from_competition_id:string,to_competition_id:string,nation_id:string}> $movesByClub */
    private function assertNextMemberships(array $nextMemberships, array $previousMemberships, array $movesByClub): void
    {
        $expected = [];
        foreach ($previousMemberships as $membership) {
            $clubId = $membership->clubId()->value();
            $competitionId = $membership->competitionId()->value();
            $change = $movesByClub[$clubId] ?? null;
            if ($change !== null && $change['from_competition_id'] === $competitionId) {
                $competitionId = $change['to_competition_id'];
            }
            $expected[$competitionId . ':' . $clubId] = true;
        }
        $actual = [];
        $clubs = [];
        foreach ($nextMemberships as $membership) {
            $clubId = $membership->clubId()->value();
            $key = $membership->competitionId()->value() . ':' . $clubId;
            if (isset($actual[$key]) || isset($clubs[$clubId])) {
                throw new WorldException(sprintf('Next Season contains duplicate Competition membership for Club "%s".', $clubId));
            }
            $actual[$key] = true;
            $clubs[$clubId] = true;
        }
        ksort($expected);
        ksort($actual);
        if ($expected !== $actual) {
            throw new WorldException('Next Season Competition membership does not match the finalized tier exchange.');
        }
    }

    private function elapsedMilliseconds(int $start): float
    {
        return round((hrtime(true) - $start) / 1_000_000, 2);
    }
}
