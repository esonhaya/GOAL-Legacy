<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\SqliteDatabase;
use Goal\Legacy\Core\Persistence\SqlProfiler;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class P2028ConsolidationTest extends TestCase
{
    public function testCareerMatchReadsUseStableBatchesAndIgnoreUnknownIds(): void
    {
        $database = new SqliteDatabase(':memory:');
        $repository = new MatchRepository($database);
        $insert = $database->connection()->prepare(
            'INSERT INTO match_records (id, competition_id, season_id, round_number, scheduled_date, home_club_id, away_club_id, status, home_goals, away_goals) '
            . 'VALUES (:id, :competition_id, :season_id, :round_number, :scheduled_date, :home_club_id, :away_club_id, :status, NULL, NULL)'
        );
        for ($index = 1; $index <= 401; ++$index) {
            $insert->execute([
                'id' => 'p2028-match-' . $index,
                'competition_id' => 'competition',
                'season_id' => 'season',
                'round_number' => $index,
                'scheduled_date' => '2024-08-' . str_pad((string) (($index % 28) + 1), 2, '0', STR_PAD_LEFT),
                'home_club_id' => 'home',
                'away_club_id' => 'away',
                'status' => 'scheduled',
            ]);
        }

        $matches = $repository->byIds(['p2028-match-401', 'missing', 'p2028-match-1', 'p2028-match-401']);

        self::assertCount(2, $matches);
        self::assertSame(['p2028-match-1', 'p2028-match-401'], array_map(static fn ($match): string => $match->id()->value(), $matches));
        self::assertSame([], $repository->byIds([]));
    }

    public function testWorldLoadIsReadSafeAfterSchemaInitialization(): void
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(
            new SeasonId('season-2024-25'),
            '2024/25',
            SimulationDate::fromIsoString('2024-08-01'),
            SimulationDate::fromIsoString('2025-05-31'),
        );
        $world = new World(
            new WorldId('p2028-read-safe'),
            'P2-028 read-safe',
            2028028,
            new DateTimeImmutable('@0'),
            $services->worldModule()->service()->calendar()->timeAt(SimulationDate::fromIsoString('2024-07-31')),
            $season->id(),
            array_map(static fn ($nation): string => $nation->id()->value(), $nations),
            array_map(static fn ($competition): string => $competition->id()->value(), $competitions),
            $services->contentPackages()->selectedIds(),
        );
        $profiler = new SqlProfiler();
        $database = new SqliteDatabase(':memory:', $profiler);
        $worldService = $services->worldModule()->service();
        $worldService->initialize($database, $world, $season);
        $profiler->reset();

        self::assertSame($world->toArray(), $worldService->load($database, $world->id())->toArray());
        $writes = array_values(array_filter(
            $profiler->snapshot()['queries'],
            static fn (array $query): bool => preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\\b/i', (string) $query['fingerprint']) === 1,
        ));
        self::assertSame([], $writes);
    }
}
