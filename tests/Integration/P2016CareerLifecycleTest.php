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
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Player\CareerLegacyService;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\PlayerCareerState;
use Goal\Legacy\Modules\Player\Persistence\CareerEventRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerOpportunityRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRetirementRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use Goal\Legacy\Modules\World\Persistence\SeasonRepository;
use PHPUnit\Framework\TestCase;

final class P2016CareerLifecycleTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            foreach (glob($root . '/*') ?: [] as $file) {
                if (is_file($file)) { unlink($file); }
            }
            if (is_dir($root)) { rmdir($root); }
        }
    }

    public function testControlledPlayerGetsOneBoundaryDecisionAndCanContinue(): void
    {
        [$services, $database, $next] = $this->scenario('p2016-continue');
        $players = $services->playerModule()->service();
        $player = $players->create($this->request('p2016-0', '1989-01-01', 82, 65));
        $players->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('p2016-continue-career'), $player->id(), SimulationDate::fromIsoString('2024-08-01')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), new SeasonId('season-2024-25'), SquadRole::Regular));
        $contracts = $services->contractModule()->service();
        $contracts->save($database, $contracts->create(new ContractCreationRequest(new ContractId('p2016-continue-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2026-06-30'), 400, SimulationDate::fromIsoString('2024-08-01'))));

        $lifecycle = $players->lifecycleService();
        $database->transaction(fn (): array => $lifecycle->processSeasonBoundaryInTransaction($database, $next));
        $open = (new CareerOpportunityRepository($database))->openForPlayer($player->id(), $next->startDate());
        self::assertCount(1, $open);
        self::assertSame('retirement', $open[0]->type()->value);
        self::assertSame(PlayerCareerState::Active, (new PlayerRepository($database))->get($player->id())->careerState());

        $lifecycle->resolveRetirementDecision($database, $open[0]->id(), 'continue-playing', $next->startDate());
        self::assertSame(PlayerCareerState::Active, (new PlayerRepository($database))->get($player->id())->careerState());
        self::assertCount(0, (new CareerOpportunityRepository($database))->openForPlayer($player->id(), $next->startDate()));

        $database->transaction(fn (): array => $lifecycle->processSeasonBoundaryInTransaction($database, $next));
        self::assertCount(0, (new CareerOpportunityRepository($database))->openForPlayer($player->id(), $next->startDate()));
    }

    public function testRetirementClosesPlayingCareerOnceAndPreservesReadModels(): void
    {
        [$services, $database, $next] = $this->scenario('p2016-retire');
        $players = $services->playerModule()->service();
        $player = $players->create($this->request('p2016-0', '1989-01-01', 82, 65));
        $players->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('p2016-retire-career'), $player->id(), SimulationDate::fromIsoString('2024-08-01')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), new SeasonId('season-2024-25'), SquadRole::Regular));
        $contracts = $services->contractModule()->service();
        $contracts->save($database, $contracts->create(new ContractCreationRequest(new ContractId('p2016-retire-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2026-06-30'), 400, SimulationDate::fromIsoString('2024-08-01'))));
        $database->transaction(fn (): array => $players->lifecycleService()->processSeasonBoundaryInTransaction($database, $next));
        $opportunity = (new CareerOpportunityRepository($database))->openForPlayer($player->id(), $next->startDate())[0];

        $players->lifecycleService()->resolveRetirementDecision($database, $opportunity->id(), 'retire', $next->startDate());
        $players->lifecycleService()->resolveRetirementDecision($database, $opportunity->id(), 'retire', $next->startDate());
        self::assertSame(PlayerCareerState::Retired, (new PlayerRepository($database))->get($player->id())->careerState());
        self::assertSame('terminated', $contracts->repository($database)->byPlayer($player->id())[0]->status()->value);
        self::assertNotNull((new PlayerRetirementRepository($database, false))->get($player->id()));
        self::assertCount(1, (new CareerEventRepository($database))->resolvedForPlayer($player->id(), 50));
        self::assertCount(0, (new CareerOpportunityRepository($database))->openForPlayer($player->id(), $next->startDate()));
        self::assertSame('retired', (new PlayerRepository($database))->get($player->id())->careerState()->value);
        self::assertNotEmpty($players->pulseService()->feed($database, $player->id(), 10));
        self::assertSame([], $players->lifecycleService()->integrity($database));
        $legacy = (new CareerLegacyService($services->clubModule()->service(), $services->nationalTeams(), $services->internationalCompetitions(), $players->socialService()))->summary($database, $player->id()->value());
        self::assertNotNull($legacy['retirement']);
        self::assertSame('season-2025-26', $legacy['retirement']['retirement_season_id']);
    }

    public function testYoungPlayerCannotEnterRetirementPathFromLowEvidence(): void
    {
        [$services, $database, $next] = $this->scenario('p2016-young');
        $players = $services->playerModule()->service();
        $player = $players->create($this->request('p2016-young', '2007-01-01', 95, 55));
        $players->repository($database)->save($player);
        $assessment = $players->lifecycleService()->retirementAssessment($database, $player, $next->startDate());
        self::assertFalse($assessment['eligible']);
        self::assertSame('youth', $assessment['phase']);
        self::assertSame(PlayerCareerState::Active, $players->repository($database)->get($player->id())->careerState());
    }

    private function request(string $id, string $birthDate, int $potential, int $overall): PlayerCreationRequest
    {
        return new PlayerCreationRequest($id, 'Lifecycle', 'Player', $id, $birthDate, 'england', [], 'england', ['england'], 180, 75, 'CM', $potential, 'regular', 16016, new PlayerAttributeSet($overall, $overall, $overall, $overall, $overall, $overall));
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $next = new Season(new SeasonId('season-2025-26'), '2025/26', SimulationDate::fromIsoString('2025-08-01'), SimulationDate::fromIsoString('2026-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 16016, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);
        (new SeasonRepository($database))->save($next);

        return [$services, $database, $next];
    }
}
