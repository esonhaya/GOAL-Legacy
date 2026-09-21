<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\Club\ClubFixtureContextService;
use Goal\Legacy\Modules\Club\ClubRivalryCatalog;
use Goal\Legacy\Modules\Competition\Persistence\CompetitionRepository;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use Goal\Legacy\Modules\Player\EchoService;
use PHPUnit\Framework\TestCase;

final class P2031RivalryTest extends TestCase
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

    public function testCanonicalPairsAreSymmetricAndDoNotCreateSaveRows(): void
    {
        [$services, $database] = $this->scenario('p2031-catalog');
        $clubIds = array_map(
            static fn ($club): string => $club->id()->value(),
            $services->clubModule()->service()->repository($database)->all(),
        );

        self::assertSame([], ClubRivalryCatalog::validate($clubIds));
        self::assertSame(18, count(ClubRivalryCatalog::pairs()));
        self::assertSame(198, count($clubIds));
        self::assertSame(ClubRivalryCatalog::DERBY_RIVALRY, ClubRivalryCatalog::relationship('arsenal', 'tottenham-hotspur')['type']);
        self::assertSame(
            ClubRivalryCatalog::relationship('arsenal', 'tottenham-hotspur'),
            ClubRivalryCatalog::relationship('tottenham-hotspur', 'arsenal'),
        );
        self::assertSame(ClubRivalryCatalog::NONE, ClubRivalryCatalog::relationship('arsenal', 'chelsea')['type']);
        self::assertFalse($database->connection()->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name LIKE '%rival%'")->fetchColumn());
    }

    public function testFixtureContextKeepsRivalrySeasonStakesAndFormerClubFactsSeparate(): void
    {
        [$services, $database, $season] = $this->scenario('p2031-context');
        $competition = (new CompetitionRepository($database))->get('premier-league');
        $match = new GameMatch(
            new MatchId('p2031-context-match'),
            $competition->id(),
            $season->id(),
            4,
            SimulationDate::fromIsoString('2024-09-21'),
            new \Goal\Legacy\Modules\Club\Domain\ClubId('arsenal'),
            new \Goal\Legacy\Modules\Club\Domain\ClubId('tottenham-hotspur'),
            MatchStatus::Scheduled,
        );
        $context = (new ClubFixtureContextService())->forMatch(
            $match,
            $competition,
            ['match_id' => $match->id()->value(), 'reason' => 'title_rival'],
            ['tottenham-hotspur', 'everton'],
            'arsenal',
        );

        self::assertSame(ClubRivalryCatalog::DERBY_RIVALRY, $context['category']);
        self::assertTrue($context['is_derby']);
        self::assertTrue($context['is_rivalry']);
        self::assertContains('DERBY', $context['labels']);
        self::assertContains('RIVALRY', $context['labels']);
        self::assertContains('SEASON STAKES', $context['labels']);
        self::assertContains('FORMER CLUB', $context['labels']);
        self::assertSame('title_rival', $context['season_stakes']['reason']);
        self::assertSame('tottenham-hotspur', $context['former_club']['club_id']);
        self::assertFalse($context['simulation_effect']);
        self::assertArrayNotHasKey('importance_score', $context);
        self::assertArrayNotHasKey('rivalry_score', $context);
    }

    public function testEchoOnlySurfacesARealNonDrawRivalryResult(): void
    {
        $echo = new EchoService();

        self::assertNull($echo->match(['appeared' => true, 'rivalry' => true, 'result' => 'draw']));
        self::assertSame(
            ['kind' => 'match_result', 'importance' => 'notable', 'response' => 'victory'],
            $echo->match(['appeared' => true, 'rivalry' => true, 'result' => 'win']),
        );
        self::assertNull($echo->match(['appeared' => true, 'rivalry' => false, 'result' => 'win', 'important_match' => false]));
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2026031, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $season];
    }
}
