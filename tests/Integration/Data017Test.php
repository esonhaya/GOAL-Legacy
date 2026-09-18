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
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Transfer\Domain\Transfer;
use Goal\Legacy\Modules\Transfer\Domain\TransferExecutionTerms;
use Goal\Legacy\Modules\Transfer\Domain\TransferId;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class Data017Test extends TestCase
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

    public function testSecondTierCompetitionsFixturesStandingsAndReload(): void
    {
        [$services, $database, $season] = $this->scenario('data-017-fixtures');
        $competitionService = $services->competitionModule()->service();
        $definitions = $competitionService->loadSelected();
        self::assertCount(18, $definitions);
        self::assertSame(5, count(array_filter($definitions, static fn ($definition): bool => $definition->type() === CompetitionType::DomesticLeague && $definition->tier() === 2)));

        $expected = ['championship' => 24, 'segunda-division' => 22, '2-bundesliga' => 18, 'serie-b' => 20, 'ligue-2' => 18];
        $matchService = $services->matchModule()->service();
        $membershipRepository = $services->clubModule()->service()->membershipRepository($database);
        foreach ($expected as $competitionId => $clubs) {
            self::assertCount($clubs, $membershipRepository->byCompetition($competitionId, $season->id()));
            $fixtures = $matchService->generateFixtures($database, $competitionId, $season->id());
            self::assertCount($clubs * ($clubs - 1), $fixtures);
            self::assertCount($clubs, $matchService->standings($database, $competitionId, $season->id()));
        }

        $reloaded = $services->worldModule()->service()->load($database, 'data-017-fixtures');
        self::assertSame(18, count($competitionService->repository($database)->bySeason($season->id())));
        self::assertSame($reloaded->toArray(), $services->worldModule()->service()->load($database, 'data-017-fixtures')->toArray());
        self::assertSame(2, $competitionService->repository($database)->get('championship')->tier());
    }

    public function testSecondTierPopulationAndCrossTierTransferUseExistingPaths(): void
    {
        [$services, $database, $season] = $this->scenario('data-017-transfer');
        $population = $services->playerModule()->service()->populationService()->populate($database, $season, 17017);
        self::assertSame(198, $population['clubs_populated']);
        self::assertSame(4950, $population['players_total']);
        self::assertSame(25, $population['min_squad_size']);
        self::assertSame(25, $population['max_squad_size']);

        $players = new PlayerRepository($database);
        $player = $players->get('npc-v1-blackburn-rovers-01');
        $squads = $services->clubModule()->service()->squadRepository($database);
        self::assertSame('blackburn-rovers', $squads->byPlayer($player->id(), $season->id())[0]->clubId()->value());
        $registrations = $services->competitionModule()->service()->registrationRepository($database)->byPlayer($player->id());
        self::assertSame('championship', $registrations[0]->competitionId()->value());
        $career = new CareerPlayerReference(new CareerId('data-017-second-tier-career'), $player->id(), $season->startDate());
        (new CareerPlayerRepository($database))->save($career);
        self::assertSame($player->id()->value(), (new CareerPlayerRepository($database))->get('data-017-second-tier-career')->playerId()->value());

        $transferService = $services->transferModule()->service();
        $transfer = new Transfer(new TransferId('data-017-upward-transfer'), $player->id(), new ClubId('blackburn-rovers'), new ClubId('arsenal'), $season->id(), 100, $season->startDate());
        $transferService->execute($database, $transfer, new TransferExecutionTerms(new ContractId('data-017-upward-contract'), $season->endDate()->addDays(365), 1000));
        self::assertSame('arsenal', $squads->byPlayer($player->id(), $season->id())[0]->clubId()->value());
        self::assertContains('premier-league', array_map(static fn ($registration): string => $registration->competitionId()->value(), $services->competitionModule()->service()->registrationRepository($database)->byPlayer($player->id())));

        $returnTransfer = new Transfer(new TransferId('data-017-downward-transfer'), $player->id(), new ClubId('arsenal'), new ClubId('blackburn-rovers'), $season->id(), 100, $season->startDate());
        $transferService->execute($database, $returnTransfer, new TransferExecutionTerms(new ContractId('data-017-downward-contract'), $season->endDate()->addDays(365), 1000));
        self::assertSame('blackburn-rovers', $squads->byPlayer($player->id(), $season->id())[0]->clubId()->value());
        self::assertContains('championship', array_map(static fn ($registration): string => $registration->competitionId()->value(), $services->competitionModule()->service()->registrationRepository($database)->byPlayer($player->id())));
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 17017, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
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
