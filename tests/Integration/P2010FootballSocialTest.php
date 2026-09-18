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
use Goal\Legacy\Modules\Player\Domain\CareerEvent;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Persistence\CareerEventRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class P2010FootballSocialTest extends TestCase
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

    public function testSocialStateIsControlledBoundedDeterministicAndPersistent(): void
    {
        [$services, $database, $season, $store] = $this->scenario('p2-010-social');
        $players = $services->playerModule()->service();
        $controlled = $players->create(new PlayerCreationRequest('p2010-controlled', 'Alex', 'Rivera', 'Alex Rivera', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 90, 'regular', 40, new PlayerAttributeSet(64, 64, 64, 64, 64, 64)));
        $teammate = $players->create(new PlayerCreationRequest('p2010-teammate', 'Mika', 'Stone', 'Mika Stone', '2003-01-01', 'england', [], 'england', ['england'], 182, 78, 'CM', 90, 'regular', 40, new PlayerAttributeSet(72, 72, 72, 72, 72, 72)));
        $players->repository($database)->save($controlled);
        $players->repository($database)->save($teammate);
        $club = new ClubSquadMembership(new ClubId('arsenal'), $controlled->id(), $season->id(), SquadRole::Regular);
        $players->initializeCareer($database, $controlled, new CareerPlayerReference(new CareerId('p2010-career'), $controlled->id(), $season->startDate()), $club);
        $services->clubModule()->service()->squadRepository($database)->save(new ClubSquadMembership(new ClubId('arsenal'), $teammate->id(), $season->id(), SquadRole::KeyPlayer));

        $social = $players->socialService();
        $initial = $social->context($database, $controlled->id());
        self::assertSame('Prospect', $initial['public_profile_label']);
        self::assertSame(1, (int) $database->connection()->query('SELECT COUNT(*) FROM player_social_states')->fetchColumn());

        $social->recordTransferRequest($database, $controlled->id(), SimulationDate::fromIsoString('2024-08-15'));
        $requested = $social->context($database, $controlled->id());
        $social->recordTransferRequest($database, $controlled->id(), SimulationDate::fromIsoString('2024-08-15'));
        self::assertSame($requested, $social->context($database, $controlled->id()));
        $social->recordTransferWithdrawal($database, $controlled->id(), SimulationDate::fromIsoString('2024-08-20'));
        self::assertGreaterThanOrEqual($requested['supporter_score'], $social->context($database, $controlled->id())['supporter_score']);

        $event = CareerEvent::pending('p2010-event', $controlled->id(), $season->id(), SimulationDate::fromIsoString('2024-08-21'), 'p2010-event-source', 'teammates', 'social-position-competition', 'A football moment', 'A meaningful football moment.', [['id' => 'mentor', 'label' => 'Listen', 'history' => 'Built a mentor relationship', 'social' => ['relationship_type' => 'mentor', 'relationship_context' => 'A senior teammate became a mentor.', 'history' => true]]], ['club_id' => 'arsenal']);
        $database->transaction(function () use ($database, $event): void { (new CareerEventRepository($database))->saveInTransaction($event); });
        $experience = $players->careerExperienceService();
        $experience->resolve($database, $event->id(), 1, SimulationDate::fromIsoString('2024-08-21'));
        $experience->resolve($database, $event->id(), 1, SimulationDate::fromIsoString('2024-08-21'));
        self::assertCount(1, $social->relationships($database, $controlled->id()));
        self::assertSame('mentor', $social->relationships($database, $controlled->id())[0]['type']);
        $social->recordTransfer($database, $controlled->id(), 'arsenal', null, SimulationDate::fromIsoString('2024-08-22'));
        self::assertNull($social->context($database, $controlled->id())['current_club_id']);
        self::assertSame('No active Club manager', $social->context($database, $controlled->id())['manager_relationship']);

        $stateRows = (int) $database->connection()->query('SELECT COUNT(*) FROM player_social_states')->fetchColumn();
        $readState = $social->context($database, $controlled->id());
        $social->relationships($database, $controlled->id());
        $social->history($database, $controlled->id());
        self::assertSame($stateRows, (int) $database->connection()->query('SELECT COUNT(*) FROM player_social_states')->fetchColumn());
        self::assertNotEmpty($readState['public_profile_label']);
        self::assertSame(0, (int) $database->connection()->query('SELECT COUNT(*) FROM player_social_states WHERE player_id = ' . $database->connection()->quote($teammate->id()->value()))->fetchColumn());

        $reopened = $store->openDatabase('p2-010-social');
        self::assertSame($social->context($database, $controlled->id()), $social->context($reopened, $controlled->id()));
        self::assertSame($social->relationships($database, $controlled->id()), $social->relationships($reopened, $controlled->id()));
    }

    /** @return array{0: \Goal\Legacy\Core\Bootstrap\CoreServices,1: \Goal\Legacy\Core\Persistence\DatabaseInterface,2: Season,3: SqliteSaveStore} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2040, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true); $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $season, $store];
    }
}
