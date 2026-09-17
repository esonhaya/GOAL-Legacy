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
use Goal\Legacy\Modules\Match\Domain\SimulationFidelity;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\PlayerSeasonPerformanceService;
use Goal\Legacy\Modules\Player\PlayerCareerStatisticsService;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class PlayerCentricSimulationTest extends TestCase
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

    public function testWorldFidelityKeepsCanonicalOutcomeAndUsesCompactEvidence(): void
    {
        [$services, $database, $season] = $this->scenario('p2-003-world');
        $services->playerModule()->service()->populationService()->populate($database, $season, 3003);
        $matchService = $services->matchModule()->service();
        $fixture = array_values(array_filter(
            $matchService->generateFixtures($database, 'premier-league', $season->id()),
            static fn ($match): bool => !in_array($match->homeClubId()->value(), ['arsenal']) && !in_array($match->awayClubId()->value(), ['arsenal']),
        ))[0];

        $completed = $matchService->simulate($database, $fixture->id(), SimulationFidelity::World);

        self::assertNotNull($completed->result());
        self::assertSame([], (new PlayerMatchStatRepository($database))->byMatch($fixture->id()));
        self::assertSame([], (new MatchSelectionRepository($database))->byMatch($fixture->id()));
        self::assertGreaterThan(0, (int) $database->connection()->query('SELECT COUNT(*) FROM player_season_statistics')->fetchColumn());
        self::assertSame(0, (int) $database->connection()->query('SELECT COUNT(*) FROM career_match_evaluations')->fetchColumn());
        self::assertSame(0, (int) $database->connection()->query('SELECT COUNT(*) FROM player_development_history')->fetchColumn());
    }

    public function testControlledFixtureRetainsFullEvidencePath(): void
    {
        [$services, $database, $season] = $this->scenario('p2-003-player');
        $services->playerModule()->service()->populationService()->populate($database, $season, 3003);
        $playerService = $services->playerModule()->service();
        $player = $playerService->create(new PlayerCreationRequest(
            'p2-003-controlled', 'Controlled', 'Player', 'Controlled Player', '2005-01-01',
            'england', [], 'england', ['england'], 180, 75, 'CM', 99, 'regular', 33,
            new PlayerAttributeSet(70, 70, 70, 70, 70, 70),
        ));
        $playerService->repository($database)->save($player);
        $services->clubModule()->service()->squadRepository($database)->save(new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Regular));
        $services->contractModule()->service()->save($database, $services->contractModule()->service()->create(new ContractCreationRequest(new ContractId('p2-003-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2025-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));
        (new CareerPlayerRepository($database))->save(new CareerPlayerReference(new CareerId('p2-003-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')));
        $fixture = array_values(array_filter($services->matchModule()->service()->generateFixtures($database, 'premier-league', $season->id()), static fn ($match): bool => $match->homeClubId()->value() === 'arsenal' || $match->awayClubId()->value() === 'arsenal'))[0];

        $services->matchModule()->service()->simulate($database, $fixture->id(), SimulationFidelity::Player);

        self::assertGreaterThanOrEqual(25, count((new MatchSelectionRepository($database))->byMatch($fixture->id())));
        self::assertNotEmpty((new PlayerMatchStatRepository($database))->byMatch($fixture->id()));
    }

    public function testWorldAggregateFeedsSeasonPerformanceAfterReload(): void
    {
        [$services, $database, $season, $store, $root] = $this->scenario('p2-003-aggregate');
        $services->playerModule()->service()->populationService()->populate($database, $season, 3004);
        $matchService = $services->matchModule()->service();
        $fixture = $matchService->generateFixtures($database, 'premier-league', $season->id())[0];
        $matchService->simulate($database, $fixture->id(), SimulationFidelity::World);
        $row = $database->connection()->query('SELECT player_id FROM player_season_statistics ORDER BY player_id ASC LIMIT 1')->fetchColumn();
        self::assertIsString($row);
        unset($database);
        $database = $store->openDatabase('p2-003-aggregate');
        $assessment = (new PlayerSeasonPerformanceService())->assess($database, $row, $season->id());
        self::assertSame(1, $assessment->statistics()['appearances']);
        self::assertGreaterThan(0, $assessment->statistics()['rated_appearances']);
        $line = (new PlayerCareerStatisticsService())->seasonDetailed($database, $row, $season->id());
        self::assertSame(1, $line['appearances']);
        self::assertGreaterThanOrEqual(0, $line['minutes']);
        self::assertFileExists($root . '/p2-003-aggregate.sqlite');
    }

    /** @return array{0:mixed,1:mixed,2:Season,3:SqliteSaveStore,4:string} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 3003, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $root = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($root, 0775, true);
        $this->roots[] = $root;
        $store = new SqliteSaveStore($root, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $season, $store, $root];
    }
}
