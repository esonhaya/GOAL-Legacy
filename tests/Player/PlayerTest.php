<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Player;

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\SqliteDatabase;
use Goal\Legacy\Core\Persistence\SqlProfiler;
use Goal\Legacy\Modules\Club\Domain\Club;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Club\Persistence\ClubRepository;
use Goal\Legacy\Modules\Club\Persistence\ClubSquadRepository;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Nation\Domain\Nation;
use Goal\Legacy\Modules\Nation\Domain\NationId;
use Goal\Legacy\Modules\Nation\Persistence\NationRepository;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\DevelopmentProfile;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\PlayerException;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Domain\SquadMembershipException;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Persistence\SeasonRepository;
use PHPUnit\Framework\TestCase;

final class PlayerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('PDO SQLite extension is unavailable.');
        }
    }

    public function testCreationIsDeterministicAndDerivesOverallRating(): void
    {
        $service = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test'])->playerModule()->service();
        $request = $this->request();
        $first = $service->create($request);
        $second = $service->create($request);

        self::assertSame($first->toArray(), $second->toArray());
        self::assertSame('career-player', $first->id()->value());
        self::assertSame(['england', 'france'], array_map(static fn (NationId $id): string => $id->value(), $first->nationalityIds()));
        self::assertSame(PlayerPosition::CentralMidfielder, $first->primaryPosition());
        self::assertSame(DevelopmentProfile::Regular, $first->developmentProfile());
        self::assertSame(70, $first->overallRating());
        self::assertLessThanOrEqual($first->potential(), $first->overallRating());
    }

    public function testGeneratedAttributesAreBoundedAndProfileDeterministic(): void
    {
        $service = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test'])->playerModule()->service();
        $late = $service->create(new PlayerCreationRequest('late-player', 'Late', 'Player', null, '2004-01-01', 'england', [], null, null, 175, 70, 'ST', 80, 'late_bloomer', 7));
        $prodigy = $service->create(new PlayerCreationRequest('prodigy-player', 'Prodigy', 'Player', null, '2004-01-01', 'england', [], null, null, 175, 70, 'ST', 80, 'prodigy', 7));

        self::assertLessThan($prodigy->overallRating(), $late->overallRating());
        foreach ($prodigy->attributes()->toArray() as $value) {
            self::assertGreaterThanOrEqual(0, $value);
            self::assertLessThanOrEqual(80, $value);
        }
    }

    public function testCreationRejectsUnknownNationInvalidPositionAndDuplicateNationality(): void
    {
        $service = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test'])->playerModule()->service();
        try {
            $service->create(new PlayerCreationRequest('unknown-nation', 'Unknown', 'Nation', null, '2004-01-01', 'missing', [], null, null, 175, 70, 'ST', 80, 'regular'));
            self::fail('Unknown Nation should be rejected.');
        } catch (PlayerException) {
            self::assertTrue(true);
        }
        try {
            $service->create(new PlayerCreationRequest('bad-position', 'Bad', 'Position', null, '2004-01-01', 'england', [], null, null, 175, 70, 'LIBERO', 80, 'regular'));
            self::fail('Unknown position should be rejected.');
        } catch (PlayerException) {
            self::assertTrue(true);
        }
        $this->expectException(PlayerException::class);
        $service->create(new PlayerCreationRequest('duplicate-nation', 'Duplicate', 'Nation', null, '2004-01-01', 'england', ['england'], null, null, 175, 70, 'ST', 80, 'regular'));
    }

    public function testCreationRejectsInvalidPhysicalRangesAndPotentialRelationship(): void
    {
        $service = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test'])->playerModule()->service();
        foreach ([
            new PlayerCreationRequest('short-player', 'Short', 'Player', null, '2004-01-01', 'england', [], null, null, 119, 70, 'ST', 80, 'regular'),
            new PlayerCreationRequest('heavy-player', 'Heavy', 'Player', null, '2004-01-01', 'england', [], null, null, 175, 201, 'ST', 80, 'regular'),
            new PlayerCreationRequest('over-potential', 'Over', 'Potential', null, '2004-01-01', 'england', [], null, null, 175, 70, 'ST', 80, 'regular', 0, new PlayerAttributeSet(90, 90, 90, 90, 90, 90)),
        ] as $request) {
            try {
                $service->create($request);
                self::fail('Invalid Player creation input should be rejected.');
            } catch (PlayerException) {
                self::assertTrue(true);
            }
        }
    }

    public function testPlayerRepositoryRoundTripsUpdatesAndQueriesByNation(): void
    {
        $database = new SqliteDatabase(':memory:');
        $nations = new NationRepository($database);
        $nations->save($this->nation('england'));
        $nations->save($this->nation('france'));
        $player = $this->player();
        $repository = new PlayerRepository($database);
        $repository->save($player);
        self::assertTrue($repository->exists($player->id()));
        self::assertSame($player->toArray(), $repository->get($player->id())->toArray());
        self::assertSame(['career-player'], array_map(static fn (Player $value): string => $value->id()->value(), $repository->byNation('france')));

        $updated = new Player(new PlayerId('career-player'), 'Updated', 'Player', 'Updated Player', $player->birthDate(), $player->primaryNationId(), $player->secondaryNationIds(), $player->birthNationId(), $player->eligibilityNationIds(), 181, 76, $player->primaryPosition(), new PlayerAttributeSet(72, 70, 68, 71, 66, 73), 80, $player->developmentProfile(), $player->creationSeed());
        $repository->save($updated);
        self::assertSame('Updated', $repository->get('career-player')->firstName());
    }

    public function testAllUsesBoundedBulkHydrationInsteadOfPerPlayerReads(): void
    {
        $profiler = new SqlProfiler();
        $database = new SqliteDatabase(':memory:', $profiler);
        $nations = new NationRepository($database);
        $nations->save($this->nation('england'));
        $nations->save($this->nation('france'));
        $repository = new PlayerRepository($database);
        $repository->save($this->player());
        $repository->save(new Player(new PlayerId('second-player'), 'Second', 'Player', 'Second Player', SimulationDate::fromIsoString('2004-01-01'), new NationId('england'), [], new NationId('england'), [new NationId('england')], 180, 75, PlayerPosition::CentralMidfielder, new PlayerAttributeSet(60, 60, 60, 60, 60, 60), 80, DevelopmentProfile::Regular, 43));
        $profiler->reset();

        self::assertCount(2, $repository->all());
        $queries = $profiler->snapshot()['queries'];
        self::assertSame(0, array_sum(array_map(static fn (array $query): int => str_contains((string) $query['fingerprint'], 'SELECT * FROM player_records WHERE id = :id') ? (int) $query['calls'] : 0, $queries)));
        self::assertSame(1, array_sum(array_map(static fn (array $query): int => str_contains((string) $query['fingerprint'], 'SELECT * FROM player_records WHERE id IN') ? (int) $query['calls'] : 0, $queries)));
    }

    public function testSeasonBoundSquadAndCareerReferencePersistWithoutDuplicatingPlayer(): void
    {
        $database = new SqliteDatabase(':memory:');
        $nationRepository = new NationRepository($database);
        $nationRepository->save($this->nation('england'));
        $nationRepository->save($this->nation('france'));
        $player = $this->player();
        (new PlayerRepository($database))->save($player);
        (new ClubRepository($database))->save($this->club());
        (new SeasonRepository($database))->save($this->season());
        $membership = new ClubSquadMembership(new ClubId('test-club'), $player->id(), new SeasonId('season-2024-25'));
        $squad = new ClubSquadRepository($database);
        $squad->save($membership);
        self::assertSame(['test-club'], array_map(static fn (ClubSquadMembership $value): string => $value->clubId()->value(), $squad->byPlayer($player->id())));

        $career = new CareerPlayerReference(new CareerId('test-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31'));
        $careers = new CareerPlayerRepository($database);
        $careers->save($career);
        self::assertSame($career->toArray(), $careers->get('test-career')->toArray());
        self::assertArrayNotHasKey('first_name', $career->toArray());

        $this->expectException(SquadMembershipException::class);
        $squad->save($membership);
    }

    private function request(): PlayerCreationRequest
    {
        return new PlayerCreationRequest('career-player', 'Alex', 'Example', 'Alex Example', '2005-01-01', 'england', ['france'], 'england', ['france', 'england'], 180, 75, 'CM', 80, 'regular', 42, new PlayerAttributeSet(70, 70, 70, 70, 70, 70));
    }

    private function player(): Player
    {
        return new Player(new PlayerId('career-player'), 'Alex', 'Example', 'Alex Example', SimulationDate::fromIsoString('2005-01-01'), new NationId('england'), [new NationId('france')], new NationId('england'), [new NationId('england'), new NationId('france')], 180, 75, PlayerPosition::CentralMidfielder, new PlayerAttributeSet(70, 70, 70, 70, 70, 70), 80, DevelopmentProfile::Regular, 42);
    }

    private function nation(string $id): Nation
    {
        return new Nation(new NationId($id), ucfirst($id), ucfirst($id), strtoupper(substr($id, 0, 3)), 'europe', $id, 'association-' . $id, 'test-package', '1.0.0', 1);
    }

    private function club(): Club
    {
        return new Club(new ClubId('test-club'), 'Test Club', 'Test', null, new NationId('england'), 'London', 1900, 'Test Ground', 'red and white', 'Core values', 'Technical football', 'Current expression', 50, 50, 'test-package', '1.0.0', 1);
    }

    private function season(): Season
    {
        return new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
    }
}
