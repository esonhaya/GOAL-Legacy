<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\Player\Domain\CareerPriority;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\TrainingIntensity;
use Goal\Legacy\Modules\Player\Domain\TrainingRequest;
use Goal\Legacy\Modules\Player\Persistence\PlayerAvailabilityRepository;
use Goal\Legacy\Modules\Player\PlayerAvailabilityService;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class P2017ReadinessTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            foreach (glob($root . '/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
            if (is_dir($root)) { rmdir($root); }
        }
    }

    public function testPriorityDrivenTrainingChangesLoadAndIsIdempotent(): void
    {
        [$services, $database, , $store] = $this->scenario('p2017-training');
        $playerService = $services->playerModule()->service();
        $player = $playerService->create(new PlayerCreationRequest('p2017-training-player', 'Ready', 'Player', 'Ready Player', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 90, 'regular', 2017, new PlayerAttributeSet(55, 55, 55, 55, 55, 55)));
        $playerService->repository($database)->save($player);
        $experience = $playerService->careerExperienceService();
        $start = SimulationDate::fromIsoString('2024-08-01');
        $end = SimulationDate::fromIsoString('2024-08-15');

        $experience->setPriority($database, $player->id(), CareerPriority::Development, $start);
        $first = $experience->prepareTraining($database, $player->id(), 'p2017-fixture-1', $start, $end);
        $second = $experience->prepareTraining($database, $player->id(), 'p2017-fixture-1', $start, $end);

        self::assertSame('intense', $first['intensity']);
        self::assertTrue($first['applied']);
        self::assertFalse($second['applied']);
        self::assertSame(28, (new PlayerAvailabilityRepository($database))->state($player->id())['fatigue']);

        $experience->setPriority($database, $player->id(), CareerPriority::Recovery, $end);
        $recovery = $experience->prepareTraining($database, $player->id(), 'p2017-fixture-2', $end, SimulationDate::fromIsoString('2024-08-29'));
        self::assertSame('light', $recovery['intensity']);
        self::assertLessThan(TrainingIntensity::Intense->loadPerWeek() * 2, (new PlayerAvailabilityRepository($database))->state($player->id())['fatigue']);
        unset($database);
        $database = $store->openDatabase('p2017-training');
        self::assertSame('fresh', (new PlayerAvailabilityService())->assess($database, $player->id(), SimulationDate::fromIsoString('2024-08-29'))->readinessLabel());
    }

    public function testRestLowersWorkloadWithoutAReadSideWrite(): void
    {
        [$services, $database] = $this->scenario('p2017-recovery');
        $player = $services->playerModule()->service()->create(new PlayerCreationRequest('p2017-recovery-player', 'Rest', 'Player', 'Rest Player', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 90, 'regular', 2018, new PlayerAttributeSet(55, 55, 55, 55, 55, 55)));
        $services->playerModule()->service()->repository($database)->save($player);
        $availability = new PlayerAvailabilityService();
        $repository = new PlayerAvailabilityRepository($database);
        $start = SimulationDate::fromIsoString('2024-08-01');
        $database->transaction(fn () => $repository->saveStateInTransaction($player->id(), 60, $start, 1));
        $before = $repository->state($player->id());

        $assessment = $availability->assess($database, $player->id(), SimulationDate::fromIsoString('2024-08-02'));
        $after = $repository->state($player->id());

        self::assertSame('managed', $assessment->readinessLabel());
        self::assertSame(53, $assessment->fatigue());
        self::assertSame(60, $after['fatigue']);
        self::assertSame($before['last_date']->toIsoString(), $after['last_date']->toIsoString(), 'Recovery is a read projection and must not write on page access.');
        self::assertSame($before['revision'], $after['revision']);
    }

    public function testIntenseTrainingCanCreateOnlyAStableBoundedTrainingInjury(): void
    {
        [$services, $database] = $this->scenario('p2017-training-injury');
        $player = $services->playerModule()->service()->create(new PlayerCreationRequest('p2017-injury-player', 'Risk', 'Player', 'Risk Player', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 90, 'regular', 2019, new PlayerAttributeSet(55, 55, 55, 55, 55, 55)));
        $services->playerModule()->service()->repository($database)->save($player);
        $availability = new PlayerAvailabilityService();
        $repository = new PlayerAvailabilityRepository($database);
        $date = SimulationDate::fromIsoString('2024-08-01');
        $database->transaction(fn () => $repository->saveStateInTransaction($player->id(), 80, $date, 1));
        $source = null;
        for ($index = 0; $index < 250 && $source === null; ++$index) {
            $unit = hexdec(substr(hash('sha256', 'training-risk|p2017-intense-' . $index), 0, 12)) / 281474976710655;
            if ($unit < 0.05) { $source = 'p2017-intense-' . $index; }
        }
        self::assertNotNull($source);
        $changes = $database->transaction(fn () => $availability->applyTrainingInTransaction($database, $player->id(), (string) $source, $date, 1, TrainingIntensity::Intense));

        self::assertCount(1, $changes);
        self::assertSame('player.injured', $changes[0]['event']);
        self::assertCount(1, $repository->byPlayer($player->id()));
        self::assertTrue($availability->assess($database, $player->id(), $date)->isUnavailable());
        self::assertSame([], $database->transaction(fn () => $availability->applyTrainingInTransaction($database, $player->id(), (string) $source, $date, 1, TrainingIntensity::Intense)), 'The source key must make training injury processing idempotent.');
    }

    public function testCareerSummaryExposesReadinessWithoutCreatingHistoryRows(): void
    {
        [$services, $database, $season] = $this->scenario('p2017-summary');
        $player = $services->playerModule()->service()->create(new PlayerCreationRequest('p2017-summary-player', 'Summary', 'Player', 'Summary Player', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 90, 'regular', 2020, new PlayerAttributeSet(55, 55, 55, 55, 55, 55)));
        $services->playerModule()->service()->repository($database)->save($player);
        $query = new PlayerCareerProgressionQuery($services->clubModule()->service());
        new PlayerAvailabilityRepository($database);
        $before = (int) $database->connection()->query("SELECT COUNT(*) FROM player_availability_state")->fetchColumn();
        $summary = $query->summary($database, $player->id(), SimulationDate::fromIsoString('2024-08-01'), $season->id());
        $after = (int) $database->connection()->query("SELECT COUNT(*) FROM player_availability_state")->fetchColumn();

        self::assertSame('fresh', $summary['readiness']['label']);
        self::assertSame('normal', $summary['training_intensity']);
        self::assertSame($before, $after);
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season,3:SqliteSaveStore} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2026017, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $season, $store];
    }
}
