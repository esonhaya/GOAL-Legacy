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
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Player\ClubExpectationService;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\SeasonPerformanceAssessment;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SeasonStatus;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use Goal\Legacy\Modules\World\Persistence\SeasonRepository;
use PHPUnit\Framework\TestCase;

final class Domain023Test extends TestCase
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

    public function testRoleMovementIsOneTierAndContextual(): void
    {
        [$services, $database, $season] = $this->scenario('domain-023-policy');
        $playerService = $services->playerModule()->service();
        $player = $playerService->create($this->request('policy-player', new PlayerAttributeSet(70, 70, 70, 70, 70, 70), 'CM'));
        $better = $playerService->create($this->request('policy-better', new PlayerAttributeSet(88, 88, 88, 88, 88, 88), 'CM'));
        $playerService->repository($database)->save($player);
        $playerService->repository($database)->save($better);
        $expectation = new ClubExpectationService($services->clubModule()->service());
        $membership = new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Prospect);
        $breakout = new SeasonPerformanceAssessment('breakout', 90, ['minutes_share' => 1.0], 'test');
        self::assertSame(SquadRole::Rotation, $expectation->roleAfterSeasonPerformance($player, $membership, $breakout, [$player, $better]));

        $regular = new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Regular);
        $limited = new SeasonPerformanceAssessment('limited', 4, ['minutes_share' => 0.05], 'test');
        self::assertSame(SquadRole::Rotation, $expectation->roleAfterSeasonPerformance($player, $regular, $limited, [$player, $better]));
        self::assertSame(SquadRole::Regular, $expectation->roleAfterSeasonPerformance($player, $regular, new SeasonPerformanceAssessment('steady', 30, ['minutes_share' => 0.3], 'test'), [$player, $better]));
        self::assertSame(SquadRole::Prospect, $expectation->roleAfterSeasonPerformance($player, $membership, new SeasonPerformanceAssessment('insufficient_evidence', 0, ['minutes_share' => 0.0], 'test'), [$player, $better]));
        self::assertSame($player->attributes()->toArray(), $playerService->repository($database)->get($player->id())->attributes()->toArray());
    }

    public function testRealMatchAssessmentFlowsThroughRolloverRoleAndSelection(): void
    {
        [$services, $database, $season, $store] = $this->scenario('domain-023-production');
        $playerService = $services->playerModule()->service();
        $player = $playerService->create($this->request('role-career-player', new PlayerAttributeSet(72, 72, 72, 72, 72, 72), 'CM'));
        $playerService->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('domain-023-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Prospect));
        $contractService = $services->contractModule()->service();
        $contractService->save($database, $contractService->create(new ContractCreationRequest(new ContractId('domain-023-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2026-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));
        $matches = array_values(array_filter($services->matchModule()->service()->generateFixtures($database, 'premier-league', $season->id()), static fn ($match): bool => $match->homeClubId()->value() === 'arsenal' || $match->awayClubId()->value() === 'arsenal'));
        self::assertNotEmpty($matches);
        $matchService = $services->matchModule()->service();
        $services->worldModule()->service()->advanceToDate($database, 'domain-023-production', $matches[0]->scheduledDate());
        $completed = $matchService->simulateDue($database, $matches[0]->scheduledDate());
        self::assertNotEmpty(array_filter($completed, fn ($match): bool => $matchService->playerSummary($database, $match->id(), $player->id()) !== null));
        $beforeRole = $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $season->id())[0]->role();

        $storedSeason = (new SeasonRepository($database))->get($season->id());
        $completedSeason = $storedSeason->status() === SeasonStatus::Active ? $storedSeason->complete() : $storedSeason;
        (new SeasonRepository($database))->save($completedSeason);
        $rollover = $services->worldModule()->service()->seasonRollover();
        self::assertNotNull($rollover);
        $next = $rollover->nextSeason($completedSeason);
        $rollover->prepareNext($database, (new \Goal\Legacy\Modules\World\Persistence\WorldRepository($database))->get('domain-023-production'), $completedSeason, SimulationDate::fromIsoString('2025-06-01'));
        $rollover->materializeNext($database, (new \Goal\Legacy\Modules\World\Persistence\WorldRepository($database))->get('domain-023-production'), $completedSeason, $next, SimulationDate::fromIsoString('2025-06-01'));
        $nextStored = (new SeasonRepository($database))->get($next->id())->activate();
        (new SeasonRepository($database))->save($nextStored);
        $rollover->activateNext($database, $nextStored);

        $nextMembership = $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $next->id())[0];
        self::assertSame(SquadRole::Rotation, $nextMembership->role());
        self::assertNotSame($beforeRole, $nextMembership->role());
        self::assertSame(72, $playerService->repository($database)->get($player->id())->overallRating());
        $nextFixtures = array_values(array_filter((new \Goal\Legacy\Modules\Match\Persistence\MatchRepository($database))->byClub(new ClubId('arsenal'), $next->id()), static fn ($match): bool => $match->status()->value === 'scheduled'));
        self::assertNotEmpty($nextFixtures);
        $selection = (new \Goal\Legacy\Modules\Match\MatchSelectionService($services->clubModule()->service()))->select($database, $nextFixtures[0]);
        self::assertNotEmpty(array_filter($selection, fn ($value): bool => $value->playerId()->value() === $player->id()->value()));

        $summary = (new \Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery($services->clubModule()->service()))->summary($database, $player->id(), SimulationDate::fromIsoString('2025-08-01'), $next->id());
        self::assertSame('rotation', $summary['squad_role']);
        unset($database);
        $database = $store->openDatabase('domain-023-production');
        $reloaded = $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $next->id())[0];
        self::assertSame(SquadRole::Rotation, $reloaded->role());
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season,3:SqliteSaveStore} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2026023, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $season, $store];
    }

    private function request(string $id, PlayerAttributeSet $attributes, string $position): PlayerCreationRequest
    {
        return new PlayerCreationRequest($id, 'Role', 'Player', $id, '2005-01-01', 'england', [], 'england', ['england'], 180, 75, $position, 90, 'regular', 9023, $attributes);
    }
}
