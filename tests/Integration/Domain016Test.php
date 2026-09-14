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
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Transfer\Domain\TransferStatus;
use Goal\Legacy\Modules\Transfer\Persistence\TransferRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class Domain016Test extends TestCase
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

    public function testNpcMovementIsNeedDrivenAndRetryCannotMovePlayersTwice(): void
    {
        [$services, $database, $season] = $this->scenario('domain-016-window');
        $squads = $services->clubModule()->service()->squadRepository($database);
        $players = new PlayerRepository($database);
        $registrations = $services->competitionModule()->service()->registrationRepository($database);
        $vacancy = null;
        foreach ($squads->byClub('chelsea', $season->id()) as $membership) {
            $player = $players->get($membership->playerId());
            if ($player->primaryPosition() === PlayerPosition::CentralMidfielder) {
                $vacancy = [$membership, $player];
                break;
            }
        }
        self::assertNotNull($vacancy);
        [$membership, $player] = $vacancy;
        foreach ($registrations->byPlayer($player->id()) as $registration) {
            if ($registration->seasonId()->value() === $season->id()->value() && $registration->clubId()->value() === 'chelsea') {
                $registrations->unregister($registration);
            }
        }
        $squads->remove(new ClubSquadMembership(new ClubId('chelsea'), $player->id(), $season->id(), $membership->role()));

        $first = $services->clubRecruitmentService()->recruit($database, $season, $season->startDate());
        $second = $services->clubRecruitmentService()->recruit($database, $season, $season->startDate());

        self::assertGreaterThanOrEqual(1, $first['npc_transfers']);
        self::assertGreaterThanOrEqual(1, $first['candidates_evaluated']);
        self::assertLessThanOrEqual(18, $first['movement_budget']);
        self::assertSame(0, $second['npc_transfers']);
        $transfers = array_values(array_filter((new TransferRepository($database))->all(), static fn ($transfer): bool => $transfer->seasonId()->value() === $season->id()->value() && $transfer->status() === TransferStatus::Completed));
        $counts = [];
        foreach ($transfers as $transfer) {
            $counts[$transfer->playerId()->value()] = ($counts[$transfer->playerId()->value()] ?? 0) + 1;
        }
        self::assertLessThanOrEqual(1, max($counts));
    }

    public function testCareerPlayerIsExcludedFromAutonomousMovement(): void
    {
        [$services, $database, $season] = $this->scenario('domain-016-career-firewall');
        $squads = $services->clubModule()->service()->squadRepository($database);
        $players = new PlayerRepository($database);
        $registrations = $services->competitionModule()->service()->registrationRepository($database);
        $protected = $squads->byClub('arsenal', $season->id())[0];
        (new CareerPlayerRepository($database))->save(new CareerPlayerReference(new CareerId('domain-016-career'), $protected->playerId(), $season->startDate()));
        $vacancy = $squads->byClub('chelsea', $season->id())[0];
        foreach ($registrations->byPlayer($vacancy->playerId()) as $registration) {
            if ($registration->seasonId()->value() === $season->id()->value() && $registration->clubId()->value() === 'chelsea') {
                $registrations->unregister($registration);
            }
        }
        $squads->remove($vacancy);

        $services->clubRecruitmentService()->recruit($database, $season, $season->startDate());

        self::assertSame('arsenal', $squads->byPlayer($protected->playerId(), $season->id())[0]->clubId()->value());
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 16016, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);
        $services->playerModule()->service()->populationService()->populate($database, $season, $world->universeSeed());

        return [$services, $database, $season];
    }
}
