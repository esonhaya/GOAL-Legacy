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
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PDOException;
use PHPUnit\Framework\TestCase;

final class Domain006Test extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) { foreach (glob($root . '/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } } if (is_dir($root)) { rmdir($root); } }
    }

    public function testBig5FixtureGenerationHasExpectedCountsAndDeterministicHomeAwayPairs(): void
    {
        [$services, $database, $season] = $this->scenario('domain-006-fixtures');
        $matchService = $services->matchModule()->service();
        $expected = ['premier-league' => 380, 'la-liga' => 380, 'bundesliga' => 306, 'serie-a' => 380, 'ligue-1' => 306];
        $all = [];
        foreach ($expected as $competitionId => $count) {
            $matches = $matchService->generateFixtures($database, $competitionId, $season->id());
            self::assertCount($count, $matches);
            foreach ($matches as $match) { self::assertSame($season->id()->value(), $match->seasonId()->value()); self::assertGreaterThanOrEqual($season->startDate()->toIsoString(), $match->scheduledDate()->toIsoString()); self::assertLessThanOrEqual($season->endDate()->toIsoString(), $match->scheduledDate()->toIsoString()); $all[] = $match; }
        }
        self::assertCount(1752, $all);
        self::assertCount(1752, array_unique(array_map(static fn ($match): string => $match->id()->value(), $all)));
        $pairCounts = [];
        foreach ($all as $match) { $clubs = [$match->homeClubId()->value(), $match->awayClubId()->value()]; sort($clubs, SORT_STRING); $key = $match->competitionId()->value() . ':' . implode(':', $clubs); $pairCounts[$key] = ($pairCounts[$key] ?? 0) + 1; }
        self::assertNotContains(1, array_values($pairCounts));
        self::assertNotContains(3, array_values($pairCounts));
        self::assertSame($all[0]->toArray(), $matchService->repository($database)->get($all[0]->id())->toArray());
        self::assertCount(1752, array_merge($matchService->generateFixtures($database, 'premier-league', $season->id()), $matchService->repository($database)->byCompetition('la-liga', $season->id()), $matchService->repository($database)->byCompetition('bundesliga', $season->id()), $matchService->repository($database)->byCompetition('serie-a', $season->id()), $matchService->repository($database)->byCompetition('ligue-1', $season->id())));
    }

    public function testCareerPlayerMatchLoopPersistsResultStatsHighlightsStandingsAndReload(): void
    {
        [$services, $database, $season, $store] = $this->scenario('domain-006-vertical');
        $matchService = $services->matchModule()->service(); $matches = $matchService->generateFixtures($database, 'premier-league', $season->id());
        $playerService = $services->playerModule()->service(); $player = $playerService->create(new PlayerCreationRequest('domain-006-player', 'Match', 'Career', 'Match Career', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 88, 'regular', 42)); $playerService->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('domain-006-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id()));
        $contractService = $services->contractModule()->service(); $contractService->save($database, $contractService->create(new ContractCreationRequest(new ContractId('domain-006-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2025-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));
        $date = $matches[0]->scheduledDate(); $services->worldModule()->service()->advanceToDate($database, 'domain-006-vertical', $date); $completed = $matchService->simulateDue($database, $date); self::assertCount(10, $completed); self::assertCount(10, array_filter($completed, static fn ($match): bool => $match->status() === MatchStatus::Completed));
        $careerSummary = null; foreach ($completed as $match) { $careerSummary = $matchService->playerSummary($database, $match->id(), $player->id()); if ($careerSummary !== null) { break; } } self::assertNotNull($careerSummary); self::assertTrue($careerSummary['appeared']); self::assertSame(90, $careerSummary['minutes']);
        $table = $matchService->standings($database, 'premier-league', $season->id()); self::assertCount(20, $table); self::assertSame(20, array_sum(array_map(static fn (array $row): int => $row['played'], $table))); self::assertSame($table, $matchService->standings($database, 'premier-league', $season->id()));
        $careerMatchId = $careerSummary['match_id']; $before = $matchService->repository($database)->get($careerMatchId)->toArray(); unset($database); $database = $store->openDatabase('domain-006-vertical'); self::assertSame($before, $matchService->repository($database)->get($careerMatchId)->toArray()); self::assertSame($table, $matchService->standings($database, 'premier-league', $season->id())); self::assertNotNull($matchService->playerSummary($database, $careerMatchId, $player->id()));
    }

    public function testMatchResultTransactionRollsBackWhenHighlightWriteFails(): void
    {
        [$services, $database, $season] = $this->scenario('domain-006-rollback'); $matchService = $services->matchModule()->service(); $matches = $matchService->generateFixtures($database, 'premier-league', $season->id()); $match = $matches[0]; $matchService->highlightRepository($database);
        $database->connection()->exec("CREATE TRIGGER fail_match_highlight_write BEFORE INSERT ON match_highlights BEGIN SELECT RAISE(ABORT, 'forced highlight failure'); END");
        try { $matchService->simulate($database, $match->id()); self::fail('Forced highlight failure should abort Match processing.'); } catch (PDOException $exception) { self::assertStringContainsString('forced highlight failure', $exception->getMessage()); }
        self::assertSame(MatchStatus::Scheduled, (new MatchRepository($database))->get($match->id())->status()); self::assertSame([], $matchService->statRepository($database)->byMatch($match->id())); self::assertSame([], $matchService->highlightRepository($database)->byMatch($match->id())); self::assertSame(0, array_sum(array_map(static fn (array $row): int => $row['played'], $matchService->standings($database, 'premier-league', $season->id()))));
    }

    /** @return array{0: \Goal\Legacy\Core\Bootstrap\CoreServices, 1: \Goal\Legacy\Core\Persistence\DatabaseInterface, 2: Season, 3: SqliteSaveStore} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']); $calendar = $services->worldModule()->service()->calendar(); $nations = $services->nationModule()->service()->loadSelected(); $competitions = $services->competitionModule()->service()->loadSelected(); $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31')); $world = new World(new WorldId($id), $id, 2026006, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds()); $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8)); mkdir($directory, 0775, true); $this->roots[] = $directory; $store = new SqliteSaveStore($directory, new JsonSerializer()); $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0'))); $database = $store->openDatabase($id); $services->worldModule()->service()->initialize($database, $world, $season); return [$services, $database, $season, $store];
    }
}
