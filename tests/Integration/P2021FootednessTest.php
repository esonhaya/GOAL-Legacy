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
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\PlayerFoot;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Domain\TrainingFocus;
use Goal\Legacy\Modules\Player\Domain\TrainingRequest;
use Goal\Legacy\Modules\Player\Domain\WeakFootTier;
use Goal\Legacy\Modules\Player\PlayerFootService;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class P2021FootednessTest extends TestCase
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

    public function testFootIdentityIsDeterministicAndRoundTrips(): void
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $request = new PlayerCreationRequest('p2021-identity', 'Foot', 'Identity', null, '2005-01-01', 'england', [], null, null, 180, 75, 'LB', 90, 'regular', 2021, new PlayerAttributeSet(70, 70, 70, 70, 70, 70), PlayerFoot::Left, WeakFootTier::Comfortable);
        $player = $services->playerModule()->service()->create($request);
        self::assertSame(PlayerFoot::Left, $player->preferredFoot());
        self::assertSame(WeakFootTier::Comfortable, $player->weakFoot());

        $same = $services->playerModule()->service()->create($request);
        self::assertSame($player->toArray(), $same->toArray());
    }

    public function testWeakFootTrainingUsesExistingCadenceAndDoesNotFarmPositionFocus(): void
    {
        [$services, $database, $store] = $this->scenario('p2021-training');
        $players = $services->playerModule()->service();
        $player = $players->create(new PlayerCreationRequest('p2021-training-player', 'Foot', 'Training', null, '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 90, 'regular', 2021, new PlayerAttributeSet(70, 70, 70, 70, 70, 70), PlayerFoot::Right, WeakFootTier::Limited));
        $players->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('p2021-training-career'), $player->id(), SimulationDate::fromIsoString('2024-08-01')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), new SeasonId('season-2024-25'), SquadRole::Regular));
        $date = SimulationDate::fromIsoString('2024-08-01');
        $players->positionDevelopmentService()->setFocus($database, $player->id(), PlayerPosition::AttackingMidfielder, $date);
        $players->trainingService()->complete($database, new TrainingRequest($player->id(), 'p2021-weak-foot-block', TrainingFocus::WeakFoot, $date, $date->addDays(180), 'normal'));

        $summary = $players->repository($database)->get($player->id());
        self::assertSame(WeakFootTier::Comfortable, $summary->weakFoot());
        self::assertSame(0, $players->positionDevelopmentService()->context($database, $player->id(), $date->addDays(180))['progress']);
        $reloaded = $store->openDatabase('p2021-training');
        self::assertSame($summary->toArray(), $players->repository($reloaded)->get($player->id())->toArray());
    }

    public function testFootContextIsBoundedAndActionChoiceIsStable(): void
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $left = $services->playerModule()->service()->create(new PlayerCreationRequest('p2021-left', 'Left', 'Wide', null, '2005-01-01', 'england', [], null, null, 180, 75, 'LB', 90, 'regular', 1, new PlayerAttributeSet(70, 70, 70, 70, 70, 70), PlayerFoot::Left, WeakFootTier::Limited));
        $right = $services->playerModule()->service()->create(new PlayerCreationRequest('p2021-right', 'Right', 'Wide', null, '2005-01-01', 'england', [], null, null, 180, 75, 'LB', 90, 'regular', 1, new PlayerAttributeSet(70, 70, 70, 70, 70, 70), PlayerFoot::Right, WeakFootTier::Limited));
        $foot = new PlayerFootService();
        self::assertSame(2, $foot->positionSuitability($left, PlayerPosition::LeftBack));
        self::assertSame(-2, $foot->positionSuitability($right, PlayerPosition::LeftBack));
        self::assertSame($foot->actionFoot($left, 'match-1|goal|40'), $foot->actionFoot($left, 'match-1|goal|40'));
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:\Goal\Legacy\Core\Persistence\SqliteSaveStore} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2021021, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $services->nationModule()->service()->loadSelected()), array_map(static fn ($competition): string => $competition->id()->value(), $services->competitionModule()->service()->loadSelected()), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $store];
    }
}
