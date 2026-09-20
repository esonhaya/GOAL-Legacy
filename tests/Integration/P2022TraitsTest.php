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
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\PlayerMatchRatingService;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Domain\PositionDevelopmentState;
use Goal\Legacy\Modules\Player\Domain\PlayerTrait;
use Goal\Legacy\Modules\Player\Persistence\PositionDevelopmentRepository;
use Goal\Legacy\Modules\Player\Domain\WeakFootTier;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\PlayerTraitService;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use Goal\Legacy\Modules\World\Persistence\PlayerSeasonStatisticsRepository;
use PHPUnit\Framework\TestCase;

final class P2022TraitsTest extends TestCase
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

    public function testTraitsNeedCapabilityAndMeaningfulEvidence(): void
    {
        [$services, $database, $season] = $this->scenario('p2022-evidence');
        $players = new PlayerRepository($database);
        $striker = $this->player('p2022-striker', 'ST', new PlayerAttributeSet(80, 85, 70, 75, 45, 80));
        $lowSample = $this->player('p2022-low-sample', 'ST', new PlayerAttributeSet(80, 99, 70, 75, 45, 80));
        $players->save($striker);
        $players->save($lowSample);
        $this->addEvidence($database, $season, $striker, 24, [90, 1, 0, 4, 2, 0, 0, 0, 0, 0, 0, 0]);
        $this->addEvidence($database, $season, $lowSample, 1, [90, 1, 0, 4, 2, 0, 0, 0, 0, 0, 0, 0]);

        $service = new PlayerTraitService();
        $identity = $service->derive($database, $striker);
        $limited = $service->derive($database, $lowSample);

        self::assertContains('finisher', array_column($identity['established'], 'key'));
        self::assertContains('goal_threat', array_column($identity['established'], 'key'));
        self::assertNotContains('finisher', array_column($limited['established'], 'key'));
        self::assertNotContains('finisher', array_column($limited['emerging'], 'key'));
        self::assertLessThanOrEqual(5, count($identity['active']));
        self::assertSame($identity, $service->derive($database, $striker));
        self::assertSame([], $database->connection()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE '%trait%'")->fetchAll());
    }

    public function testCatalogMaturityAndInertiaAreBounded(): void
    {
        [$services, $database, $season] = $this->scenario('p2022-maturity');
        $players = new PlayerRepository($database);
        $stable = $this->player('p2022-stable', 'ST', new PlayerAttributeSet(75, 85, 70, 75, 45, 80));
        $hot = $this->player('p2022-hot', 'ST', new PlayerAttributeSet(70, 75, 70, 70, 45, 70));
        $average = $this->player('p2022-average', 'CM', new PlayerAttributeSet(60, 60, 60, 60, 60, 60));
        $players->save($stable);
        $players->save($hot);
        $players->save($average);
        $prior = new Season(new SeasonId('season-2023-24'), '2023/24', SimulationDate::fromIsoString('2023-08-01'), SimulationDate::fromIsoString('2024-05-31'));
        $this->addEvidence($database, $prior, $stable, 18, [90, 1, 0, 3, 2, 0, 0, 0, 0, 0, 0, 0]);
        $this->addEvidence($database, $season, $stable, 3, [90, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0]);
        $this->addEvidence($database, $season, $hot, 6, [90, 1, 0, 3, 2, 0, 0, 0, 0, 0, 0, 0]);
        $this->addEvidence($database, $season, $average, 20, [90, 0, 0, 0, 0, 0, 0, 0, 0, 20, 12, 0]);

        $service = new PlayerTraitService();
        $stableTraits = $service->derive($database, $stable, $season->id());
        $hotTraits = $service->derive($database, $hot, $season->id());
        $averageTraits = $service->derive($database, $average, $season->id());
        $labels = array_map(static fn (PlayerTrait $trait): string => $trait->label(), PlayerTrait::cases());
        $descriptions = array_map(static fn (PlayerTrait $trait): string => $trait->description(), PlayerTrait::cases());

        self::assertCount(10, PlayerTrait::cases());
        self::assertCount(count($labels), array_unique($labels));
        self::assertCount(count($descriptions), array_unique($descriptions));
        self::assertNotContains('Prodigy', $labels);
        self::assertContains('finisher', array_column($stableTraits['established'], 'key'));
        self::assertNotContains('finisher', array_column($stableTraits['emerging'], 'key'));
        self::assertContains('finisher', array_column($hotTraits['emerging'], 'key'));
        self::assertNotContains('finisher', array_column($hotTraits['established'], 'key'));
        self::assertSame([], $averageTraits['established']);
        self::assertLessThanOrEqual($stableTraits['active_limit'], count($stableTraits['established']));
        self::assertLessThanOrEqual($stableTraits['emerging_limit'], count($stableTraits['emerging']));
        self::assertSame($stableTraits, $service->derive($database, $stable, $season->id()));
    }

    public function testTraitFamiliesRespectPositionAndFootEvidence(): void
    {
        [$services, $database, $season] = $this->scenario('p2022-families');
        $players = new PlayerRepository($database);
        $creator = $this->player('p2022-creator', 'CM', new PlayerAttributeSet(70, 65, 85, 75, 65, 75));
        $defender = $this->player('p2022-defender', 'CB', new PlayerAttributeSet(60, 45, 65, 70, 88, 82));
        $twoFooted = $this->player('p2022-two-footed', 'RW', new PlayerAttributeSet(85, 78, 70, 82, 45, 75), WeakFootTier::Strong);
        $versatile = $this->player('p2022-versatile', 'CM', new PlayerAttributeSet(70, 70, 75, 75, 65, 75));
        foreach ([$creator, $defender, $twoFooted, $versatile] as $player) { $players->save($player); }
        $positionRepository = new PositionDevelopmentRepository($database, true);
        $database->transaction(function () use ($positionRepository, $versatile, $season): void {
            $state = PositionDevelopmentState::empty($versatile->id())->withProgress(PlayerPosition::AttackingMidfielder, 100, $season->startDate());
            $positionRepository->saveStateInTransaction($state);
        });
        $this->addEvidence($database, $season, $creator, 20, [75, 0, 1, 1, 0, 0, 0, 0, 0, 70, 58, 0]);
        $this->addEvidence($database, $season, $defender, 20, [75, 0, 0, 0, 0, 0, 1, 2, 1, 35, 25, 0]);
        $this->addEvidence($database, $season, $twoFooted, 10, [90, 1, 0, 3, 2, 0, 0, 0, 0, 20, 16, 0]);
        $this->addEvidence($database, $season, $versatile, 20, [75, 0, 0, 0, 0, 0, 0, 0, 0, 20, 16, 0]);

        $service = new PlayerTraitService();
        $creatorTraits = array_column($service->derive($database, $creator)['established'], 'key');
        $defenderTraits = array_column($service->derive($database, $defender)['established'], 'key');
        $footTraits = array_column($service->derive($database, $twoFooted)['established'], 'key');
        $versatileTraits = array_column($service->derive($database, $versatile)['established'], 'key');

        self::assertContains('creator', $creatorTraits);
        self::assertContains('playmaker', $creatorTraits);
        self::assertContains('ball_winner', $defenderTraits);
        self::assertContains('defensive_anchor', $defenderTraits);
        self::assertContains('two_footed', $footTraits);
        self::assertContains('versatile', $versatileTraits);
        self::assertNotContains('finisher', $defenderTraits);
        self::assertNotContains('two_footed', $creatorTraits);
    }

    public function testTraitsAreReadOnlyAndNoMatchFeedbackLoopExists(): void
    {
        [$services, $database, $season] = $this->scenario('p2022-read-only');
        $player = $this->player('p2022-read-only-player', 'ST', new PlayerAttributeSet(70, 85, 65, 70, 50, 70));
        (new PlayerRepository($database))->save($player);
        $before = $player->toArray();
        $derived = (new PlayerTraitService())->forPlayer($database, $player->id());
        $after = (new PlayerRepository($database))->get($player->id())->toArray();

        self::assertTrue($derived['derived']);
        self::assertSame($before, $after);
        self::assertSame(10, count(PlayerTrait::cases()));
    }

    public function testNpcDerivationIsCompactOnDemandAndSaveSafe(): void
    {
        [$services, $database, $season, $store] = $this->scenario('p2022-npc-read');
        $npc = $this->player('p2022-npc', 'CM', new PlayerAttributeSet(70, 65, 85, 75, 65, 75));
        (new PlayerRepository($database))->save($npc);
        $this->addEvidence($database, $season, $npc, 20, [75, 0, 1, 1, 0, 0, 0, 0, 0, 70, 58, 0]);
        $tablesBefore = $database->connection()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE '%trait%'")->fetchAll();

        $service = new PlayerTraitService();
        $identity = $service->derive($database, $npc, $season->id());
        $reloaded = $store->openDatabase('p2022-npc-read');

        self::assertContains('creator', array_column($identity['established'], 'key'));
        self::assertSame($identity, $service->derive($reloaded, $npc, $season->id()));
        self::assertSame($tablesBefore, $reloaded->connection()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE '%trait%'")->fetchAll());
        self::assertSame([], $reloaded->connection()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE '%trait%'")->fetchAll());
    }

    public function testTraitsDoNotChangeCanonicalMatchRatingOrPlayerState(): void
    {
        [$services, $database] = $this->scenario('p2022-boundary');
        $player = $this->player('p2022-boundary-player', 'ST', new PlayerAttributeSet(70, 85, 65, 70, 50, 70));
        (new PlayerRepository($database))->save($player);
        $stat = new PlayerMatchStat(new MatchId('p2022-boundary-match'), $player->id(), new ClubId('arsenal'), true, true, 90, 1, 0, 3, 2);
        $ratingService = new PlayerMatchRatingService();
        $beforeRating = $ratingService->rate($stat, $player->primaryPosition());
        $beforePlayer = (new PlayerRepository($database))->get($player->id())->toArray();

        (new PlayerTraitService())->derive($database, $player);

        self::assertSame($beforeRating, $ratingService->rate($stat, $player->primaryPosition()));
        self::assertSame($beforePlayer, (new PlayerRepository($database))->get($player->id())->toArray());
    }

    private function player(string $id, string $position, PlayerAttributeSet $attributes, WeakFootTier $weakFoot = WeakFootTier::Usable): \Goal\Legacy\Modules\Player\Domain\Player
    {
        return (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test'])
            ->playerModule()->service()->create(new PlayerCreationRequest(
                $id,
                'Identity',
                'Player',
                $id,
                '2000-01-01',
                'england',
                [],
                'england',
                ['england'],
                180,
                75,
                $position,
                99,
                'regular',
                2022,
                $attributes,
                null,
                $weakFoot,
            ));
    }

    /** @param array{0:int,1:int,2:int,3:int,4:int,5:int,6:int,7:int,8:int,9:int,10:int,11:int} $line */
    private function addEvidence($database, Season $season, \Goal\Legacy\Modules\Player\Domain\Player $player, int $matches, array $line): void
    {
        $aggregates = new PlayerSeasonStatisticsRepository($database);
        [$minutes, $goals, $assists, $shots, $shotsOnTarget, $saves, $cleanSheets, $tackles, $interceptions, $passesAttempted, $passesCompleted, $blocks] = $line;
        for ($round = 1; $round <= $matches; ++$round) {
            $match = new GameMatch(new MatchId('p2022-' . $player->id()->value() . '-' . $round), new CompetitionId('premier-league'), $season->id(), $round, SimulationDate::fromIsoString('2024-08-01')->addDays($round), new ClubId('arsenal'), new ClubId('chelsea'));
            $stat = new PlayerMatchStat($match->id(), $player->id(), new ClubId('arsenal'), true, true, $minutes, $goals, $assists, $shots, $shotsOnTarget, $saves, $cleanSheets, $tackles, $interceptions, $blocks, $passesAttempted, $passesCompleted);
            $aggregates->addMatchInTransaction($match, [$stat], [$player->id()->value() => $player->primaryPosition()]);
        }
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season,3:SqliteSaveStore} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2022022, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
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
