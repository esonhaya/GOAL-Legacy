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
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\CareerLegacyService;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Persistence\CareerLegacyRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class P2025CareerMemoryTest extends TestCase
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

    public function testFirstsTimelinePersonalBestAndLegacyRowsAreStable(): void
    {
        [$services, $database, $season, $player] = $this->scenario('p2025-memory');
        $this->matchWithStat($database, $season, $player->id()->value(), 'p2025-memory-match', '2024-08-02', true, 1, 1);
        $legacy = new CareerLegacyService($services->clubModule()->service(), $services->nationalTeams(), $services->internationalCompetitions(), $services->playerModule()->service()->socialService());
        $repository = new CareerLegacyRepository($database);
        $repository->saveMilestoneInTransaction(['source_key' => 'milestone|' . $player->id()->value() . '|club_appearances|50', 'player_id' => $player->id()->value(), 'season_id' => $season->id()->value(), 'metric' => 'club_appearances', 'threshold' => 50, 'label' => '50 Club appearances', 'occurred_date' => $season->endDate()->toIsoString(), 'evidence' => ['value' => 50]]);
        $repository->saveRecordInTransaction(['player_id' => $player->id()->value(), 'metric' => 'best_season_goals', 'value' => 12, 'season_id' => $season->id()->value(), 'club_id' => 'arsenal', 'updated_date' => $season->endDate()->toIsoString(), 'evidence' => ['value' => 12]]);

        $first = $legacy->summary($database, $player->id()->value());
        $second = $legacy->summary($database, $player->id()->value());
        $titles = array_column($first['career_timeline'], 'title');
        self::assertContains('Senior debut', $titles);
        self::assertContains('First senior start', $titles);
        self::assertContains('First senior goal', $titles);
        self::assertContains('First senior assist', $titles);
        self::assertContains('50 Club appearances', $titles);
        self::assertSame($first['career_timeline'], $second['career_timeline']);
        self::assertSame('Most goals in a Season', $first['personal_bests'][0]['label']);
        self::assertContains('match-first|' . $player->id()->value() . '|first_senior_goal', array_column($first['career_timeline'], 'source_key'));
    }

    public function testApproachingMilestonesAreBoundedAndNoLowSampleMilestoneAppears(): void
    {
        [$services] = $this->scenario('p2025-next');
        $legacy = new CareerLegacyService($services->clubModule()->service(), $services->nationalTeams(), $services->internationalCompetitions());
        self::assertSame(100, $legacy->nextMilestone(['appearances' => 99, 'goals' => 8, 'assists' => 2], [])['threshold']);
        self::assertNull($legacy->nextMilestone(['appearances' => 1, 'goals' => 1, 'assists' => 0], []));
    }

    public function testMatchLandmarkCalloutsAreReadOnlyAndUseCanonicalMatchEvidence(): void
    {
        [$services, $database, $season, $player] = $this->scenario('p2025-callout');
        $match = $this->matchWithStat($database, $season, $player->id()->value(), 'p2025-callout-match', '2024-08-03', true, 1, 1);
        $legacy = new CareerLegacyService($services->clubModule()->service(), $services->nationalTeams(), $services->internationalCompetitions());
        $callouts = $legacy->matchLandmarks($database, $match, $player->id()->value());
        self::assertContains('Senior debut', array_column($callouts, 'title'));
        self::assertContains('First senior goal', array_column($callouts, 'title'));
        self::assertSame(0, (int) $database->connection()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'career_legacy_milestones'")->fetchColumn());
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season,3:\Goal\Legacy\Modules\Player\Domain\Player} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2025025, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $root = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($root, 0775, true); $this->roots[] = $root;
        $store = new SqliteSaveStore($root, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);
        $player = $services->playerModule()->service()->create(new PlayerCreationRequest('p2025-player', 'Memory', 'Player', 'Memory Player', '2000-01-01', 'england', [], 'england', ['england'], 180, 75, 'ST', 90, 'regular', 12025, new PlayerAttributeSet(70, 70, 70, 70, 70, 70)));
        (new PlayerRepository($database))->save($player);
        $services->playerModule()->service()->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('p2025-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Regular));

        return [$services, $database, $season, $player];
    }

    private function matchWithStat($database, Season $season, string $playerId, string $id, string $date, bool $started, int $goals, int $assists): GameMatch
    {
        // The fixture is written explicitly so this test never relies on a
        // simulation tick to manufacture historical evidence.
        $match = new GameMatch(new MatchId($id), new CompetitionId('premier-league'), $season->id(), 1, SimulationDate::fromIsoString($date), new ClubId('arsenal'), new ClubId('chelsea'));
        $match = $match->complete(new MatchResult(2, 0));
        (new MatchRepository($database))->save($match);
        (new PlayerMatchStatRepository($database))->replaceForMatch([new PlayerMatchStat($match->id(), new \Goal\Legacy\Modules\Player\Domain\PlayerId($playerId), new ClubId('arsenal'), true, $started, 90, $goals, $assists, 2, 2)]);

        return $match;
    }
}
