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
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
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
use Goal\Legacy\Modules\World\Persistence\PlayerCompetitionStatisticsRepository;
use PHPUnit\Framework\TestCase;

final class P2012CareerLegacyTest extends TestCase
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

    public function testAwardsUseCompactSeasonEvidenceWithoutControlledPlayerBiasAndResolveOnce(): void
    {
        [$services, $database, $season] = $this->scenario('p2012-awards');
        $playerService = $services->playerModule()->service();
        $controlled = $this->player('controlled-low', '1999-01-01', 'LW');
        $defender = $this->player('npc-defender', '1999-01-01', 'CB');
        $forward = $this->player('npc-forward', '2005-01-01', 'ST');
        $players = new PlayerRepository($database);
        $players->save($controlled);
        $players->save($defender);
        $players->save($forward);
        $playerService->initializeCareer(
            $database,
            $controlled,
            new CareerPlayerReference(new CareerId('p2012-career'), $controlled->id(), SimulationDate::fromIsoString('2024-07-31')),
            new ClubSquadMembership(new ClubId('arsenal'), $controlled->id(), $season->id(), SquadRole::Prospect),
        );

        $aggregates = new PlayerCompetitionStatisticsRepository($database);
        $positions = [
            $controlled->id()->value() => $controlled->primaryPosition(),
            $defender->id()->value() => $defender->primaryPosition(),
            $forward->id()->value() => $forward->primaryPosition(),
        ];
        for ($round = 1; $round <= 5; ++$round) {
            $match = new GameMatch(
                new MatchId('p2012-award-match-' . $round),
                new CompetitionId('premier-league'),
                $season->id(),
                $round,
                SimulationDate::fromIsoString('2024-08-' . str_pad((string) $round, 2, '0', STR_PAD_LEFT)),
                new ClubId('arsenal'),
                new ClubId('chelsea'),
            );
            $stats = [
                new PlayerMatchStat($match->id(), $controlled->id(), new ClubId('arsenal'), true, true, 90, 0, 0, 1, 0),
                new PlayerMatchStat($match->id(), $defender->id(), new ClubId('arsenal'), true, true, 90, 0, 0, 0, 0, 0, 1, 8, 8, 4, 45, 40),
                new PlayerMatchStat($match->id(), $forward->id(), new ClubId('chelsea'), true, true, 90, 1, 1, 2, 1),
            ];
            $database->transaction(function () use ($aggregates, $match, $stats, $positions): void {
                $aggregates->addMatchInTransaction($match, $stats, $positions);
            });
        }

        self::assertCount(3, $aggregates->byCompetitionSeason('premier-league', $season->id()));
        self::assertSame(5, $aggregates->byPlayer($defender->id()->value())[0]['appearances']);

        $legacyService = new CareerLegacyService(
            $services->clubModule()->service(),
            $services->nationalTeams(),
            $services->internationalCompetitions(),
            $playerService->socialService(),
        );
        $first = $legacyService->resolveCompletedSeason($database, $season);
        $repository = new CareerLegacyRepository($database);
        $awards = $repository->awardsForSeason($season->id()->value());
        $second = $legacyService->resolveCompletedSeason($database, $season);

        self::assertSame(4, $first['awards']);
        self::assertSame(0, $first['honours']);
        self::assertSame(0, $second['awards']);
        self::assertCount(4, $awards);
        self::assertSame('npc-defender', $this->winner($awards, 'PLAYER_OF_THE_SEASON'));
        self::assertSame('npc-forward', $this->winner($awards, 'YOUNG_PLAYER_OF_THE_SEASON'));
        self::assertSame('npc-forward', $this->winner($awards, 'TOP_SCORER'));
        self::assertSame('npc-forward', $this->winner($awards, 'TOP_ASSIST_PROVIDER'));
        self::assertNotSame('controlled-low', $this->winner($awards, 'PLAYER_OF_THE_SEASON'));
        self::assertSame([], $legacyService->integrity($database));
    }

    /** @param list<array<string, mixed>> $awards */
    private function winner(array $awards, string $type): string
    {
        foreach ($awards as $award) {
            if (($award['award_type'] ?? null) === $type) {
                return (string) $award['winner_player_id'];
            }
        }

        self::fail('Missing award ' . $type);
    }

    private function player(string $id, string $birthDate, string $position): \Goal\Legacy\Modules\Player\Domain\Player
    {
        return (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test'])
            ->playerModule()->service()->create(new PlayerCreationRequest(
                $id,
                'Legacy',
                'Candidate',
                $id,
                $birthDate,
                'england',
                [],
                'england',
                ['england'],
                180,
                75,
                $position,
                90,
                'regular',
                12012,
                new PlayerAttributeSet(60, 60, 60, 60, 60, 60),
            ));
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2012012, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
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
