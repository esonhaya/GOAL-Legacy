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
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
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
    /** @var array{free_agent_signings:int,npc_transfers:int,newgens_avoided:int,position_needs_met:int} */
    private array $lastRecruitment = ['free_agent_signings' => 0, 'npc_transfers' => 0, 'newgens_avoided' => 0, 'position_needs_met' => 0];

    public function __construct(
        private readonly CompetitionService $competitionService,
        private readonly ClubService $clubService,
        private readonly ContractService $contractService,
        private readonly PlayerPopulationService $populationService,
        private readonly PlayerLifecycleService $playerLifecycle,
        private readonly ClubRecruitmentService $recruitment,
        private readonly MatchService $matchService,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /** @return array{free_agent_signings:int,npc_transfers:int,newgens_avoided:int,position_needs_met:int} */
    public function lastRecruitment(): array
    {
        return $this->lastRecruitment;
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
        $this->playerLifecycle->processSeasonBoundaryInTransaction($database, $next);

        $currentSquads = $this->clubService->squadRepository($database)->all();
        $currentSquads = array_values(array_filter($currentSquads, static fn (ClubSquadMembership $membership): bool => $membership->seasonId()->value() === $current->id()->value()));
        $byClub = [];
        foreach ($currentSquads as $membership) {
            $byClub[$membership->clubId()->value()][] = $membership;
        }
        $clubs = $this->clubService->repository($database)->all();
        $renewed = 0;
        $released = 0;
        $carried = 0;
        foreach ($clubs as $club) {
            $result = $database->transaction(fn (): array => $this->continueClubSquadInTransaction($database, $club, $byClub[$club->id()->value()] ?? [], $next, $asOfDate));
            $renewed += $result['renewed'];
            $released += $result['released'];
            $carried += $result['carried'];
        }

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
        $database->transaction(function () use ($database, $world, $previous, $next): void {
            $definitions = array_values(array_filter($this->competitionService->loadSelected(), fn (CompetitionDefinition $definition): bool => in_array($definition->id()->value(), $world->competitionIds(), true)));
            $this->competitionService->materializeInTransaction($database, $definitions, $next->id());
            $previousMemberships = $this->clubService->membershipRepository($database)->bySeason($previous->id());
            $memberships = $this->clubService->membershipRepository($database);
            foreach ($previousMemberships as $membership) {
                $continued = new ClubCompetitionMembership($membership->clubId(), $membership->competitionId(), $next->id());
                if (!$memberships->exists($continued)) {
                    $memberships->save($continued);
                }
            }
        });

        $previousSquads = array_filter($this->clubService->squadRepository($database)->all(), static fn (ClubSquadMembership $membership): bool => $membership->seasonId()->value() === $previous->id()->value());
        $population = ['players_generated' => 0];
        $recruitment = ['free_agents_signed' => 0, 'npc_transfers' => 0, 'newgens_avoided' => 0, 'position_needs_met' => 0];
        if ($previousSquads !== []) {
            $recruitment = $this->recruitment->recruit($database, $next, $asOfDate);
            $newgens = $this->populationService->generateNewgens($database, $next, $world->universeSeed(), $asOfDate);
            $fallback = $this->populationService->replenish($database, $next, $world->universeSeed(), $asOfDate);
            $population['players_generated'] = (int) ($newgens['players_generated'] ?? 0) + (int) ($fallback['players_generated'] ?? 0);
        }
        $this->lastRecruitment = [
            'free_agent_signings' => (int) ($recruitment['free_agents_signed'] ?? 0),
            'npc_transfers' => (int) ($recruitment['npc_transfers'] ?? 0),
            'newgens_avoided' => (int) ($recruitment['newgens_avoided'] ?? 0),
            'position_needs_met' => (int) ($recruitment['position_needs_met'] ?? 0),
        ];
        $fixtures = 0;
        $matches = new MatchRepository($database);
        foreach ($world->competitionIds() as $competitionId) {
            if ($matches->byCompetition($competitionId, $previous->id()) === []) {
                continue;
            }
            $fixtures += count($this->matchService->generateFixtures($database, $competitionId, $next->id()));
        }

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
        $count = 0;
        foreach ($squads->all() as $squad) {
            if ($squad->seasonId()->value() !== $season->id()->value()) {
                continue;
            }
            $contract = $contracts->activeForPlayer($squad->playerId());
            if ((new PlayerRepository($database))->get($squad->playerId())->isRetired()) {
                continue;
            }
            if ($contract === null || $contract->clubId()->value() !== $squad->clubId()->value()) {
                continue;
            }
            foreach ($clubMemberships->byClub($squad->clubId()) as $membership) {
                if ($membership->seasonId()->value() !== $season->id()->value()) {
                    continue;
                }
                $registration = new PlayerRegistration($season->id(), $membership->competitionId(), $membership->clubId(), $squad->playerId());
                if (!$registrations->exists($registration)) {
                    $registrations->register($registration);
                    ++$count;
                }
            }
        }

        return ['registrations' => $count];
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
    private function continueClubSquadInTransaction(DatabaseInterface $database, Club $club, array $memberships, Season $next, SimulationDate $asOfDate): array
    {
        $squads = $this->clubService->squadRepository($database);
        $players = new PlayerRepository($database);
        $contracts = $this->contractService->repository($database);
        $renewed = 0;
        $released = 0;
        $carried = 0;
        foreach ($memberships as $membership) {
            $player = $players->get($membership->playerId());
            if ($player->isRetired()) {
                ++$released;
                continue;
            }
            $active = $contracts->activeForPlayer($player->id());
            if ($active !== null && $active->clubId()->value() !== $club->id()->value()) {
                ++$released;
                continue;
            }
            $candidate = $active;
            if ($candidate === null) {
                foreach (array_reverse($contracts->byPlayer($player->id())) as $historical) {
                    if ($historical->clubId()->value() === $club->id()->value()) {
                        $candidate = $historical;
                        break;
                    }
                }
            }
            $needsRenewal = $candidate === null || $candidate->endDate()->isBefore($next->startDate());
            if ($needsRenewal) {
                if (!$this->shouldRenew($club, $player, $membership, $next->startDate())) {
                    ++$released;
                    continue;
                }
                $renewalId = new ContractId('renewal-' . $next->id()->value() . '-' . substr(hash('sha256', $player->id()->value() . '|' . $club->id()->value()), 0, 24));
                if (!$contracts->exists($renewalId)) {
                    $oldWage = $candidate?->wage() ?? 0;
                    if ($candidate !== null && $candidate->status()->value === 'active') {
                        $contracts->saveInTransaction($candidate->terminate());
                    }
                    $wage = max($oldWage, ($club->reputation() * 10) + ($player->overallRating() * 5));
                    $renewal = $this->contractService->create(new ContractCreationRequest($renewalId, $player->id(), $club->id(), $next->startDate(), $next->endDate()->addDays(365), $wage, $asOfDate));
                    $contracts->saveInTransaction($renewal);
                    ++$renewed;
                }
            }
            $nextMembership = new ClubSquadMembership($club->id(), $player->id(), $next->id(), $membership->role());
            if (!$squads->exists($nextMembership)) {
                $squads->save($nextMembership);
            }
            ++$carried;
        }

        return ['renewed' => $renewed, 'released' => $released, 'carried' => $carried];
    }

    private function shouldRenew(Club $club, Player $player, ClubSquadMembership $membership, SimulationDate $date): bool
    {
        $age = $player->ageAt($date);
        if ($age >= 34 && $player->overallRating() < max(60, $club->reputation() - 10)) {
            return false;
        }
        if ($membership->role() === SquadRole::KeyPlayer || $membership->role() === SquadRole::Regular) {
            return true;
        }

        return $age <= 23 || $player->overallRating() >= max(55, $club->reputation() - 18);
    }
}
