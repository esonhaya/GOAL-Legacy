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
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\Domain\MatchSimulation;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\MatchSelectionService;
use Goal\Legacy\Modules\Match\MatchSimulationService;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class Domain028Test extends TestCase
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

    public function testProductionPathReconcilesShotsSavesCleanSheetsAndReloads(): void
    {
        [$services, $database, $season, $store] = $this->scenario('domain-028-production');
        $services->playerModule()->service()->populationService()->populate($database, $season, 28028);
        $matchService = $services->matchModule()->service();
        $matches = array_values(array_filter($matchService->generateFixtures($database, 'premier-league', $season->id()), static fn (GameMatch $match): bool => $match->homeClubId()->value() === 'arsenal' || $match->awayClubId()->value() === 'arsenal'));
        self::assertNotEmpty($matches);

        $direct = new MatchSimulationService($services->clubModule()->service(), new MatchSelectionService($services->clubModule()->service()));
        $first = $direct->simulate($database, $matches[0]);
        $second = $direct->simulate($database, $matches[0]);
        self::assertSame($this->simulationArray($first), $this->simulationArray($second));

        $players = new PlayerRepository($database);
        $statsRepository = new PlayerMatchStatRepository($database);
        $goalMatch = null;
        $before = null;
        $savesFound = false;
        $cleanSheetsFound = false;
        $zeroZeroFound = false;
        $simulated = 0;
        foreach (array_slice($matches, 0, 24) as $scheduled) {
            $completed = $matchService->simulate($database, $scheduled->id());
            ++$simulated;
            $stats = $statsRepository->byMatch($completed->id());
            $homeStats = array_values(array_filter($stats, static fn ($stat): bool => $stat->clubId()->value() === $completed->homeClubId()->value()));
            $awayStats = array_values(array_filter($stats, static fn ($stat): bool => $stat->clubId()->value() === $completed->awayClubId()->value()));
            self::assertSame($completed->result()?->homeGoals(), array_sum(array_map(static fn ($stat): int => $stat->goals(), $homeStats)));
            self::assertSame($completed->result()?->awayGoals(), array_sum(array_map(static fn ($stat): int => $stat->goals(), $awayStats)));
            self::assertGreaterThanOrEqual(array_sum(array_map(static fn ($stat): int => $stat->shotsOnTarget(), $homeStats)), array_sum(array_map(static fn ($stat): int => $stat->shots(), $homeStats)));
            self::assertGreaterThanOrEqual(array_sum(array_map(static fn ($stat): int => $stat->goals(), $homeStats)), array_sum(array_map(static fn ($stat): int => $stat->shotsOnTarget(), $homeStats)));
            self::assertGreaterThanOrEqual(array_sum(array_map(static fn ($stat): int => $stat->shotsOnTarget(), $awayStats)), array_sum(array_map(static fn ($stat): int => $stat->shots(), $awayStats)));
            self::assertGreaterThanOrEqual(array_sum(array_map(static fn ($stat): int => $stat->goals(), $awayStats)), array_sum(array_map(static fn ($stat): int => $stat->shotsOnTarget(), $awayStats)));
            foreach ($stats as $stat) {
                self::assertGreaterThanOrEqual(0, $stat->shots());
                self::assertGreaterThanOrEqual(0, $stat->shotsOnTarget());
                self::assertGreaterThanOrEqual(0, $stat->saves());
                self::assertGreaterThanOrEqual(0, $stat->cleanSheets());
                $player = $players->get($stat->playerId());
                if ($stat->saves() > 0) { self::assertSame('GK', $player->primaryPosition()->value); $savesFound = true; }
                if ($stat->cleanSheets() > 0) { self::assertContains($player->primaryPosition()->value, ['GK', 'CB', 'LB', 'RB']); self::assertSame(0, $stat->clubId()->value() === $completed->homeClubId()->value() ? $completed->result()?->awayGoals() : $completed->result()?->homeGoals()); $cleanSheetsFound = true; }
            }
            $homeKeeper = array_sum(array_map(static fn ($stat): int => $stat->saves(), array_filter($awayStats, static fn ($stat): bool => $players->get($stat->playerId())->primaryPosition()->value === 'GK')));
            $awayKeeper = array_sum(array_map(static fn ($stat): int => $stat->saves(), array_filter($homeStats, static fn ($stat): bool => $players->get($stat->playerId())->primaryPosition()->value === 'GK')));
            self::assertSame(array_sum(array_map(static fn ($stat): int => $stat->shotsOnTarget(), $homeStats)) - ($completed->result()?->homeGoals() ?? 0), $homeKeeper);
            self::assertSame(array_sum(array_map(static fn ($stat): int => $stat->shotsOnTarget(), $awayStats)) - ($completed->result()?->awayGoals() ?? 0), $awayKeeper);
            if (($completed->result()?->homeGoals() ?? 0) === 0 && ($completed->result()?->awayGoals() ?? 0) === 0) { $zeroZeroFound = true; }
            if ($goalMatch === null && (($completed->result()?->homeGoals() ?? 0) + ($completed->result()?->awayGoals() ?? 0)) > 0) { $goalMatch = $completed; $before = array_map(static fn ($stat): array => $stat->toArray(), $stats); }
            if ($savesFound && $cleanSheetsFound && $zeroZeroFound && $goalMatch !== null) { break; }
        }
        self::assertNotNull($goalMatch, 'The bounded deterministic sample should contain a goal.');
        self::assertTrue($savesFound);
        self::assertTrue($cleanSheetsFound);
        self::assertGreaterThan(0, $simulated);
        $this->assertNotNull($before);
        $goalStats = $statsRepository->byMatch($goalMatch->id());
        $statsRepository->replaceForMatch($goalStats);
        self::assertSame($before, array_map(static fn ($stat): array => $stat->toArray(), $statsRepository->byMatch($goalMatch->id())));
        $aggregate = $statsRepository->seasonAggregates($season->id());
        self::assertNotEmpty($aggregate);
        $sample = array_values(array_filter($goalStats, static fn ($stat): bool => $stat->shots() > 0))[0];
        $sampleMatches = $statsRepository->byPlayer($sample->playerId());
        self::assertSame(array_sum(array_map(static fn ($stat): int => $stat->shots(), $sampleMatches)), $aggregate[$sample->playerId()->value()]['shots']);
        self::assertSame(array_sum(array_map(static fn ($stat): int => $stat->shotsOnTarget(), $sampleMatches)), $aggregate[$sample->playerId()->value()]['shots_on_target']);

        unset($database);
        $database = $store->openDatabase('domain-028-production');
        self::assertSame($before, array_map(static fn ($stat): array => $stat->toArray(), (new PlayerMatchStatRepository($database))->byMatch($goalMatch->id())));
        self::assertArrayHasKey('shots', $matchService->playerSummary($database, $goalMatch->id(), $sample->playerId()));
    }

    public function testLegacyRowsMigrateAndExpandedStatsAggregateIdempotently(): void
    {
        [$services, $database, $season] = $this->scenario('domain-028-stats');
        $match = new GameMatch(new MatchId('domain-028-stat-match'), new CompetitionId('premier-league'), $season->id(), 1, SimulationDate::fromIsoString('2024-08-01'), new ClubId('arsenal'), new ClubId('chelsea'));
        $match = $match->complete(new MatchResult(1, 0));
        $services->matchModule()->service()->repository($database)->save($match);
        $database->connection()->exec('CREATE TABLE match_player_stats (match_id TEXT NOT NULL, player_id TEXT NOT NULL, club_id TEXT NOT NULL, appeared INTEGER NOT NULL, started INTEGER NOT NULL, minutes INTEGER NOT NULL, goals INTEGER NOT NULL, assists INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (match_id, player_id))');
        $database->connection()->exec("INSERT INTO match_player_stats (match_id, player_id, club_id, appeared, started, minutes, goals, assists) VALUES ('domain-028-stat-match', 'legacy-goal', 'arsenal', 1, 1, 90, 1, 0)");
        $repository = new PlayerMatchStatRepository($database);
        $legacy = $repository->byMatch($match->id())[0];
        self::assertSame(1, $legacy->shots());
        self::assertSame(1, $legacy->shotsOnTarget());
        self::assertSame(0, $legacy->saves());
        $p1 = new PlayerId('domain-028-shooter');
        $p2 = new PlayerId('domain-028-assister');
        $p3 = new PlayerId('domain-028-keeper');
        $p4 = new PlayerId('domain-028-defender');
        $stats = [
            new PlayerMatchStat($match->id(), $p1, new ClubId('arsenal'), true, true, 90, 1, 0, 3, 2),
            new PlayerMatchStat($match->id(), $p2, new ClubId('arsenal'), true, true, 90, 0, 1, 2, 1),
            new PlayerMatchStat($match->id(), $p3, new ClubId('chelsea'), true, true, 90, 0, 0, 0, 0, 1),
            new PlayerMatchStat($match->id(), $p4, new ClubId('chelsea'), true, true, 90, 0, 0, 0, 0, 0, 1),
        ];
        $repository->replaceForMatch($stats);
        $aggregate = $repository->seasonAggregatesForPlayer($p1, $season->id())['arsenal'];
        self::assertSame(['club_id' => 'arsenal', 'appearances' => 1, 'starts' => 1, 'minutes' => 90, 'goals' => 1, 'assists' => 0, 'shots' => 3, 'shots_on_target' => 2, 'saves' => 0, 'clean_sheets' => 0], $aggregate);
        $repository->replaceForMatch($stats);
        self::assertCount(4, $repository->byMatch($match->id()));
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season,3:SqliteSaveStore} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2026028, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $season, $store];
    }

    /** @return array<string, mixed> */
    private function simulationArray(MatchSimulation $simulation): array
    {
        return ['result' => $simulation->result()->toArray(), 'stats' => array_map(static fn ($stat): array => $stat->toArray(), $simulation->playerStats()), 'highlights' => array_map(static fn ($highlight): array => $highlight->toArray(), $simulation->highlights())];
    }
}
