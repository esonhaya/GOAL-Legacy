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
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunityStatus;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\CareerTransferRequestStatus;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Persistence\CareerOpportunityRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Transfer\Domain\TransferException;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class Domain021Test extends TestCase
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

    public function testRequestPersistsAndProducesOneReusableInterestDecision(): void
    {
        [$services, $database, $season, $store] = $this->scenario('domain-021-accept');
        $player = $this->controlledPlayer($services, $database, $season, 'domain-021-accept-player', 95, SimulationDate::fromIsoString('2026-05-31'));
        $this->removePosition($services, $database, 'arsenal', $season, PlayerPosition::CentralMidfielder);
        $this->removePosition($services, $database, 'manchester-city', $season, PlayerPosition::Goalkeeper);

        $movement = $services->transferModule()->service()->careerMovement();
        $requested = $movement->requestTransfer($database, $player->id(), $season, $season->startDate());
        self::assertSame(CareerTransferRequestStatus::Requested, $requested->transferRequestStatus());
        self::assertSame($season->id()->value(), $requested->transferRequestSeasonId()?->value());
        self::assertSame(CareerTransferRequestStatus::Requested, (new CareerPlayerRepository($database))->byPlayer($player->id())->transferRequestStatus());

        unset($database);
        $database = $store->openDatabase('domain-021-accept');
        self::assertSame(CareerTransferRequestStatus::Requested, (new CareerPlayerRepository($database))->byPlayer($player->id())->transferRequestStatus());
        $this->expectException(TransferException::class);
        $movement->requestTransfer($database, $player->id(), $season, $season->startDate());
    }

    public function testRequestedPlayerCanAcceptInterestThroughExistingTransferPath(): void
    {
        [$services, $database, $season] = $this->scenario('domain-021-move');
        $player = $this->controlledPlayer($services, $database, $season, 'domain-021-move-player', 95, SimulationDate::fromIsoString('2026-05-31'));
        $this->removePosition($services, $database, 'arsenal', $season, PlayerPosition::CentralMidfielder);
        $this->removePosition($services, $database, 'manchester-city', $season, PlayerPosition::Goalkeeper);
        $movement = $services->transferModule()->service()->careerMovement();
        $movement->requestTransfer($database, $player->id(), $season, $season->startDate());
        $services->clubRecruitmentService()->recruit($database, $season, $season->startDate());

        $opportunity = (new CareerOpportunityRepository($database))->openForPlayer($player->id())[0] ?? null;
        self::assertNotNull($opportunity);
        self::assertSame('player_request', $opportunity->context()['origin']);
        $option = array_values(array_filter($opportunity->context()['options'], static fn (array $candidate): bool => $candidate['kind'] === 'accept_transfer'))[0] ?? null;
        self::assertNotNull($option);
        self::assertLessThanOrEqual(3, count(array_filter($opportunity->context()['options'], static fn (array $candidate): bool => $candidate['kind'] === 'accept_transfer')));

        $resolved = $movement->resolveTransferDecision($database, $opportunity->id(), $option['id'], $season->startDate());
        self::assertSame(CareerOpportunityStatus::Resolved, $resolved->status());
        self::assertSame(CareerTransferRequestStatus::None, (new CareerPlayerRepository($database))->byPlayer($player->id())->transferRequestStatus());
        self::assertSame($option['club_id'], $services->contractModule()->service()->repository($database)->activeForPlayer($player->id())?->clubId()->value());
        self::assertSame($option['club_id'], $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $season->id())[0]->clubId()->value());
        self::assertNotEmpty($services->competitionModule()->service()->registrationRepository($database)->byPlayer($player->id()));
    }

    public function testNoInterestLeavesContractAndRequestExpiresAtNextWindow(): void
    {
        [$services, $database, $season] = $this->scenario('domain-021-no-interest');
        $player = $this->controlledPlayer($services, $database, $season, 'domain-021-no-interest-player', 30, SimulationDate::fromIsoString('2026-05-31'));
        $this->removePosition($services, $database, 'arsenal', $season, PlayerPosition::CentralMidfielder);
        $this->removePosition($services, $database, 'manchester-city', $season, PlayerPosition::Goalkeeper);
        $movement = $services->transferModule()->service()->careerMovement();
        $movement->requestTransfer($database, $player->id(), $season, $season->startDate());
        $services->clubRecruitmentService()->recruit($database, $season, $season->startDate());

        self::assertSame([], (new CareerOpportunityRepository($database))->openForPlayer($player->id()));
        self::assertSame('arsenal', $services->contractModule()->service()->repository($database)->activeForPlayer($player->id())?->clubId()->value());
        self::assertSame(CareerTransferRequestStatus::Requested, (new CareerPlayerRepository($database))->byPlayer($player->id())->transferRequestStatus());
        $next = new Season(new SeasonId('season-2025-26'), '2025/26', SimulationDate::fromIsoString('2025-08-01'), SimulationDate::fromIsoString('2026-05-31'));
        self::assertSame(1, $movement->expireStaleTransferRequests($database, $next));
        self::assertSame(CareerTransferRequestStatus::None, (new CareerPlayerRepository($database))->byPlayer($player->id())->transferRequestStatus());
    }

    public function testStayAndPreconditionsPreserveExistingCareerRules(): void
    {
        [$services, $database, $season] = $this->scenario('domain-021-rules');
        $player = $this->controlledPlayer($services, $database, $season, 'domain-021-stay-player', 95, SimulationDate::fromIsoString('2026-05-31'));
        $movement = $services->transferModule()->service()->careerMovement();
        $movement->requestTransfer($database, $player->id(), $season, $season->startDate());
        $withdrawn = $movement->withdrawTransferRequest($database, $player->id(), $season->startDate());
        self::assertSame(CareerTransferRequestStatus::None, $withdrawn->transferRequestStatus());

        $noContract = $this->controlledPlayer($services, $database, $season, 'domain-021-no-contract', 75, SimulationDate::fromIsoString('2026-05-31'), false);
        $this->expectException(TransferException::class);
        $movement->requestTransfer($database, $noContract->id(), $season, $season->startDate());
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season,3?:SqliteSaveStore} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 21021, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);
        $services->playerModule()->service()->populationService()->populate($database, $season, 21021);

        return [$services, $database, $season, $store];
    }

    private function controlledPlayer(object $services, object $database, Season $season, string $id, int $overall, SimulationDate $end, bool $withContract = true): object
    {
        $player = $services->playerModule()->service()->create(new PlayerCreationRequest($id, 'Career', 'Request', $id, '2001-01-01', 'england', [], 'england', ['england'], 190, $overall, 'GK', min(99, $overall + 4), 'regular', 21021, new PlayerAttributeSet($overall, $overall, $overall, $overall, $overall, $overall)));
        $services->playerModule()->service()->initializeCareer($database, $player, new CareerPlayerReference(new CareerId($id . '-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Regular));
        if ($withContract) {
            $contracts = $services->contractModule()->service();
            $contracts->save($database, $contracts->create(new ContractCreationRequest(new ContractId($id . '-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), $end, 100, SimulationDate::fromIsoString('2024-07-31'))));
            $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));
        }

        return $player;
    }

    private function removePosition(object $services, object $database, string $clubId, Season $season, PlayerPosition $position): void
    {
        $players = new PlayerRepository($database);
        foreach ($services->clubModule()->service()->squadRepository($database)->byClub($clubId, $season->id()) as $membership) {
            if ($players->get($membership->playerId())->primaryPosition() !== $position) {
                continue;
            }
            foreach ($services->competitionModule()->service()->registrationRepository($database)->byPlayer($membership->playerId()) as $registration) {
                if ($registration->seasonId()->value() === $season->id()->value() && $registration->clubId()->value() === $clubId) {
                    $services->competitionModule()->service()->registrationRepository($database)->unregister($registration);
                }
            }
            $services->clubModule()->service()->squadRepository($database)->remove($membership);
            return;
        }
        self::fail(sprintf('No %s in %s squad.', $position->value, $clubId));
    }
}
