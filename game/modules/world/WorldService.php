<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\World;

use DateTimeImmutable;
use Goal\Legacy\Core\Events\EventDispatcherInterface;
use Goal\Legacy\Core\Events\GenericEvent;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Time\SimulationClock;
use Goal\Legacy\Core\Time\SimulationTime;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Competition\CompetitionService;
use Goal\Legacy\Modules\Competition\Domain\Competition;
use Goal\Legacy\Modules\Competition\Domain\CompetitionStatus;
use Goal\Legacy\Modules\Competition\Persistence\CompetitionRepository;
use Goal\Legacy\Modules\Nation\NationService;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonLifecycleService;
use Goal\Legacy\Modules\World\Domain\SeasonStatus;
use Goal\Legacy\Modules\World\Domain\SimulationCalendar;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldEventNames;
use Goal\Legacy\Modules\World\Domain\WorldException;
use Goal\Legacy\Modules\World\Domain\WorldId;
use Goal\Legacy\Modules\World\Persistence\SeasonRepository;
use Goal\Legacy\Modules\World\Persistence\WorldRepository;
use InvalidArgumentException;

final class WorldService
{
    public function __construct(
        private readonly SimulationClock $clock,
        private readonly SimulationCalendar $calendar,
        private readonly EventDispatcherInterface $events,
        private readonly NationService $nationService,
        private readonly CompetitionService $competitionService,
        private readonly ClubService $clubService,
        private readonly SeasonLifecycleService $seasonLifecycle = new SeasonLifecycleService(),
    ) {
    }

    public function calendar(): SimulationCalendar
    {
        return $this->calendar;
    }

    public function repository(DatabaseInterface $database): WorldRepository
    {
        return new WorldRepository($database);
    }

    public function seasonRepository(DatabaseInterface $database): SeasonRepository
    {
        return new SeasonRepository($database);
    }

    public function initialize(DatabaseInterface $database, World $world, Season $season): void
    {
        if ($world->currentSeasonId()?->value() !== $season->id()->value()) {
            throw new WorldException('World current Season ID must match the Season being initialized.');
        }

        $nations = $this->nationService->loadSelected();
        $competitions = $this->competitionService->loadSelected();
        $clubs = $this->clubService->loadSelected();
        $this->assertReferences($world, $season, $nations, $competitions, $clubs);

        $worldRepository = new WorldRepository($database);
        $seasonRepository = new SeasonRepository($database);
        $database->transaction(function () use ($database, $world, $season, $nations, $competitions, $clubs, $worldRepository, $seasonRepository): void {
            $this->nationService->materializeInTransaction($database, $nations);
            $seasonRepository->save($season);
            $this->competitionService->materializeInTransaction($database, $competitions, $season->id());
            $this->clubService->materializeInTransaction($database, $clubs);
            $worldRepository->save($world);
        });

        $this->synchronizeClock($world);
    }

    public function load(DatabaseInterface $database, string|WorldId $id): World
    {
        $world = (new WorldRepository($database))->get($id);
        $this->synchronizeClock($world);

        return $world;
    }

    public function advanceByDays(DatabaseInterface $database, string|WorldId $id, int $days): World
    {
        if ($days < 0) {
            throw new InvalidArgumentException('World time cannot advance by a negative number of days.');
        }
        $world = (new WorldRepository($database))->get($id);

        return $this->advanceWorldToDate($database, $world, $world->currentDate($this->calendar)->addDays($days));
    }

    public function advanceToDate(DatabaseInterface $database, string|WorldId $id, SimulationDate $date): World
    {
        return $this->advanceWorldToDate($database, (new WorldRepository($database))->get($id), $date);
    }

    private function advanceWorldToDate(DatabaseInterface $database, World $world, SimulationDate $date): World
    {
        $this->synchronizeClock($world);
        $targetTime = $this->calendar->timeAt($date);
        if ($targetTime->isBefore($world->currentTime())) {
            throw new InvalidArgumentException('World simulation date cannot move backwards.');
        }
        if ($targetTime->compareTo($world->currentTime()) === 0) {
            return $world;
        }

        $seasonRepository = new SeasonRepository($database);
        $competitionRepository = new CompetitionRepository($database);
        $worldRepository = new WorldRepository($database);
        $season = $world->currentSeasonId() === null ? null : $seasonRepository->get($world->currentSeasonId());
        $transition = $season === null ? null : $this->seasonLifecycle->evaluate($season, $date);
        $competitionChanges = [];
        if ($transition !== null) {
            foreach ($competitionRepository->bySeason($transition->season()->id()) as $competition) {
                $changed = $competition;
                if ($transition->started() && $changed->status() === CompetitionStatus::Upcoming) {
                    $changed = $changed->activate($transition->season()->id());
                }
                if ($transition->completed() && $changed->status() === CompetitionStatus::Active) {
                    $changed = $changed->complete();
                }
                if ($changed->status() !== $competition->status()) {
                    $competitionChanges[] = [$competition, $changed];
                }
            }
        }
        $newWorld = $world->withTimeline($targetTime, $transition?->season()->id() ?? $world->currentSeasonId());

        $database->transaction(function () use ($seasonRepository, $competitionRepository, $worldRepository, $transition, $competitionChanges, $newWorld): void {
            if ($transition !== null) {
                $seasonRepository->save($transition->season());
            }
            foreach ($competitionChanges as [, $changed]) {
                $competitionRepository->save($changed);
            }
            $worldRepository->updateTimeline($newWorld);
        });

        $this->clock->advanceTo($targetTime);
        $timestamp = $date->atStartOfDay();
        $this->events->dispatch(new GenericEvent(WorldEventNames::TIME_ADVANCED, [
            'from_date' => $world->currentDate($this->calendar)->toIsoString(),
            'from_time' => $world->currentTime()->ticks(),
            'to_date' => $date->toIsoString(),
            'to_time' => $targetTime->ticks(),
            'world_id' => $world->id()->value(),
        ], timestamp: $timestamp));
        if ($transition?->started()) {
            $this->events->dispatch(new GenericEvent(WorldEventNames::SEASON_STARTED, [
                'season_id' => $transition->season()->id()->value(),
                'world_id' => $world->id()->value(),
            ], timestamp: $timestamp));
        }
        if ($transition?->completed()) {
            $this->events->dispatch(new GenericEvent(WorldEventNames::SEASON_COMPLETED, [
                'season_id' => $transition->season()->id()->value(),
                'world_id' => $world->id()->value(),
            ], timestamp: $timestamp));
        }
        foreach ($competitionChanges as [$before, $after]) {
            /** @var Competition $before */
            /** @var Competition $after */
            $eventName = $after->status() === CompetitionStatus::Active
                ? WorldEventNames::COMPETITION_ACTIVATED
                : WorldEventNames::COMPETITION_COMPLETED;
            $this->events->dispatch(new GenericEvent($eventName, [
                'competition_id' => $after->id()->value(),
                'nation_id' => $after->nationId()->value(),
                'season_id' => $after->seasonId()?->value(),
                'world_id' => $world->id()->value(),
            ], timestamp: $timestamp));
        }

        return $newWorld;
    }

    /** @param list<\Goal\Legacy\Modules\Nation\Domain\Nation> $nations @param list<\Goal\Legacy\Modules\Competition\Domain\CompetitionDefinition> $competitions @param list<\Goal\Legacy\Modules\Club\Domain\ClubContentDefinition> $clubs */
    private function assertReferences(World $world, Season $season, array $nations, array $competitions, array $clubs): void
    {
        $nationIds = array_map(static fn ($nation): string => $nation->id()->value(), $nations);
        $competitionIds = array_map(static fn ($competition): string => $competition->id()->value(), $competitions);
        sort($nationIds, SORT_STRING);
        sort($competitionIds, SORT_STRING);
        if ($world->nationIds() !== $nationIds || $world->competitionIds() !== $competitionIds) {
            throw new WorldException('World references must match selected Nation and Competition content.');
        }
        foreach ($clubs as $definition) {
            foreach ($definition->memberships() as $membership) {
                if ($membership->seasonId()->value() !== $season->id()->value()) {
                    throw new WorldException(sprintf('Club "%s" membership baseline Season must match the World current Season.', $definition->club()->id()->value()));
                }
            }
        }
    }

    private function synchronizeClock(World $world): void
    {
        if ($this->clock->now()->isAfter($world->currentTime())) {
            throw new WorldException('Core clock is ahead of the persisted World timeline.');
        }
        if ($this->clock->now()->isBefore($world->currentTime())) {
            $this->clock->advanceTo($world->currentTime());
        }
    }
}
