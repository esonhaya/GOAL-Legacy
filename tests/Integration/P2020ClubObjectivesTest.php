<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\Club\ClubSeasonObjectiveService;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Club\Persistence\ClubMembershipRepository;
use Goal\Legacy\Modules\Club\Persistence\ClubSeasonObjectiveRepository;
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use Goal\Legacy\Modules\World\Persistence\SeasonRepository;
use PHPUnit\Framework\TestCase;

final class P2020ClubObjectivesTest extends TestCase
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

    public function testClubExpectationUsesStableClubContextAndCompetitionTier(): void
    {
        [$services, $database, $season] = $this->scenario('p2020-objective-derivation');
        $objectives = new ClubSeasonObjectiveService($services->clubModule()->service());
        $date = SimulationDate::fromIsoString('2024-08-01');

        $arsenal = $objectives->context($database, 'arsenal', $season->id(), $date);
        self::assertNotNull($arsenal);
        self::assertSame(1, $arsenal['league']['tier']);
        self::assertSame(ClubSeasonObjectiveService::TITLE_CHALLENGE, $arsenal['expectation']);
        self::assertSame('early_season', $arsenal['season']['phase']);
        self::assertNull($arsenal['outcome']);

        $secondTier = array_values(array_filter(
            $services->competitionModule()->service()->loadSelected(),
            static fn ($competition): bool => $competition->type() === CompetitionType::DomesticLeague && $competition->tier() === 2,
        ))[0] ?? null;
        self::assertNotNull($secondTier);
        $membership = (new ClubMembershipRepository($database))->byCompetition($secondTier->id(), $season->id())[0] ?? null;
        self::assertNotNull($membership);
        $lowerTier = $objectives->context($database, $membership->clubId(), $season->id(), $date);
        self::assertNotNull($lowerTier);
        self::assertSame(2, $lowerTier['league']['tier']);
        self::assertContains($lowerTier['expectation'], [ClubSeasonObjectiveService::PROMOTION_CHALLENGE, ClubSeasonObjectiveService::STABLE_SEASON]);
    }

    public function testControlledSummaryExposesClubStakesAndCompletedOutcomeIsIdempotent(): void
    {
        [$services, $database, $season, $store] = $this->scenario('p2020-objective-lifecycle');
        $player = $services->playerModule()->service()->create(new PlayerCreationRequest(
            'p2020-objective-player', 'Club Stakes', 'Player', 'Club Stakes Player', '2005-01-01',
            'england', [], 'england', ['england'], 180, 75, 'CM', 90, 'regular', 2020,
            new PlayerAttributeSet(72, 72, 72, 72, 72, 72),
        ));
        $services->playerModule()->service()->initializeCareer(
            $database,
            $player,
            new CareerPlayerReference(new CareerId('p2020-objective-career'), $player->id(), $season->startDate()),
            new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Regular),
        );
        $contracts = $services->contractModule()->service();
        $contracts->save($database, $contracts->create(new ContractCreationRequest(
            new ContractId('p2020-objective-contract'),
            $player->id(),
            new ClubId('arsenal'),
            $season->startDate(),
            SimulationDate::fromIsoString('2026-06-30'),
            100,
            $season->startDate(),
        )));

        $summary = (new PlayerCareerProgressionQuery($services->clubModule()->service()))->summary(
            $database,
            $player->id(),
            $season->startDate(),
            $season->id(),
        );
        self::assertSame(ClubSeasonObjectiveService::TITLE_CHALLENGE, $summary['club_season']['expectation']);
        self::assertContains($summary['club_season']['pressure'], ['low', 'normal']);

        $active = (new SeasonRepository($database))->get($season->id())->activate();
        (new SeasonRepository($database))->save($active);
        $completed = $active->complete();
        (new SeasonRepository($database))->save($completed);
        $objectives = new ClubSeasonObjectiveService($services->clubModule()->service());
        self::assertSame(['resolved' => 1, 'skipped' => 0], $objectives->resolveControlledSeason($database, $completed, $completed->endDate()));
        $stored = (new ClubSeasonObjectiveRepository($database))->find($player->id()->value(), $season->id()->value(), 'arsenal');
        self::assertNotNull($stored);
        self::assertSame(ClubSeasonObjectiveService::TITLE_CHALLENGE, $stored['objective']);
        self::assertContains($stored['outcome'], ['achieved', 'exceeded', 'unknown', 'missed']);
        self::assertSame([], $objectives->integrity($database));
        self::assertSame(['resolved' => 0, 'skipped' => 1], $objectives->resolveControlledSeason($database, $completed, $completed->endDate()));

        unset($database);
        $reloaded = $store->openDatabase('p2020-objective-lifecycle');
        $reloadedRow = (new ClubSeasonObjectiveRepository($reloaded))->find($player->id()->value(), $season->id()->value(), 'arsenal');
        self::assertSame($stored, $reloadedRow);
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season,3?:SqliteSaveStore} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2026020, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $season, $store];
    }
}
