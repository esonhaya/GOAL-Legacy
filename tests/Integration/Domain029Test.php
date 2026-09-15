<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\MatchSelectionService;
use Goal\Legacy\Modules\Match\MatchSimulationService;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class Domain029Test extends TestCase
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

    public function testRatingUsesTheProductionMatchReadPathAndSurvivesReload(): void
    {
        [$services, $database, $season, $store] = $this->scenario('domain-029-production');
        $services->playerModule()->service()->populationService()->populate($database, $season, 29029);
        $matchService = $services->matchModule()->service();
        $matches = $matchService->generateFixtures($database, 'premier-league', $season->id());
        $direct = new MatchSimulationService($services->clubModule()->service(), new MatchSelectionService($services->clubModule()->service()));
        self::assertSame($this->simulationArray($direct->simulate($database, $matches[0])), $this->simulationArray($direct->simulate($database, $matches[0])));

        $completed = $matchService->simulate($database, $matches[0]->id());
        $players = new PlayerRepository($database);
        $ranges = ['GK' => [], 'DEF' => [], 'MID' => [], 'ATT' => []];
        foreach ($matchService->statRepository($database)->byMatch($completed->id()) as $stat) {
            $summary = $matchService->playerSummary($database, $completed->id(), $stat->playerId());
            self::assertNotNull($summary);
            self::assertIsFloat($summary['rating']);
            self::assertGreaterThanOrEqual(0.0, $summary['rating']);
            self::assertLessThanOrEqual(10.0, $summary['rating']);
            self::assertSame($summary['rating'], $matchService->playerSummary($database, $completed->id(), $stat->playerId())['rating']);
            $group = match ($players->get($stat->playerId())->primaryPosition()->value) { 'GK' => 'GK', 'CB', 'LB', 'RB' => 'DEF', 'DM', 'CM', 'AM' => 'MID', default => 'ATT' };
            $ranges[$group][] = $summary['rating'];
        }
        foreach ($ranges as $values) { self::assertNotEmpty($values); }
        $unusedBench = array_values(array_filter($matchService->selectionRepository($database)->byMatch($completed->id()), fn ($selection): bool => $selection->status()->value === 'bench' && $matchService->playerSummary($database, $completed->id(), $selection->playerId()) === null));
        self::assertNotEmpty($unusedBench);

        $sample = $matchService->statRepository($database)->byMatch($completed->id())[0];
        $before = $matchService->playerSummary($database, $completed->id(), $sample->playerId())['rating'];
        unset($database);
        $database = $store->openDatabase('domain-029-production');
        self::assertSame($before, $matchService->playerSummary($database, $completed->id(), $sample->playerId())['rating']);
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season,3:SqliteSaveStore} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), 'Domain 029', 29029, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $root = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(4)); mkdir($root, 0775, true); $this->roots[] = $root;
        $store = new SqliteSaveStore($root, new JsonSerializer()); $store->create(SaveMetadata::create($id, $world->label(), $world->currentTime(), new DateTimeImmutable('@0'))); $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $season, $store];
    }

    private function simulationArray(object $simulation): array
    {
        return ['result' => $simulation->result()->toArray(), 'stats' => array_map(static fn ($stat): array => $stat->toArray(), $simulation->playerStats()), 'highlights' => array_map(static fn ($highlight): array => $highlight->toArray(), $simulation->highlights())];
    }
}
