<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\Match\Domain\SimulationFidelity;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class P2014MatchdayStoryTest extends TestCase
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

    public function testControlledStoryReconcilesCanonicalFactsAndSurvivesReload(): void
    {
        [$services, $database, $season, $store] = $this->scenario('p2014-story');
        $services->playerModule()->service()->populationService()->populate($database, $season, 2014);
        $matches = $services->matchModule()->service()->generateFixtures($database, 'premier-league', $season->id());
        $matchService = $services->matchModule()->service();
        $completed = $matchService->simulate($database, $matches[0]->id(), SimulationFidelity::Player);
        $stats = new PlayerMatchStatRepository($database);
        $playerStat = $stats->byMatch($completed->id())[0];

        $story = $matchService->playerStory($database, $completed->id(), $playerStat->playerId());
        self::assertContains($story['participation_state'], ['starter', 'substitute']);
        self::assertContains($story['participation_label'], ['Starting XI', 'Substitute appearance']);
        self::assertSame($playerStat->minutes(), $story['minutes']);
        self::assertSame($story['rating'], $story['rating_explanation']['rating']);
        self::assertIsArray($story['rating_explanation']['positive']);
        self::assertIsArray($story['player_highlight_facts']);
        self::assertSame($story, $matchService->playerStory($database, $completed->id(), $playerStat->playerId()));
        self::assertTrue($matchService->integrity($database, $completed->id())['valid']);

        $goalEvents = array_values(array_filter($story['timeline'], static fn (array $event): bool => $event['type'] === 'goal'));
        self::assertCount($completed->result()->homeGoals() + $completed->result()->awayGoals(), $goalEvents);
        if ($goalEvents !== []) {
            $last = $goalEvents[count($goalEvents) - 1];
            self::assertSame($completed->result()->homeGoals(), $last['score_after']['home']);
            self::assertSame($completed->result()->awayGoals(), $last['score_after']['away']);
        }

        unset($database);
        $reloaded = $store->openDatabase('p2014-story');
        self::assertSame($story, $matchService->playerStory($reloaded, $completed->id(), $playerStat->playerId()));
    }

    public function testUnusedBenchHasAHumanStateAndStoryReadDoesNotExpandWorldDetail(): void
    {
        [$services, $database, $season] = $this->scenario('p2014-boundary');
        $services->playerModule()->service()->populationService()->populate($database, $season, 2015);
        $matches = $services->matchModule()->service()->generateFixtures($database, 'premier-league', $season->id());
        $matchService = $services->matchModule()->service();
        $completed = $matchService->simulate($database, $matches[0]->id(), SimulationFidelity::Player);
        $selections = (new MatchSelectionRepository($database))->byMatch($completed->id());
        $unused = array_values(array_filter($selections, static fn ($selection): bool => $selection->status()->value === 'bench' && (new PlayerMatchStatRepository($database))->byMatch($completed->id()) !== [] && !array_filter((new PlayerMatchStatRepository($database))->byMatch($completed->id()), static fn ($stat): bool => $stat->playerId()->value() === $selection->playerId()->value())))[0] ?? null;
        self::assertNotNull($unused);
        $unusedStory = $matchService->playerStory($database, $completed->id(), $unused->playerId());
        self::assertSame('unused_substitute', $unusedStory['participation_state']);
        self::assertSame('Unused substitute', $unusedStory['participation_label']);
        self::assertNull($unusedStory['rating']);
        self::assertSame(0, $unusedStory['minutes']);

        $worldMatch = $matches[1];
        $matchService->simulate($database, $worldMatch->id(), SimulationFidelity::World);
        $statRowsBefore = (int) $database->connection()->query('SELECT COUNT(*) FROM match_player_stats WHERE match_id = ' . $database->connection()->quote($worldMatch->id()->value()))->fetchColumn();
        self::assertSame(0, $statRowsBefore);
        $matchService->playerStory($database, $worldMatch->id(), $unused->playerId());
        $statRowsAfter = (int) $database->connection()->query('SELECT COUNT(*) FROM match_player_stats WHERE match_id = ' . $database->connection()->quote($worldMatch->id()->value()))->fetchColumn();
        self::assertSame(0, $statRowsAfter);
    }

    /** @return array{0:object,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season,3:SqliteSaveStore} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2014, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $root = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(4)); mkdir($root, 0775, true); $this->roots[] = $root;
        $store = new SqliteSaveStore($root, new JsonSerializer()); $store->create(SaveMetadata::create($id, $world->label(), $world->currentTime(), new DateTimeImmutable('@0'))); $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $season, $store];
    }
}
