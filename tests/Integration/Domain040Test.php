<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Player\CareerExperienceService;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\CareerPriority;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\TrainingFocus;
use Goal\Legacy\Modules\Player\Persistence\CareerEventRepository;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class Domain040Test extends TestCase
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

    public function testPriorityAndFocusUseExistingPersistentOwners(): void
    {
        [$services, $database, $season] = $this->scenario('domain-040-priority');
        $player = $services->playerModule()->service()->create(new PlayerCreationRequest('domain-040-player', 'Career', 'Player', 'Domain 040 Player', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 90, 'regular', 40, new PlayerAttributeSet(50, 50, 50, 50, 50, 50)));
        $services->playerModule()->service()->repository($database)->save($player);
        $experience = $services->playerModule()->service()->careerExperienceService();
        $date = SimulationDate::fromIsoString('2024-08-01');
        self::assertSame(CareerPriority::Balanced, $experience->priority($database, $player->id()));
        self::assertSame(CareerPriority::Development, $experience->setPriority($database, $player->id(), CareerPriority::Development, $date));
        self::assertSame(TrainingFocus::Passing, $experience->setTrainingFocus($database, $player->id(), TrainingFocus::Passing, $date));
        $summary = (new PlayerCareerProgressionQuery($services->clubModule()->service())->summary($database, $player->id(), $date, $season->id()));
        self::assertSame('development', $summary['priority']);
        self::assertSame('passing', $summary['training_focus']);
    }

    public function testCareerEventIsDeterministicPersistentAndResolvedOnce(): void
    {
        [$services, $database, $season] = $this->scenario('domain-040-event');
        $player = $services->playerModule()->service()->create(new PlayerCreationRequest('domain-040-event-player', 'Career', 'Player', 'Event Player', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 90, 'regular', 41, new PlayerAttributeSet(50, 50, 50, 50, 50, 50)));
        $services->playerModule()->service()->repository($database)->save($player);
        $fixture = $services->matchModule()->service()->generateFixtures($database, 'premier-league', $season->id())[0];
        (new MatchRepository($database))->save($fixture->complete(new MatchResult(1, 0)));
        $summary = ['current_club' => ['id' => $fixture->homeClubId()->value(), 'name' => 'Arsenal']];
        $date = $fixture->scheduledDate();
        $experience = new CareerExperienceService($services->playerModule()->service()->developmentService(), $services->playerModule()->service()->trainingService());
        $first = $experience->ensureEvent($database, $player->id(), $season->id(), $date, $summary);
        self::assertNotNull($first);
        $second = $experience->ensureEvent($database, $player->id(), $season->id(), $date, $summary);
        self::assertNotNull($second);
        self::assertSame($first->id(), $second->id());
        self::assertSame($first->toArray(), $second->toArray());
        self::assertCount(1, $experience->pendingEvent($database, $player->id()) === null ? [] : [$experience->pendingEvent($database, $player->id())]);
        $resolved = $experience->resolve($database, $first->id(), 1, $date);
        self::assertSame('resolved', $resolved->status()->value);
        self::assertSame($first->choices()[0]['id'], $resolved->selectedChoice());
        self::assertCount(1, $experience->lifeHistory($database, $player->id()));
        $replayed = $experience->resolve($database, $first->id(), 2, $date);
        self::assertSame($resolved->selectedChoice(), $replayed->selectedChoice());
        self::assertCount(1, (new CareerEventRepository($database))->resolvedForPlayer($player->id()));
        self::assertNull($experience->ensureEvent($database, $player->id(), $season->id(), $date, $summary));
    }

    /** @return array{0: \Goal\Legacy\Core\Bootstrap\CoreServices, 1: \Goal\Legacy\Core\Persistence\DatabaseInterface, 2: Season} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2040, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $season];
    }
}
