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
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractException;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Contract\Domain\ContractStatus;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Transfer\Domain\Transfer;
use Goal\Legacy\Modules\Transfer\Domain\TransferExecutionTerms;
use Goal\Legacy\Modules\Transfer\Domain\TransferException;
use Goal\Legacy\Modules\Transfer\Domain\TransferId;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class Domain005Test extends TestCase
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

    public function testTransferClosesAndCreatesAllRelationshipsAndPreservesCareerIdentity(): void
    {
        [$services, $database, $season] = $this->scenario('domain-005-vertical');
        $playerService = $services->playerModule()->service();
        $player = $playerService->create(new PlayerCreationRequest('domain-005-player', 'Domain', 'Transfer', null, '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 88, 'regular', 42));
        $playerService->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('domain-005-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id()));
        $contractService = $services->contractModule()->service();
        $contractService->save($database, $contractService->create(new ContractCreationRequest(new ContractId('domain-005-source-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2025-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        $registrations = $services->competitionModule()->service()->registrationRepository($database);
        $registrations->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));
        $transfer = new Transfer(new TransferId('domain-005-transfer'), $player->id(), new ClubId('arsenal'), new ClubId('chelsea'), $season->id(), 2500, SimulationDate::fromIsoString('2024-08-02'));
        $services->transferModule()->service()->save($database, $transfer);
        $completed = $services->transferModule()->service()->execute($database, $transfer, new TransferExecutionTerms(new ContractId('domain-005-destination-contract'), SimulationDate::fromIsoString('2025-06-30'), 200));

        self::assertSame('completed', $completed->status()->value);
        self::assertSame(ContractStatus::Terminated, $contractService->repository($database)->get('domain-005-source-contract')->status());
        self::assertSame('chelsea', $contractService->repository($database)->get('domain-005-destination-contract')->clubId()->value());
        self::assertCount(0, $services->clubModule()->service()->squadRepository($database)->byClub('arsenal', $season->id()));
        self::assertSame(['chelsea'], array_map(static fn ($membership): string => $membership->clubId()->value(), $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $season->id())));
        self::assertSame([
            ['domestic-cup-england', 'chelsea'],
            ['premier-league', 'chelsea'],
        ], array_map(static fn ($registration): array => [$registration->competitionId()->value(), $registration->clubId()->value()], $registrations->byPlayer($player->id())));
        self::assertSame('domain-005-player', $playerService->careerRepository($database)->get('domain-005-career')->playerId()->value());
        self::assertSame('completed', $services->transferModule()->service()->repository($database)->get('domain-005-transfer')->status()->value);
    }

    public function testWorldAdvancementExpiresContractWithoutInventingSquadRemoval(): void
    {
        [$services, $database, $season] = $this->scenario('domain-005-expiry');
        $playerService = $services->playerModule()->service();
        $player = $playerService->create(new PlayerCreationRequest('domain-005-expiry-player', 'Expiry', 'Player', null, '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 80, 'regular', 2));
        $playerService->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('domain-005-expiry-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id()));
        $contractService = $services->contractModule()->service();
        $contractService->save($database, $contractService->create(new ContractCreationRequest(new ContractId('domain-005-expiry-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2024-08-02'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        $services->worldModule()->service()->advanceToDate($database, 'domain-005-expiry', SimulationDate::fromIsoString('2024-08-03'));
        self::assertSame(ContractStatus::Expired, $contractService->repository($database)->get('domain-005-expiry-contract')->status());
        self::assertCount(1, $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $season->id()));
    }

    public function testTransferFailureRollsBackContractSquadAndRegistrationMutations(): void
    {
        [$services, $database, $season] = $this->scenario('domain-005-rollback');
        $playerService = $services->playerModule()->service();
        $player = $playerService->create(new PlayerCreationRequest('domain-005-rollback-player', 'Rollback', 'Player', null, '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 80, 'regular', 3));
        $playerService->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('domain-005-rollback-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id()));
        $contractService = $services->contractModule()->service();
        $contractService->save($database, $contractService->create(new ContractCreationRequest(new ContractId('domain-005-rollback-source'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2025-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        $registrations = $services->competitionModule()->service()->registrationRepository($database);
        $registrations->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));
        $conflictPlayer = $playerService->create(new PlayerCreationRequest('domain-005-conflict-player', 'Conflict', 'Player', null, '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 80, 'regular', 4));
        $playerService->repository($database)->save($conflictPlayer);
        $contractService->save($database, $contractService->create(new ContractCreationRequest(new ContractId('domain-005-conflicting-destination-id'), $conflictPlayer->id(), new ClubId('chelsea'), SimulationDate::fromIsoString('2025-01-01'), SimulationDate::fromIsoString('2025-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        $transfer = new Transfer(new TransferId('domain-005-rollback-transfer'), $player->id(), new ClubId('arsenal'), new ClubId('chelsea'), $season->id(), 0, SimulationDate::fromIsoString('2024-08-02'));
        $services->transferModule()->service()->save($database, $transfer);
        $this->expectException(ContractException::class);
        try {
            $services->transferModule()->service()->execute($database, $transfer, new TransferExecutionTerms(new ContractId('domain-005-conflicting-destination-id'), SimulationDate::fromIsoString('2025-06-30'), 200));
        } finally {
            self::assertSame(ContractStatus::Active, $contractService->repository($database)->get('domain-005-rollback-source')->status());
            self::assertCount(1, $services->clubModule()->service()->squadRepository($database)->byClub('arsenal', $season->id()));
            self::assertCount(0, $services->clubModule()->service()->squadRepository($database)->byClub('chelsea', $season->id()));
            self::assertSame(['arsenal'], array_map(static fn ($registration): string => $registration->clubId()->value(), $registrations->byPlayer($player->id())));
            self::assertSame('agreed', $services->transferModule()->service()->repository($database)->get('domain-005-rollback-transfer')->status()->value);
        }
    }

    /** @return array{0: \Goal\Legacy\Core\Bootstrap\CoreServices, 1: \Goal\Legacy\Core\Persistence\DatabaseInterface, 2: Season} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $calendar = $services->worldModule()->service()->calendar();
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $world = new World(new WorldId($id), $id, 2025005, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true); $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);
        return [$services, $database, $season];
    }
}
