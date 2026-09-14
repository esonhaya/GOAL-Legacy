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
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Persistence\CareerOpportunityRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldException;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class Domain019Test extends TestCase
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

    public function testControlledPlayerMustResolveBoundaryDecisionBeforeRenewal(): void
    {
        [$services, $database, $season, $store, $player] = $this->scenario('domain-019-renewal');
        $world = $services->worldModule()->service();
        $world->advanceToDate($database, 'domain-019-renewal', SimulationDate::fromIsoString('2025-06-01'));
        $next = $world->seasonRepository($database)->get('season-2025-26');
        $opportunities = (new CareerOpportunityRepository($database))->openForPlayer($player->id());
        self::assertCount(1, $opportunities);
        self::assertSame('contract_renewal', $opportunities[0]->type()->value);
        $this->expectException(WorldException::class);
        $world->advanceToDate($database, 'domain-019-renewal', $next->startDate());
    }

    public function testControlledPlayerCanAcceptRenewalAndReload(): void
    {
        [$services, $database, $season, $store, $player] = $this->scenario('domain-019-accept');
        $world = $services->worldModule()->service();
        $world->advanceToDate($database, 'domain-019-accept', SimulationDate::fromIsoString('2025-06-01'));
        $opportunity = (new CareerOpportunityRepository($database))->openForPlayer($player->id())[0];
        $resolved = $services->transferModule()->service()->careerMovement()->resolveContractDecision($database, $opportunity->id(), 'renew-current-club', SimulationDate::fromIsoString('2025-06-01'));
        self::assertSame(CareerOpportunityStatus::Resolved, $resolved->status());
        $world->advanceToDate($database, 'domain-019-accept', SimulationDate::fromIsoString('2025-08-01'));
        unset($database);
        $database = $store->openDatabase('domain-019-accept');
        $next = new SeasonId('season-2025-26');
        self::assertSame('arsenal', $services->contractModule()->service()->repository($database)->activeForPlayer($player->id())?->clubId()->value());
        self::assertSame('arsenal', $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $next)[0]->clubId()->value());
        self::assertNotEmpty($services->competitionModule()->service()->registrationRepository($database)->byPlayer($player->id()));
        self::assertSame(CareerOpportunityStatus::Resolved, (new CareerOpportunityRepository($database))->get($opportunity->id())->status());
    }

    public function testControlledPlayerCanDeclineAndRemainAnActiveFreePlayer(): void
    {
        [$services, $database, $season, $store, $player] = $this->scenario('domain-019-decline');
        $world = $services->worldModule()->service();
        $world->advanceToDate($database, 'domain-019-decline', SimulationDate::fromIsoString('2025-06-01'));
        $opportunity = (new CareerOpportunityRepository($database))->openForPlayer($player->id())[0];
        $services->transferModule()->service()->careerMovement()->resolveContractDecision($database, $opportunity->id(), 'enter-free-agency', SimulationDate::fromIsoString('2025-06-01'));
        $world->advanceToDate($database, 'domain-019-decline', SimulationDate::fromIsoString('2025-08-01'));
        $next = new SeasonId('season-2025-26');
        self::assertSame('active', $services->playerModule()->service()->repository($database)->get($player->id())->careerState()->value);
        self::assertSame([], $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $next));
        self::assertNull($services->contractModule()->service()->repository($database)->activeForPlayer($player->id()));
    }

    public function testControlledPlayerCanAcceptAnExternalFreeSigningOffer(): void
    {
        [$services, $database, $season] = $this->scenario('domain-019-external');
        $vacancy = $services->clubModule()->service()->squadRepository($database)->byClub(new ClubId('manchester-city'), $season->id())[0];
        $services->clubModule()->service()->squadRepository($database)->remove($vacancy);
        $world = $services->worldModule()->service();
        $world->advanceToDate($database, 'domain-019-external', SimulationDate::fromIsoString('2025-06-01'));
        $player = $services->playerModule()->service()->repository($database)->get(new PlayerId('domain-019-player'));
        $opportunity = (new CareerOpportunityRepository($database))->openForPlayer($player->id())[0];
        $external = array_values(array_filter($opportunity->context()['options'], static fn (array $option): bool => $option['kind'] === 'sign_with_club'));
        self::assertNotEmpty($external);
        $resolved = $services->transferModule()->service()->careerMovement()->resolveContractDecision($database, $opportunity->id(), $external[0]['id'], SimulationDate::fromIsoString('2025-06-01'));
        self::assertSame(CareerOpportunityStatus::Resolved, $resolved->status());
        $world->advanceToDate($database, 'domain-019-external', SimulationDate::fromIsoString('2025-08-01'));
        self::assertSame($external[0]['club_id'], $services->contractModule()->service()->repository($database)->activeForPlayer($player->id())?->clubId()->value());
    }

    /** @return array{0:object,1:object,2:Season,3:SqliteSaveStore,4:object} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 19019, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);
        $services->playerModule()->service()->populationService()->populate($database, $season, 19019);
        $existingSquad = $services->clubModule()->service()->squadRepository($database)->byClub(new ClubId('arsenal'), $season->id())[0];
        $services->clubModule()->service()->squadRepository($database)->remove($existingSquad);
        $player = $services->playerModule()->service()->create(new PlayerCreationRequest('domain-019-player', 'Career', 'Contract', 'Career Contract', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 92, 'regular', 19019, new PlayerAttributeSet(72, 72, 72, 72, 72, 72)));
        $services->playerModule()->service()->initializeCareer($database, $player, new CareerPlayerReference(new CareerId($id . '-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Regular));
        $contracts = $services->contractModule()->service();
        $contracts->save($database, $contracts->create(new ContractCreationRequest(new ContractId($id . '-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2025-05-31'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));

        return [$services, $database, $season, $store, $player];
    }
}
