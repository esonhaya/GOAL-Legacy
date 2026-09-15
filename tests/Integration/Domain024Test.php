<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\SeasonPerformanceAssessment;
use Goal\Legacy\Modules\Player\Persistence\PlayerDevelopmentRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SeasonStatus;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use Goal\Legacy\Modules\World\Persistence\SeasonRepository;
use PHPUnit\Framework\TestCase;

final class Domain024Test extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            foreach (glob($root . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

    public function testRealMatchPerformanceFeedsDevelopmentAtRolloverAndReloads(): void
    {
        [$services, $database, $season, $store] = $this->scenario('domain-024-production');
        $playerService = $services->playerModule()->service();
        $player = $playerService->create($this->request('development-career-player', new PlayerAttributeSet(72, 72, 72, 72, 72, 72), 95));
        $playerService->initializeCareer($database, $player, new \Goal\Legacy\Modules\Player\Domain\CareerPlayerReference(new \Goal\Legacy\Modules\Player\Domain\CareerId('domain-024-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id()));
        $contracts = $services->contractModule()->service();
        $contracts->save($database, $contracts->create(new ContractCreationRequest(new ContractId('domain-024-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2026-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));

        $fixtures = array_values(array_filter($services->matchModule()->service()->generateFixtures($database, 'premier-league', $season->id()), static fn ($match): bool => $match->homeClubId()->value() === 'arsenal' || $match->awayClubId()->value() === 'arsenal'));
        self::assertNotEmpty($fixtures);
        $matchService = $services->matchModule()->service();
        $services->worldModule()->service()->advanceToDate($database, 'domain-024-production', $fixtures[0]->scheduledDate());
        $completed = $matchService->simulateDue($database, $fixtures[0]->scheduledDate());
        self::assertNotEmpty(array_filter($completed, fn ($match): bool => $matchService->playerSummary($database, $match->id(), $player->id()) !== null));

        $before = $playerService->repository($database)->get($player->id())->overallRating();
        $seasonAssessment = (new \Goal\Legacy\Modules\Player\PlayerSeasonPerformanceService())->assess($database, $player->id(), $season->id(), new ClubId('arsenal'));
        self::assertSame('breakout', $seasonAssessment->classification());

        $storedSeason = (new SeasonRepository($database))->get($season->id());
        $completedSeason = $storedSeason->status() === SeasonStatus::Active ? $storedSeason->complete() : $storedSeason;
        (new SeasonRepository($database))->save($completedSeason);
        $rollover = $services->worldModule()->service()->seasonRollover();
        self::assertNotNull($rollover);
        $next = $rollover->nextSeason($completedSeason);
        $world = (new \Goal\Legacy\Modules\World\Persistence\WorldRepository($database))->get('domain-024-production');
        $rollover->prepareNext($database, $world, $completedSeason, SimulationDate::fromIsoString('2025-06-01'));
        $after = $playerService->repository($database)->get($player->id())->overallRating();
        self::assertGreaterThanOrEqual($before, $after);
        self::assertLessThanOrEqual($before + 1, $after);
        self::assertCount(1, array_filter((new PlayerDevelopmentRepository($database))->byPlayer($player->id()), static fn ($entry): bool => $entry->source() === 'season_lifecycle'));

        $rollover->prepareNext($database, $world, $completedSeason, SimulationDate::fromIsoString('2025-06-01'));
        self::assertSame($after, $playerService->repository($database)->get($player->id())->overallRating());

        $rollover->materializeNext($database, $world, $completedSeason, $next, SimulationDate::fromIsoString('2025-06-01'));
        $nextStored = (new SeasonRepository($database))->get($next->id())->activate();
        (new SeasonRepository($database))->save($nextStored);
        $rollover->activateNext($database, $nextStored);
        $nextFixtures = array_values(array_filter((new \Goal\Legacy\Modules\Match\Persistence\MatchRepository($database))->byClub(new ClubId('arsenal'), $next->id()), static fn ($match): bool => $match->status()->value === 'scheduled'));
        self::assertNotEmpty($nextFixtures);
        unset($database);
        $database = $store->openDatabase('domain-024-production');
        self::assertSame($after, $playerService->repository($database)->get($player->id())->overallRating());
        self::assertNotEmpty((new \Goal\Legacy\Modules\Match\MatchSelectionService($services->clubModule()->service()))->select($database, $nextFixtures[0]));
    }

    public function testPerformanceModifierIsBoundedAgeAwareAndPotentialSafe(): void
    {
        [$services, $database, $season] = $this->scenario('domain-024-policy');
        $playerService = $services->playerModule()->service();
        $young = $playerService->create($this->request('young-breakout', new PlayerAttributeSet(70, 70, 70, 70, 70, 70), 90));
        $youngSteady = $playerService->create($this->request('young-steady', new PlayerAttributeSet(70, 70, 70, 70, 70, 70), 90));
        $limited = $playerService->create($this->request('young-limited', new PlayerAttributeSet(70, 70, 70, 70, 70, 70), 90));
        $insufficient = $playerService->create($this->request('young-insufficient', new PlayerAttributeSet(70, 70, 70, 70, 70, 70), 90));
        $capped = $playerService->create($this->request('potential-capped', new PlayerAttributeSet(70, 70, 70, 70, 70, 70), 70));
        $old = $playerService->create(new PlayerCreationRequest('old-breakout', 'Development', 'Player', 'old-breakout', '1988-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 90, 'regular', 24024, new PlayerAttributeSet(70, 70, 70, 70, 70, 70)));
        foreach ([$young, $youngSteady, $limited, $insufficient, $capped, $old] as $candidate) {
            $playerService->repository($database)->save($candidate);
        }
        $nextDate = SimulationDate::fromIsoString('2025-08-01');
        $development = $playerService->developmentService();
        $apply = static function ($player, SeasonPerformanceAssessment $assessment) use ($database, $development, $nextDate): void {
            $processedSources = null;
            $knownStates = null;
            $repository = null;
            $database->transaction(fn (): mixed => $development->applySeasonLifecycleInTransaction($database, $player->id(), $nextDate, 'domain-024-policy-season', $player, $processedSources, $knownStates, $repository, $assessment));
        };
        $apply($young, new SeasonPerformanceAssessment('breakout', 90, [], 'test'));
        $apply($youngSteady, new SeasonPerformanceAssessment('steady', 30, [], 'test'));
        $apply($limited, new SeasonPerformanceAssessment('limited', 8, [], 'test'));
        $apply($insufficient, new SeasonPerformanceAssessment('insufficient_evidence', 0, [], 'test'));
        $apply($capped, new SeasonPerformanceAssessment('breakout', 90, [], 'test'));
        $apply($old, new SeasonPerformanceAssessment('breakout', 90, [], 'test'));

        $youngAfter = $playerService->repository($database)->get($young->id());
        $steadyAfter = $playerService->repository($database)->get($youngSteady->id());
        $limitedAfter = $playerService->repository($database)->get($limited->id());
        $insufficientAfter = $playerService->repository($database)->get($insufficient->id());
        $cappedAfter = $playerService->repository($database)->get($capped->id());
        $oldAfter = $playerService->repository($database)->get($old->id());
        self::assertGreaterThanOrEqual($steadyAfter->overallRating(), $youngAfter->overallRating());
        self::assertSame($youngSteady->overallRating(), $limitedAfter->overallRating());
        self::assertSame($youngSteady->overallRating(), $insufficientAfter->overallRating());
        self::assertLessThanOrEqual(1, $youngAfter->overallRating() - $young->overallRating());
        self::assertLessThanOrEqual($young->potential(), $youngAfter->overallRating());
        self::assertSame($capped->overallRating(), $cappedAfter->overallRating());
        self::assertGreaterThanOrEqual($oldAfter->overallRating() - $old->overallRating(), $youngAfter->overallRating() - $young->overallRating());
        self::assertSame($youngAfter->attributes()->toArray(), $playerService->repository($database)->get($young->id())->attributes()->toArray());
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season,3:SqliteSaveStore} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2026024, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $season, $store];
    }

    private function request(string $id, PlayerAttributeSet $attributes, int $potential): PlayerCreationRequest
    {
        return new PlayerCreationRequest($id, 'Development', 'Player', $id, '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', $potential, 'regular', 24024, $attributes);
    }
}
