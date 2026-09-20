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
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Domain\TrainingFocus;
use Goal\Legacy\Modules\Player\Domain\TrainingRequest;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class P2019PositionDevelopmentTest extends TestCase
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

    public function testTrainingProgressCompletesAndPrimaryChangeIsSaveSafe(): void
    {
        [$services, $database, $store] = $this->scenario('p2019-position');
        $playerService = $services->playerModule()->service();
        $player = $playerService->create(new PlayerCreationRequest('p2019-position-player', 'Position', 'Player', 'Position Player', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 90, 'regular', 2019, new PlayerAttributeSet(70, 70, 75, 75, 65, 70)));
        $playerService->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('p2019-position-career'), $player->id(), SimulationDate::fromIsoString('2024-08-01')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), new SeasonId('season-2024-25'), SquadRole::Regular));
        $positions = $playerService->positionDevelopmentService();
        $date = SimulationDate::fromIsoString('2024-08-01');
        $focused = $positions->setFocus($database, $player->id(), PlayerPosition::AttackingMidfielder, $date);
        self::assertSame('AM', $focused['developing_position']);
        self::assertSame(0, $focused['progress']);

        for ($week = 0; $week < 15; ++$week) {
            $start = $date->addDays($week * 8);
            $end = $start->addDays(7);
            $playerService->trainingService()->complete($database, new TrainingRequest($player->id(), 'p2019-position-' . $week, TrainingFocus::Balanced, $start, $end, 'light'));
        }
        $progress = $positions->context($database, $player->id(), SimulationDate::fromIsoString('2024-09-01'));
        self::assertSame(100, $progress['familiarity']['AM']['progress']);
        self::assertContains('AM', $progress['secondary_positions']);
        self::assertNull($progress['developing_position']);

        $changed = $positions->changePrimary($database, $player->id(), 'AM', SimulationDate::fromIsoString('2024-09-01'));
        self::assertSame('AM', $changed['primary_position']);
        self::assertSame('AM', $playerService->repository($database)->get($player->id())->primaryPosition()->value);
        self::assertCount(1, $positions->history($database, $player->id()));
        $reloaded = $store->openDatabase('p2019-position');
        self::assertSame($changed, $positions->context($reloaded, $player->id(), SimulationDate::fromIsoString('2024-09-01')));
        $positions->changePrimary($reloaded, $player->id(), 'AM', SimulationDate::fromIsoString('2024-09-01'));
        self::assertCount(1, $positions->history($reloaded, $player->id()));
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:\Goal\Legacy\Core\Persistence\SqliteSaveStore} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2026019, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
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
