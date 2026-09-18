<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
use Goal\Legacy\Modules\Competition\DomesticCupService;
use Goal\Legacy\Modules\Competition\EuropeanCompetitionService;
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Match\Domain\SimulationFidelity;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class P2008EuropeanClubTest extends TestCase
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

    public function testTwoTiersUseSixteenExistingClubsAndStableGroups(): void
    {
        [$services, $database, $season] = $this->scenario('p2-008-draw');
        $matches = $services->matchModule()->service();
        $matches->generateFixtures($database, 'premier-league', $season->id());
        $europe = new EuropeanCompetitionService($services->clubModule()->service(), new \Goal\Legacy\Modules\Competition\DomesticCupService($services->clubModule()->service()));
        $tierOne = $matches->generateFixtures($database, EuropeanCompetitionService::TIER_1, $season->id());
        $tierTwo = $matches->generateFixtures($database, EuropeanCompetitionService::TIER_2, $season->id());

        self::assertCount(24, $tierOne);
        self::assertCount(24, $tierTwo);
        self::assertSame(16, (int) $database->connection()->query("SELECT COUNT(*) FROM european_entries WHERE competition_id = 'europe-tier-1' AND season_id = 'season-2024-25'")->fetchColumn());
        self::assertSame(16, (int) $database->connection()->query("SELECT COUNT(*) FROM european_entries WHERE competition_id = 'europe-tier-2' AND season_id = 'season-2024-25'")->fetchColumn());
        self::assertSame(0, (int) $database->connection()->query("SELECT COUNT(*) FROM (SELECT club_id FROM european_entries WHERE season_id = 'season-2024-25' GROUP BY club_id HAVING COUNT(*) > 1)")->fetchColumn());
        self::assertSame(4, (int) $database->connection()->query("SELECT COUNT(*) FROM european_group_memberships WHERE competition_id = 'europe-tier-1' AND season_id = 'season-2024-25' GROUP BY group_name HAVING COUNT(*) = 4")->fetchColumn());
        self::assertSame(4, (int) $database->connection()->query("SELECT COUNT(*) FROM european_group_memberships WHERE competition_id = 'europe-tier-2' AND season_id = 'season-2024-25' GROUP BY group_name HAVING COUNT(*) = 4")->fetchColumn());

        $before = array_map(static fn ($match): array => [$match->id()->value(), $match->scheduledDate()->toIsoString(), $match->homeClubId()->value(), $match->awayClubId()->value()], $tierOne);
        self::assertSame($before, array_map(static fn ($match): array => [$match->id()->value(), $match->scheduledDate()->toIsoString(), $match->homeClubId()->value(), $match->awayClubId()->value()], $matches->generateFixtures($database, EuropeanCompetitionService::TIER_1, $season->id())));
        $collisions = $database->connection()->query("SELECT club_id, scheduled_date, COUNT(*) AS count FROM (SELECT home_club_id AS club_id, scheduled_date FROM match_records WHERE season_id = 'season-2024-25' UNION ALL SELECT away_club_id AS club_id, scheduled_date FROM match_records WHERE season_id = 'season-2024-25') GROUP BY club_id, scheduled_date HAVING COUNT(*) > 1")->fetchAll();
        self::assertSame([], $collisions);
        self::assertSame(62, count($tierOne) + count($tierTwo) + 14);

        $services->worldModule()->service()->load($database, 'p2-008-draw');
        self::assertSame($before, array_map(static fn ($match): array => [$match->id()->value(), $match->scheduledDate()->toIsoString(), $match->homeClubId()->value(), $match->awayClubId()->value()], $matches->repository($database)->byCompetition(EuropeanCompetitionService::TIER_1, $season->id())));
        self::assertSame('active', $europe->view($database, EuropeanCompetitionService::TIER_1, $season->id())['status']);
    }

    public function testEuropeanGroupAndKnockoutStateCompletesExactlyOnce(): void
    {
        [$services, $database, $season] = $this->scenario('p2-008-completion');
        $matches = $services->matchModule()->service();
        $matches->generateFixtures($database, 'premier-league', $season->id());
        $domesticCups = new \Goal\Legacy\Modules\Competition\DomesticCupService($services->clubModule()->service());
        $europe = new EuropeanCompetitionService($services->clubModule()->service(), $domesticCups);
        $matches->generateFixtures($database, EuropeanCompetitionService::TIER_1, $season->id());
        $repository = $matches->repository($database);

        foreach ($repository->byCompetition(EuropeanCompetitionService::TIER_1, $season->id()) as $fixture) {
            $completed = $fixture->complete(new MatchResult(1, 0));
            $repository->save($completed);
            $europe->recordCompletedMatch($database, $completed);
        }
        $afterGroups = $repository->byCompetition(EuropeanCompetitionService::TIER_1, $season->id());
        self::assertCount(28, $afterGroups);
        self::assertCount(4, array_filter($afterGroups, static fn ($match): bool => $match->round() === 7));
        self::assertCount(4, $europe->groupTables($database, EuropeanCompetitionService::TIER_1, $season->id()));

        foreach ([7, 8, 9] as $round) {
            $roundFixtures = array_values(array_filter($repository->byCompetition(EuropeanCompetitionService::TIER_1, $season->id()), static fn ($match): bool => $match->round() === $round && $match->status() === MatchStatus::Scheduled));
            foreach ($roundFixtures as $fixture) {
                $completed = $fixture->complete(new MatchResult(0, 0));
                $repository->save($completed);
                $europe->recordCompletedMatch($database, $completed);
            }
        }

        self::assertTrue($europe->complete($database, EuropeanCompetitionService::TIER_1, $season->id()));
        self::assertNotNull($europe->winner($database, EuropeanCompetitionService::TIER_1, $season->id()));
        self::assertSame(31, count($repository->byCompetition(EuropeanCompetitionService::TIER_1, $season->id())));
        $winner = $europe->winner($database, EuropeanCompetitionService::TIER_1, $season->id());
        $europe->recordCompletedMatch($database, $repository->byCompetition(EuropeanCompetitionService::TIER_1, $season->id())[30]);
        self::assertSame($winner, $europe->winner($database, EuropeanCompetitionService::TIER_1, $season->id()));
        $detailTable = (int) $database->connection()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'match_player_stats'")->fetchColumn();
        self::assertSame(0, $detailTable === 0 ? 0 : (int) $database->connection()->query("SELECT COUNT(*) FROM match_player_stats stats JOIN match_records matches ON matches.id = stats.match_id JOIN competition_records competitions ON competitions.id = matches.competition_id WHERE competitions.type = 'continental'")->fetchColumn());
        foreach ($repository->byCompetition(EuropeanCompetitionService::TIER_1, $season->id()) as $fixture) {
            self::assertFalse($fixture->scheduledDate()->isAfter($season->endDate()), 'European fixture falls outside the Season calendar.');
        }
        $collisions = $database->connection()->query("SELECT club_id, scheduled_date, COUNT(*) AS count FROM (SELECT home_club_id AS club_id, scheduled_date FROM match_records WHERE season_id = 'season-2024-25' UNION ALL SELECT away_club_id AS club_id, scheduled_date FROM match_records WHERE season_id = 'season-2024-25') GROUP BY club_id, scheduled_date HAVING COUNT(*) > 1")->fetchAll();
        self::assertSame([], $collisions);
    }

    public function testBoundedWorldAcceptanceCompletesEuropeAndRolloverUsesResults(): void
    {
        [$services, $database, $season] = $this->scenario('p2-008-acceptance');
        $competitionRepository = $services->competitionModule()->service()->repository($database);
        $definitions = $competitionRepository->bySeason($season->id());
        $allIds = array_map(static fn ($definition): string => $definition->id()->value(), $definitions);
        $matchService = $services->matchModule()->service();
        $matchService->generateSeasonFixtures($database, $allIds, $season->id());
        $matches = $matchService->repository($database);
        $leagueIds = array_map(static fn ($definition): string => $definition->id()->value(), array_filter($definitions, static fn ($definition): bool => $definition->type() === CompetitionType::DomesticLeague));
        foreach ($leagueIds as $leagueId) {
            foreach ($matches->byCompetition($leagueId, $season->id()) as $fixture) {
                $matches->save($fixture->complete(new MatchResult(1, 0)));
            }
        }
        $cups = new DomesticCupService($services->clubModule()->service());
        $cupIds = array_map(static fn ($definition): string => $definition->id()->value(), array_filter($definitions, static fn ($definition): bool => $definition->type() === CompetitionType::DomesticCup));
        foreach ($cupIds as $cupId) {
            while (!$cups->complete($database, $cupId, $season->id())) {
                foreach (array_values(array_filter($matches->byCompetition($cupId, $season->id()), static fn ($fixture): bool => $fixture->status() === MatchStatus::Scheduled)) as $fixture) {
                    $completed = $fixture->complete(new MatchResult(1, 0));
                    $matches->save($completed);
                    $cups->recordCompletedMatch($database, $completed);
                }
            }
        }
        $europe = new EuropeanCompetitionService($services->clubModule()->service(), $cups);
        foreach ([EuropeanCompetitionService::TIER_1, EuropeanCompetitionService::TIER_2] as $competitionId) {
            while (!$europe->complete($database, $competitionId, $season->id())) {
                foreach (array_values(array_filter($matches->byCompetition($competitionId, $season->id()), static fn ($fixture): bool => $fixture->status() === MatchStatus::Scheduled)) as $fixture) {
                    $completed = $fixture->complete(new MatchResult(1, 0));
                    $matches->save($completed);
                    $europe->recordCompletedMatch($database, $completed);
                }
            }
            self::assertNotNull($europe->winner($database, $competitionId, $season->id()));
        }
        self::assertSame(62, (int) $database->connection()->query("SELECT COUNT(*) FROM match_records WHERE season_id = 'season-2024-25' AND competition_id IN ('europe-tier-1', 'europe-tier-2')")->fetchColumn());

        $world = $services->worldModule()->service();
        $world->advanceToDate($database, 'p2-008-acceptance', SimulationDate::fromIsoString('2025-06-01'));
        $world->advanceToDate($database, 'p2-008-acceptance', SimulationDate::fromIsoString('2025-08-01'));
        $nextSeason = new SeasonId('season-2025-26');
        self::assertSame($nextSeason->value(), $world->load($database, 'p2-008-acceptance')->currentSeasonId()?->value());
        foreach ([EuropeanCompetitionService::TIER_1, EuropeanCompetitionService::TIER_2] as $competitionId) {
            self::assertSame('active', $europe->view($database, $competitionId, $nextSeason)['status']);
            self::assertSame('season-2024-25', (string) $database->connection()->query("SELECT qualification_season_id FROM european_seasons WHERE competition_id = '" . $competitionId . "' AND season_id = 'season-2025-26'")->fetchColumn());
            self::assertSame(16, (int) $database->connection()->query("SELECT COUNT(*) FROM european_entries WHERE competition_id = '" . $competitionId . "' AND season_id = 'season-2025-26'")->fetchColumn());
            self::assertSame(0, (int) $database->connection()->query("SELECT COUNT(*) FROM (SELECT club_id FROM european_entries WHERE competition_id = '" . $competitionId . "' AND season_id = 'season-2025-26' GROUP BY club_id HAVING COUNT(*) > 1)")->fetchColumn());
        }
    }

    public function testEuropeanMatchesUseCanonicalPlayerAndWorldFidelity(): void
    {
        [$services, $database, $season] = $this->scenario('p2-008-fidelity');
        $matches = $services->matchModule()->service();
        $matches->generateFixtures($database, 'premier-league', $season->id());
        $fixtures = $matches->generateFixtures($database, EuropeanCompetitionService::TIER_1, $season->id());
        $worldMatch = $matches->simulate($database, $fixtures[0]->id(), SimulationFidelity::World);
        self::assertSame(MatchStatus::Completed, $worldMatch->status());
        self::assertSame([], $matches->statRepository($database)->byMatch($worldMatch->id()));
        self::assertGreaterThan(0, (int) $database->connection()->query("SELECT COUNT(*) FROM player_season_statistics WHERE season_id = 'season-2024-25'")->fetchColumn());

        $playerMatch = $matches->simulate($database, $fixtures[1]->id(), SimulationFidelity::Player);
        self::assertSame(MatchStatus::Completed, $playerMatch->status());
        self::assertNotEmpty($matches->statRepository($database)->byMatch($playerMatch->id()));
        self::assertGreaterThan(0, (int) $database->connection()->query("SELECT COUNT(*) FROM match_player_stats stats JOIN match_records matches ON matches.id = stats.match_id JOIN competition_records competitions ON competitions.id = matches.competition_id WHERE competitions.type = 'continental' AND matches.id = '" . $playerMatch->id()->value() . "'")->fetchColumn());
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
