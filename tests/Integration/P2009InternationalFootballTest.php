<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\International\InternationalCompetitionService;
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Match\Domain\SimulationFidelity;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Persistence\CareerEventRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class P2009InternationalFootballTest extends TestCase
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

    public function testNationalTeamsSelectDeterministicallyAndWorldChampionshipCompletes(): void
    {
        [$services, $database, $season] = $this->scenario('p2-009-world');
        $matchService = $services->matchModule()->service();
        $matchService->generateFixtures($database, 'premier-league', $season->id());
        $fixtures = $matchService->generateFixtures($database, InternationalCompetitionService::WORLD_CHAMPIONSHIP, $season->id());
        $international = $services->internationalCompetitions();

        self::assertCount(7, $services->nationalTeams()->teams($database));
        self::assertSame(6, (int) $database->connection()->query("SELECT COUNT(*) FROM international_competition_entries WHERE competition_id = 'world-championship' AND season_id = 'season-2024-25'")->fetchColumn());
        self::assertCount(6, $fixtures);
        self::assertSame(161, (int) $database->connection()->query("SELECT COUNT(*) FROM international_team_squads WHERE season_id = 'season-2024-25'")->fetchColumn());
        self::assertSame(0, (int) $database->connection()->query("SELECT COUNT(*) FROM (SELECT national_team_id FROM international_team_squads WHERE season_id = 'season-2024-25' GROUP BY national_team_id HAVING COUNT(*) <> 23)")->fetchColumn());
        $services->worldModule()->service()->load($database, 'p2-009-world');
        $reloadedFixtures = $matchService->generateFixtures($database, InternationalCompetitionService::WORLD_CHAMPIONSHIP, $season->id());
        self::assertSame(array_map(static fn ($match): array => [$match->id()->value(), $match->scheduledDate()->toIsoString(), $match->homeClubId()->value(), $match->awayClubId()->value()], $fixtures), array_map(static fn ($match): array => [$match->id()->value(), $match->scheduledDate()->toIsoString(), $match->homeClubId()->value(), $match->awayClubId()->value()], $reloadedFixtures));
        self::assertSame([], $matchService->statRepository($database)->byMatch($fixtures[0]->id()));

        $playerId = (string) $database->connection()->query("SELECT player_id FROM international_team_squads WHERE season_id = 'season-2024-25' LIMIT 1")->fetchColumn();
        self::assertNotSame('', $playerId);
        $playerFixture = $fixtures[0];
        $matchService->simulate($database, $playerFixture->id(), SimulationFidelity::World);
        self::assertSame(0, (int) $database->connection()->query("SELECT COUNT(*) FROM match_player_stats WHERE match_id = '" . $playerFixture->id()->value() . "'")->fetchColumn());
        $teamId = (string) $database->connection()->query("SELECT national_team_id FROM international_team_squads WHERE season_id = 'season-2024-25' AND player_id = '" . $playerId . "' LIMIT 1")->fetchColumn();
        (new CareerPlayerRepository($database))->save(new CareerPlayerReference(new CareerId('p2-009-controlled-career'), new PlayerId($playerId), SimulationDate::fromIsoString('2024-08-01')));
        $controlledFixture = array_values(array_filter($matchService->repository($database)->byCompetition(InternationalCompetitionService::WORLD_CHAMPIONSHIP, $season->id()), static fn ($match): bool => $match->status() === MatchStatus::Scheduled && in_array($teamId, [$match->homeClubId()->value(), $match->awayClubId()->value()], true)))[0] ?? null;
        self::assertNotNull($controlledFixture);
        $matchService->simulate($database, $controlledFixture->id(), SimulationFidelity::Player);
        self::assertNotEmpty($matchService->statRepository($database)->byMatch($controlledFixture->id()));
        self::assertSame(1, (int) $database->connection()->query("SELECT caps FROM international_player_statistics WHERE player_id = '" . $playerId . "' AND season_id = 'season-2024-25'")->fetchColumn());
        self::assertNotNull((new CareerEventRepository($database))->bySourceKey('international|' . $playerId . '|first_cap'));

        while (!$international->complete($database, InternationalCompetitionService::WORLD_CHAMPIONSHIP, $season->id())) {
            $scheduled = array_values(array_filter($matchService->repository($database)->byCompetition(InternationalCompetitionService::WORLD_CHAMPIONSHIP, $season->id()), static fn ($match): bool => $match->status() === MatchStatus::Scheduled));
            if ($scheduled === []) { self::fail('International competition stalled without a scheduled fixture: ' . json_encode($database->connection()->query("SELECT * FROM international_competitions")->fetchAll(), JSON_THROW_ON_ERROR) . ' / ' . json_encode($database->connection()->query("SELECT * FROM international_match_states ORDER BY round_number, match_id")->fetchAll(), JSON_THROW_ON_ERROR)); }
            foreach ($scheduled as $match) {
                $completed = $match->complete(new MatchResult(1, 0));
                $matchService->repository($database)->save($completed);
                $international->recordCompletedMatch($database, $completed);
            }
        }

        self::assertCount(1, $database->connection()->query("SELECT winner_team_id FROM international_competitions WHERE competition_id = 'world-championship' AND season_id = 'season-2024-25'")->fetchAll());
        self::assertNotNull($international->winner($database, InternationalCompetitionService::WORLD_CHAMPIONSHIP, $season->id()));
        self::assertSame(9, (int) $database->connection()->query("SELECT COUNT(*) FROM match_records WHERE competition_id = 'world-championship' AND season_id = 'season-2024-25'")->fetchColumn());
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
        mkdir($directory, 0775, true); $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer()); $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0'))); $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);
        $services->playerModule()->service()->populationService()->populate($database, $season, 17017);

        return [$services, $database, $season];
    }
}
