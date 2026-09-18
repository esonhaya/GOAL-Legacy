<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\Competition\DomesticCupService;
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Match\Domain\SimulationFidelity;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class P2007DomesticCupTest extends TestCase
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

    public function testFiveDomesticCupsUseExistingClubsAndStableInitialDraws(): void
    {
        [$services, $database, $season] = $this->scenario('p2-007-draw');
        $competitionService = $services->competitionModule()->service();
        $definitions = array_values(array_filter(
            $competitionService->loadSelected(),
            static fn ($definition): bool => $definition->type() === CompetitionType::DomesticCup,
        ));
        self::assertCount(5, $definitions);

        // A league schedule establishes the shared calendar baseline before
        // the knockout scheduler places its first round.
        $matches = $services->matchModule()->service();
        $matches->generateFixtures($database, 'premier-league', $season->id());
        $expected = [
            'domestic-cup-england' => 12,
            'domestic-cup-spain' => 10,
            'domestic-cup-germany' => 4,
            'domestic-cup-italy' => 8,
            'domestic-cup-france' => 4,
        ];
        foreach ($expected as $competitionId => $firstRoundMatches) {
            $cupMatches = $matches->generateFixtures($database, $competitionId, $season->id());
            self::assertCount($firstRoundMatches, $cupMatches);
            self::assertSame(
                array_map(static fn ($match): array => [$match->id()->value(), $match->scheduledDate()->toIsoString(), $match->homeClubId()->value(), $match->awayClubId()->value()], $cupMatches),
                array_map(static fn ($match): array => [$match->id()->value(), $match->scheduledDate()->toIsoString(), $match->homeClubId()->value(), $match->awayClubId()->value()], $matches->generateFixtures($database, $competitionId, $season->id())),
            );
            foreach ($cupMatches as $match) {
                self::assertSame(MatchStatus::Scheduled, $match->status());
            }
        }
    }

    public function testCompletedCupRoundAdvancesOnceAndUsesWorldMatchFidelity(): void
    {
        [$services, $database, $season] = $this->scenario('p2-007-round');
        $matches = $services->matchModule()->service();
        $matches->generateFixtures($database, 'premier-league', $season->id());
        $matches->generateFixtures($database, 'domestic-cup-france', $season->id());

        $initial = $matches->repository($database)->byCompetition('domestic-cup-france', $season->id());
        self::assertCount(4, $initial);
        foreach ($initial as $match) {
            $completed = $matches->simulate($database, $match->id(), SimulationFidelity::World);
            self::assertSame(MatchStatus::Completed, $completed->status());
        }

        $all = $matches->repository($database)->byCompetition('domestic-cup-france', $season->id());
        self::assertCount(20, $all); // four preliminary matches plus the Round of 32.
        self::assertCount(16, array_filter($all, static fn ($match): bool => $match->round() === 2));
        self::assertCount(4, array_filter($all, static fn ($match): bool => $match->status() === MatchStatus::Completed));
        self::assertSame([], $matches->statRepository($database)->byMatch($initial[0]->id()));
        foreach ($initial as $match) {
            // Reprocessing a completed Match is rejected by MatchService, so
            // advancement cannot be duplicated through the normal path.
            self::assertSame(MatchStatus::Completed, $matches->repository($database)->get($match->id())->status());
        }
    }

    public function testCompletedCupMatchRecoveryPersistsAetOrPenaltiesWithoutShootoutMatchGoals(): void
    {
        [$services, $database, $season] = $this->scenario('p2-007-recovery');
        $matches = $services->matchModule()->service();
        $matches->generateFixtures($database, 'premier-league', $season->id());
        $matches->generateFixtures($database, 'domestic-cup-france', $season->id());
        $fixture = $matches->repository($database)->byCompetition('domestic-cup-france', $season->id())[0];
        $completed = $fixture->complete(new MatchResult(0, 0));
        $matches->repository($database)->save($completed);

        // Simulate a process stop after the canonical Match write but before
        // the Cup state write; World reload must reconcile it exactly once.
        $services->worldModule()->service()->load($database, 'p2-007-recovery');
        $cups = new DomesticCupService($services->clubModule()->service());
        $resolution = $cups->matchResolution($database, $fixture->id()->value());
        self::assertIsArray($resolution);
        self::assertSame(0, $resolution['regulation_home_goals']);
        self::assertSame(0, $resolution['regulation_away_goals']);
        self::assertNotNull($resolution['winner_club_id']);
        self::assertContains($resolution['decided_by'], ['extra_time', 'penalties']);
        self::assertSame([], $matches->statRepository($database)->byMatch($fixture->id()));

        $beforeReload = $resolution;
        $services->worldModule()->service()->load($database, 'p2-007-recovery');
        self::assertSame($beforeReload, $cups->matchResolution($database, $fixture->id()->value()));
    }

    public function testControlledCupMatchKeepsDetailedEvidence(): void
    {
        [$services, $database, $season] = $this->scenario('p2-007-player');
        $matches = $services->matchModule()->service();
        $matches->generateFixtures($database, 'premier-league', $season->id());
        $matches->generateFixtures($database, 'domestic-cup-france', $season->id());
        $fixture = $matches->repository($database)->byCompetition('domestic-cup-france', $season->id())[0];
        $matches->simulate($database, $fixture->id(), SimulationFidelity::Player);

        self::assertNotEmpty($matches->statRepository($database)->byMatch($fixture->id()));
    }

    public function testBoundedFiveCupAcceptanceCompletesAllCupsAndRollsOver(): void
    {
        [$services, $database, $season] = $this->scenario('p2-007-acceptance');
        $competitionRepository = $services->competitionModule()->service()->repository($database);
        $definitions = $competitionRepository->bySeason($season->id());
        $allCompetitionIds = array_map(
            static fn ($definition): string => $definition->id()->value(),
            array_filter($definitions, static fn ($definition): bool => $definition->type() !== CompetitionType::International),
        );
        $leagueIds = array_map(
            static fn ($definition): string => $definition->id()->value(),
            array_filter($definitions, static fn ($definition): bool => $definition->type() === CompetitionType::DomesticLeague),
        );
        $cupIds = array_map(
            static fn ($definition): string => $definition->id()->value(),
            array_filter($definitions, static fn ($definition): bool => $definition->type() === CompetitionType::DomesticCup),
        );
        $matches = $services->matchModule()->service();
        $matches->generateSeasonFixtures($database, $allCompetitionIds, $season->id());
        $repository = $matches->repository($database);
        $leagueCounts = [];
        foreach ($leagueIds as $leagueId) {
            $leagueCounts[$leagueId] = count($repository->byCompetition($leagueId, $season->id()));
            foreach ($repository->byCompetition($leagueId, $season->id()) as $fixture) {
                $completed = $fixture->complete(new MatchResult(1, 0));
                $repository->save($completed);
            }
        }

        $cups = new DomesticCupService($services->clubModule()->service());
        $cupMatchCounts = [];
        foreach ($cupIds as $cupId) {
            $cupMatchCounts[$cupId] = 0;
            while (!$cups->complete($database, $cupId, $season->id())) {
                $scheduled = array_values(array_filter(
                    $repository->byCompetition($cupId, $season->id()),
                    static fn ($fixture): bool => $fixture->status() === MatchStatus::Scheduled,
                ));
                self::assertNotEmpty($scheduled);
                foreach ($scheduled as $fixture) {
                    $completed = $fixture->complete(new MatchResult(1, 0));
                    $repository->save($completed);
                    $cups->recordCompletedMatch($database, $completed);
                    ++$cupMatchCounts[$cupId];
                }
            }
            $view = $cups->view($database, $cupId, $season->id());
            self::assertSame('completed', $view['status']);
            self::assertNotNull($view['winner_club_id']);
            self::assertNotNull($view['runner_up_club_id']);
        }

        self::assertSame(193, array_sum($cupMatchCounts));
        foreach ($leagueCounts as $leagueId => $count) {
            self::assertSame($count, count($repository->byCompetition($leagueId, $season->id())));
            self::assertCount(
                count($services->clubModule()->service()->byCompetition($database, $leagueId, $season->id())),
                $matches->standings($database, $leagueId, $season->id()),
            );
        }
        $collisions = $database->connection()->query(
            "SELECT club_id, scheduled_date, COUNT(*) FROM ("
            . "SELECT home_club_id AS club_id, scheduled_date FROM match_records WHERE season_id = 'season-2024-25' "
            . "UNION ALL SELECT away_club_id AS club_id, scheduled_date FROM match_records WHERE season_id = 'season-2024-25'"
            . ') GROUP BY club_id, scheduled_date HAVING COUNT(*) > 1'
        )->fetchAll();
        self::assertSame([], $collisions);
        $detailTable = $database->connection()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'match_player_stats'");
        $detailTable->execute();
        self::assertSame(0, $detailTable->fetchColumn() === false ? 0 : (int) $database->connection()->query(
            "SELECT COUNT(*) FROM match_player_stats stats JOIN match_records matches ON matches.id = stats.match_id JOIN competition_records competitions ON competitions.id = matches.competition_id WHERE competitions.type = 'domestic_cup'"
        )->fetchColumn());

        $worldService = $services->worldModule()->service();
        $worldService->advanceToDate($database, 'p2-007-acceptance', SimulationDate::fromIsoString('2025-06-01'));
        $worldService->advanceToDate($database, 'p2-007-acceptance', SimulationDate::fromIsoString('2025-08-01'));
        $nextSeason = new SeasonId('season-2025-26');
        self::assertSame($nextSeason->value(), $worldService->load($database, 'p2-007-acceptance')->currentSeasonId()?->value());
        foreach ($cupIds as $cupId) {
            self::assertSame('active', $cups->view($database, $cupId, $nextSeason)['status']);
        }
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 17017, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);
        $services->playerModule()->service()->populationService()->populate($database, $season, 17017);

        return [$services, $database, $season];
    }
}
