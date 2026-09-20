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
use Goal\Legacy\Modules\Player\CareerRecoveryService;
use Goal\Legacy\Modules\Player\Domain\Injury;
use Goal\Legacy\Modules\Player\Domain\InjuryCategory;
use Goal\Legacy\Modules\Player\Domain\InjurySeverity;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Persistence\PlayerAvailabilityRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class P2026InjuryRecoveryTest extends TestCase
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

    public function testRecoveryConsumesMedicalStateWithoutCreatingASecondClock(): void
    {
        [$services, $database, , $player] = $this->scenario('p2026-active');
        $this->saveInjury($database, $player->id(), 'p2026-active-injury', '2024-08-01', InjurySeverity::Major);
        $recovery = new CareerRecoveryService();
        $before = (int) $database->connection()->query('SELECT COUNT(*) FROM player_injuries')->fetchColumn();

        $context = $recovery->context($database, $player->id(), SimulationDate::fromIsoString('2024-08-10'));

        self::assertSame('active', $context['status']);
        self::assertSame('REHABILITATING', $context['phase']);
        self::assertSame('MAJOR', $context['significance']);
        self::assertSame('2024-08-29', $context['medical_end_date']);
        self::assertTrue($context['visible']);
        self::assertSame($before, (int) $database->connection()->query('SELECT COUNT(*) FROM player_injuries')->fetchColumn());
        self::assertSame(0, (int) $database->connection()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE '%recovery%'")->fetchColumn());
        unset($services);
    }

    public function testUnusedBenchDoesNotCountAndSubstituteAndStarterReturnsUseCanonicalPerformance(): void
    {
        [$services, $database, $season, $player] = $this->scenario('p2026-returns');
        $this->saveInjury($database, $player->id(), 'p2026-return-one', '2024-08-01', InjurySeverity::Major);
        $unused = $this->match($database, $season, $player->id()->value(), 'p2026-unused', '2024-09-01', null);
        $recovery = new CareerRecoveryService();
        self::assertNull($recovery->returnForMatch($database, $unused, $player->id()));

        $sub = $this->match($database, $season, $player->id()->value(), 'p2026-sub', '2024-09-02', false, 30, 0);
        $subReturn = $recovery->returnForMatch($database, $sub, $player->id());
        self::assertNotNull($subReturn);
        self::assertFalse($subReturn['started']);
        self::assertSame(30, $subReturn['minutes']);
        self::assertContains('returned as a substitute', $subReturn['performance']);

        $this->saveInjury($database, $player->id(), 'p2026-return-two', '2024-10-01', InjurySeverity::Major);
        $starter = $this->match($database, $season, $player->id()->value(), 'p2026-starter', '2024-11-01', true, 1, 1);
        $starterReturn = $recovery->returnForMatch($database, $starter, $player->id());
        self::assertNotNull($starterReturn);
        self::assertTrue($starterReturn['started']);
        self::assertSame(1, $starterReturn['goals']);
        self::assertSame(1, $starterReturn['assists']);
        self::assertContains('scored on return', $starterReturn['performance']);
        self::assertContains('assisted on return', $starterReturn['performance']);
        self::assertNotSame(null, $starterReturn['rating']);
        unset($services);
    }

    public function testReturnedContextAndPresentationAreStableAcrossReload(): void
    {
        [$services, $database, $season, $player, $store, $saveId] = $this->scenario('p2026-reload');
        $this->saveInjury($database, $player->id(), 'p2026-reload-injury', '2024-08-01', InjurySeverity::Major);
        $match = $this->match($database, $season, $player->id()->value(), 'p2026-reload-match', '2024-09-02', true, 0, 0);
        $presentation = new CareerPresentationService($services);
        $view = $presentation->matchday($database, $match, $player->id()->value(), 'arsenal');
        self::assertNotNull($view['comeback']);
        self::assertSame($view['comeback'], $view['post_match']['comeback']);

        unset($database);
        $reloaded = $store->openDatabase($saveId);
        $first = (new CareerRecoveryService())->context($reloaded, $player->id(), SimulationDate::fromIsoString('2024-09-03'));
        $second = (new CareerRecoveryService())->context($reloaded, $player->id(), SimulationDate::fromIsoString('2024-09-03'));
        self::assertSame($first, $second);
        self::assertSame('RETURNED', $first['phase']);
        self::assertSame(0, (int) $reloaded->connection()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE '%rehab%'")->fetchColumn());
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season,3:\Goal\Legacy\Modules\Player\Domain\Player,4:SqliteSaveStore,5:string} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2026026, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $root = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($root, 0775, true); $this->roots[] = $root;
        $store = new SqliteSaveStore($root, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);
        $player = $services->playerModule()->service()->create(new PlayerCreationRequest('p2026-player', 'Recovery', 'Player', 'Recovery Player', '2000-01-01', 'england', [], 'england', ['england'], 180, 75, 'ST', 90, 'regular', 12026, new PlayerAttributeSet(70, 70, 70, 70, 70, 70)));
        (new PlayerRepository($database))->save($player);
        $services->playerModule()->service()->initializeCareer($database, $player, new \Goal\Legacy\Modules\Player\Domain\CareerPlayerReference(new \Goal\Legacy\Modules\Player\Domain\CareerId($id . '-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Regular));

        return [$services, $database, $season, $player, $store, $id];
    }

    private function saveInjury($database, PlayerId $playerId, string $sourceId, string $date, InjurySeverity $severity): void
    {
        $start = SimulationDate::fromIsoString($date);
        $injury = new Injury($sourceId, $playerId, 'test', $sourceId, InjuryCategory::Muscular, $severity, $start, $start->addDays($severity->durationDays()));
        $database->transaction(fn () => (new PlayerAvailabilityRepository($database))->saveInjuryInTransaction($injury));
    }

    private function match($database, Season $season, string $playerId, string $id, string $date, ?bool $started, int $goals = 0, int $assists = 0): GameMatch
    {
        $match = new GameMatch(new MatchId($id), new CompetitionId('premier-league'), $season->id(), 1, SimulationDate::fromIsoString($date), new ClubId('arsenal'), new ClubId('chelsea'));
        $match = $match->complete(new MatchResult(2, 0));
        (new MatchRepository($database))->save($match);
        if ($started !== null) {
            (new PlayerMatchStatRepository($database))->replaceForMatch([new PlayerMatchStat($match->id(), new PlayerId($playerId), new ClubId('arsenal'), true, $started, $started ? 90 : 30, $goals, $assists, max($goals, 2), max($goals, 2))]);
        }

        return $match;
    }
}
