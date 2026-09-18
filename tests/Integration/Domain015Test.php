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
use Goal\Legacy\Modules\Contract\Domain\ContractStatus;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
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

final class Domain015Test extends TestCase
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

    public function testFreePlayerReuseAndCheckpointRetryAreIdempotent(): void
    {
        [$services, $database, $season] = $this->scenario('domain-015-free');
        $squads = $services->clubModule()->service()->squadRepository($database);
        $players = new PlayerRepository($database);
        $contracts = $services->contractModule()->service()->repository($database);
        $registrations = $services->competitionModule()->service()->registrationRepository($database);
        $target = null;
        foreach ($squads->byClub('arsenal', $season->id()) as $membership) {
            $player = $players->get($membership->playerId());
            if ($player->primaryPosition() === PlayerPosition::CentralMidfielder) { $target = [$membership, $player]; break; }
        }
        self::assertNotNull($target);
        [$membership, $free] = $target;
        $contract = $contracts->activeForPlayer($free->id());
        self::assertNotNull($contract);
        $contracts->save($contract->terminate());
        foreach ($registrations->byPlayer($free->id()) as $registration) {
            if ($registration->seasonId()->value() === $season->id()->value() && $registration->clubId()->value() === 'arsenal') { $registrations->unregister($registration); }
        }
        $squads->remove(new ClubSquadMembership(new ClubId('arsenal'), $free->id(), $season->id(), $membership->role()));

        $first = $services->clubRecruitmentService()->recruit($database, $season, $season->startDate());
        $second = $services->clubRecruitmentService()->recruit($database, $season, $season->startDate());
        self::assertSame(1, $first['free_agents_signed']);
        self::assertSame(0, $second['free_agents_signed']);
        self::assertSame(1, count($squads->byPlayer($free->id(), $season->id())));
        self::assertSame(ContractStatus::Active, $contracts->activeForPlayer($free->id())?->status());
        self::assertCount(2, array_filter($registrations->byPlayer($free->id()), static fn (PlayerRegistration $registration): bool => $registration->seasonId()->value() === $season->id()->value() && $registration->clubId()->value() === 'arsenal'));
    }

    public function testContractedNpcMovementUsesTransferServiceAndLeavesSourcePlayable(): void
    {
        [$services, $database, $season] = $this->scenario('domain-015-transfer');
        $squads = $services->clubModule()->service()->squadRepository($database);
        $players = new PlayerRepository($database);
        $registrations = $services->competitionModule()->service()->registrationRepository($database);
        $target = null;
        foreach ($squads->byClub('chelsea', $season->id()) as $membership) {
            $player = $players->get($membership->playerId());
            if ($player->primaryPosition() === PlayerPosition::CentralMidfielder) { $target = [$membership, $player]; break; }
        }
        self::assertNotNull($target);
        [$membership, $vacancy] = $target;
        $protected = $squads->byClub('arsenal', $season->id())[0];
        (new CareerPlayerRepository($database))->save(new CareerPlayerReference(new CareerId('domain-015-career'), $protected->playerId(), $season->startDate()));
        foreach ($registrations->byPlayer($vacancy->id()) as $registration) {
            if ($registration->seasonId()->value() === $season->id()->value() && $registration->clubId()->value() === 'chelsea') { $registrations->unregister($registration); }
        }
        $squads->remove(new ClubSquadMembership(new ClubId('chelsea'), $vacancy->id(), $season->id(), $membership->role()));
        $report = $services->clubRecruitmentService()->recruit($database, $season, $season->startDate());
        self::assertGreaterThanOrEqual(1, $report['npc_transfers']);
        self::assertNotEmpty((new TransferRepository($database))->all());
        self::assertNotEmpty(array_filter((new TransferRepository($database))->all(), static fn ($transfer): bool => $transfer->status() === TransferStatus::Completed));
        self::assertGreaterThanOrEqual(11, count($squads->byClub('arsenal', $season->id())));
        self::assertCount(25, $squads->byClub('chelsea', $season->id()));
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
        $world = new World(new WorldId($id), $id, 15015, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
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
