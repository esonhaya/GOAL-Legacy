<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\Domain\MatchSimulation;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\MatchSelectionService;
use Goal\Legacy\Modules\Match\MatchSimulationService;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Persistence\SeasonRepository;
use PHPUnit\Framework\TestCase;

final class Domain027Test extends TestCase
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

    public function testProductionMatchPathAttributesGoalsAndAssistsDeterministically(): void
    {
        [$services, $database, $season, $store] = $this->scenario('domain-027-production');
        $services->playerModule()->service()->populationService()->populate($database, $season, 27027);
        $matchService = $services->matchModule()->service();
        $matches = array_values(array_filter(
            $matchService->generateFixtures($database, 'premier-league', $season->id()),
            static fn (GameMatch $match): bool => $match->homeClubId()->value() === 'arsenal' || $match->awayClubId()->value() === 'arsenal',
        ));
        self::assertNotEmpty($matches);

        $direct = new MatchSimulationService($services->clubModule()->service(), new MatchSelectionService($services->clubModule()->service()));
        $first = $direct->simulate($database, $matches[0]);
        $second = $direct->simulate($database, $matches[0]);
        self::assertSame($this->simulationArray($first), $this->simulationArray($second));

        $goalMatch = null;
        $goalHighlights = [];
        foreach (array_slice($matches, 0, 16) as $scheduled) {
            $completed = $matchService->simulate($database, $scheduled->id());
            $highlights = array_values(array_filter($matchService->highlightRepository($database)->byMatch($completed->id()), static fn ($highlight): bool => $highlight->type() === 'goal'));
            if ($highlights !== []) {
                $goalMatch = $completed;
                $goalHighlights = $highlights;
                break;
            }
        }
        self::assertNotNull($goalMatch, 'The bounded deterministic sample should contain a goal.');
        $statsRepository = new PlayerMatchStatRepository($database);
        $stats = $statsRepository->byMatch($goalMatch->id());
        $byPlayer = [];
        foreach ($stats as $stat) { $byPlayer[$stat->playerId()->value()] = $stat; }
        self::assertSame($goalMatch->result()?->homeGoals(), array_sum(array_map(static fn ($stat): int => $stat->goals(), array_filter($stats, static fn ($stat): bool => $stat->clubId()->value() === $goalMatch->homeClubId()->value()))));
        self::assertSame($goalMatch->result()?->awayGoals(), array_sum(array_map(static fn ($stat): int => $stat->goals(), array_filter($stats, static fn ($stat): bool => $stat->clubId()->value() === $goalMatch->awayClubId()->value()))));

        $assistIds = [];
        foreach ($goalHighlights as $highlight) {
            $scorerId = $highlight->playerId()?->value();
            self::assertNotNull($scorerId);
            self::assertArrayHasKey($scorerId, $byPlayer);
            self::assertSame($highlight->clubId()?->value(), $byPlayer[$scorerId]->clubId()->value());
            $assistId = $highlight->data()['assist_player_id'] ?? null;
            if ($assistId !== null) {
                self::assertNotSame($scorerId, $assistId);
                self::assertArrayHasKey($assistId, $byPlayer);
                self::assertSame($highlight->clubId()?->value(), $byPlayer[$assistId]->clubId()->value());
                $assistIds[] = $assistId;
            }
        }
        self::assertSame(count($assistIds), array_sum(array_map(static fn ($stat): int => $stat->assists(), $stats)));
        self::assertContains('assists', array_keys($stats[0]->toArray()));

        $scorer = array_values(array_filter($stats, static fn ($stat): bool => $stat->goals() > 0))[0];
        $summary = $matchService->playerSummary($database, $goalMatch->id(), $scorer->playerId());
        self::assertNotNull($summary);
        self::assertSame($scorer->assists(), $summary['assists']);
        $before = array_map(static fn ($stat): array => $stat->toArray(), $stats);
        $statsRepository->replaceForMatch($stats);
        self::assertSame($before, array_map(static fn ($stat): array => $stat->toArray(), $statsRepository->byMatch($goalMatch->id())));

        unset($database);
        $database = $store->openDatabase('domain-027-production');
        self::assertSame($before, array_map(static fn ($stat): array => $stat->toArray(), (new PlayerMatchStatRepository($database))->byMatch($goalMatch->id())));
        $aggregate = (new PlayerMatchStatRepository($database))->seasonAggregates($season->id());
        self::assertSame($scorer->goals(), $aggregate[$scorer->playerId()->value()]['goals']);
        self::assertSame($scorer->assists(), $aggregate[$scorer->playerId()->value()]['assists']);
    }

    public function testAssistsDefaultToZeroAggregateAndSurviveReplacement(): void
    {
        [$services, $database, $season] = $this->scenario('domain-027-stats');
        $match = new GameMatch(new MatchId('domain-027-stat-match'), new \Goal\Legacy\Modules\Competition\Domain\CompetitionId('premier-league'), $season->id(), 1, SimulationDate::fromIsoString('2024-08-01'), new ClubId('arsenal'), new ClubId('chelsea'));
        $match = $match->complete(new MatchResult(2, 0));
        $services->matchModule()->service()->repository($database)->save($match);
        $p1 = new PlayerId('domain-027-scorer');
        $p2 = new PlayerId('domain-027-assister');
        $p3 = new PlayerId('domain-027-zero');
        $stats = [
            new PlayerMatchStat($match->id(), $p1, new ClubId('arsenal'), true, true, 90, 2, 1),
            new PlayerMatchStat($match->id(), $p2, new ClubId('arsenal'), true, true, 90, 0, 1),
            new PlayerMatchStat($match->id(), $p3, new ClubId('arsenal'), true, false, 45, 0),
        ];
        $database->connection()->exec('DROP TABLE IF EXISTS match_player_stats');
        $database->connection()->exec('CREATE TABLE match_player_stats (match_id TEXT NOT NULL, player_id TEXT NOT NULL, club_id TEXT NOT NULL, appeared INTEGER NOT NULL, started INTEGER NOT NULL, minutes INTEGER NOT NULL, goals INTEGER NOT NULL, PRIMARY KEY (match_id, player_id))');
        $repository = new PlayerMatchStatRepository($database);
        $columns = $database->connection()->query('PRAGMA table_info(match_player_stats)')->fetchAll();
        self::assertContains('assists', array_column($columns, 'name'));
        $repository->replaceForMatch($stats);
        self::assertSame(0, array_values(array_filter($repository->byMatch($match->id()), static fn ($stat): bool => $stat->playerId()->value() === 'domain-027-zero'))[0]->assists());
        $aggregate = $repository->seasonAggregatesForPlayer($p1, $season->id())['arsenal'];
        self::assertSame(['club_id' => 'arsenal', 'appearances' => 1, 'starts' => 1, 'minutes' => 90, 'goals' => 2, 'assists' => 1, 'shots' => 2, 'shots_on_target' => 2, 'saves' => 0, 'clean_sheets' => 0], $aggregate);
        $repository->replaceForMatch($stats);
        self::assertCount(3, $repository->byMatch($match->id()));
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season,3:SqliteSaveStore} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2026027, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
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
        return [
            'result' => $simulation->result()->toArray(),
            'stats' => array_map(static fn ($stat): array => $stat->toArray(), $simulation->playerStats()),
            'highlights' => array_map(static fn ($highlight): array => $highlight->toArray(), $simulation->highlights()),
        ];
    }
}
