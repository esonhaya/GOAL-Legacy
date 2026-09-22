<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Devtools\Presentation\CareerPresentationService;
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
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class P2041SeasonReviewTest extends TestCase
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

    public function testCompletedSeasonReviewUsesCanonicalFactsAndIsReadOnly(): void
    {
        [$services, $database, $store, $season, $player] = $this->scenario('p2041-review');
        $playerService = $services->playerModule()->service();
        $playerService->initializeCareer(
            $database,
            $player,
            new CareerPlayerReference(new CareerId('p2041-review'), $player->id(), SimulationDate::fromIsoString('2024-07-31')),
            new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Regular),
        );
        $completed = $season->activate()->complete();
        $services->worldModule()->service()->seasonRepository($database)->save($completed);

        $match = (new GameMatch(
            new MatchId('p2041-review-match'),
            new CompetitionId('premier-league'),
            $season->id(),
            1,
            SimulationDate::fromIsoString('2024-08-02'),
            new ClubId('arsenal'),
            new ClubId('chelsea'),
        ))->complete(new MatchResult(1, 0));
        (new MatchRepository($database))->save($match);
        (new PlayerMatchStatRepository($database))->replaceForMatch([
            new PlayerMatchStat($match->id(), $player->id(), new ClubId('arsenal'), true, true, 90, 1, 1, 1, 1),
        ]);
        $canonicalRows = (new \Goal\Legacy\Modules\Player\CompetitionStatisticsQuery())->forCompetitionSeason($database, 'premier-league', $season->id());
        self::assertNotEmpty($canonicalRows);

        $snapshot = (new CareerPresentationService($services))->snapshot($database, 'p2041-review', true);
        $before = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();
        $review = (new CareerPresentationService($services))->seasonReview($database, $snapshot['summary'], $season->id()->value());
        $after = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();

        self::assertTrue($review['available']);
        self::assertTrue($review['season']['completed']);
        self::assertSame('2024/25', $review['season']['label']);
        self::assertSame(1, $review['statistics']['totals']['appearances']);
        self::assertSame(90, $review['statistics']['totals']['minutes']);
        self::assertSame(1, $review['statistics']['totals']['goals']);
        self::assertSame(1, $review['statistics']['totals']['assists']);
        self::assertCount(1, $review['statistics']['competitions']);
        self::assertSame('arsenal', $review['statistics']['competitions'][0]['clubs'][0]['club_id']);
        self::assertArrayNotHasKey('season_score', $review);
        self::assertSame($before, $after);

    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:SqliteSaveStore,3:Season,4:\Goal\Legacy\Modules\Player\Domain\Player} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2041041, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);
        $player = $services->playerModule()->service()->create(new PlayerCreationRequest(
            'p2041-player', 'Review', 'Player', 'Review Player', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'ST', 90, 'regular', 2041, new PlayerAttributeSet(70, 70, 70, 70, 70, 70),
        ));

        return [$services, $database, $store, $season, $player];
    }
}
