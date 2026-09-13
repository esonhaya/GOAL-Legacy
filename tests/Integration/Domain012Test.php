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
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunityStatus;
use Goal\Legacy\Modules\Player\Persistence\CareerOpportunityRepository;
use Goal\Legacy\Modules\Transfer\Domain\TransferStatus;
use Goal\Legacy\Modules\Transfer\Domain\TransferException;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class Domain012Test extends TestCase
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

    public function testModernCompetitionDefaultAllowsFiveSubstitutions(): void
    {
        [$services, $database] = $this->scenario('domain-012-rules');

        self::assertSame(5, $services->competitionModule()->service()->repository($database)->get('premier-league')->maximumSubstitutions());
    }

    public function testCareerOfferCanBeDeclinedWithoutChangingPlayerState(): void
    {
        [$services, $database, $season, $store] = $this->scenario('domain-012-decline');
        $services->playerModule()->service()->populationService()->populate($database, $season, 12012);
        $player = $this->createCareerPlayer($services, $database, $season, 'domain-012-decline-player', 70, 92, SquadRole::Prospect);
        $movement = $services->transferModule()->service()->careerMovement();
        $date = SimulationDate::fromIsoString('2024-10-01');
        $offers = $movement->evaluate($database, $player->id(), $season->id(), $date);
        self::assertNotEmpty($offers);
        self::assertSame([], $movement->evaluate($database, $player->id(), $season->id(), $date));

        $sourceContract = $services->contractModule()->service()->repository($database)->activeForPlayer($player->id());
        self::assertNotNull($sourceContract);
        $declined = $movement->decline($database, $offers[0]->id(), $date);
        self::assertSame(CareerOpportunityStatus::Declined, $declined->status());
        self::assertSame('arsenal', $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $season->id())[0]->clubId()->value());
        self::assertSame($sourceContract->id()->value(), $services->contractModule()->service()->repository($database)->activeForPlayer($player->id())?->id()->value());
        self::assertSame([], $movement->evaluate($database, $player->id(), $season->id(), $date));

        unset($database);
        $database = $store->openDatabase('domain-012-decline');
        self::assertSame(CareerOpportunityStatus::Declined, (new CareerOpportunityRepository($database))->get($offers[0]->id())->status());
    }

    public function testCareerOfferAcceptanceUsesTransferServiceAndSurvivesReload(): void
    {
        [$services, $database, $season, $store] = $this->scenario('domain-012-accept');
        $services->playerModule()->service()->populationService()->populate($database, $season, 12012);
        $player = $this->createCareerPlayer($services, $database, $season, 'domain-012-accept-player', 70, 92, SquadRole::Prospect);
        $before = $services->playerModule()->service()->repository($database)->get($player->id())->toArray();
        $movement = $services->transferModule()->service()->careerMovement();
        $date = SimulationDate::fromIsoString('2024-10-01');
        $offers = $movement->evaluate($database, $player->id(), $season->id(), $date);
        self::assertNotEmpty($offers);
        $offer = $offers[0];
        self::assertNotEmpty($offer->targetClubId());
        self::assertContains('playing_time', $offer->context()['reasons']);

        $resolved = $movement->accept($database, $offer->id(), $date);
        self::assertSame(CareerOpportunityStatus::Resolved, $resolved->status());
        $destination = $offer->targetClubId()->value();
        self::assertSame($destination, $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $season->id())[0]->clubId()->value());
        self::assertSame($destination, $services->contractModule()->service()->repository($database)->activeForPlayer($player->id())?->clubId()->value());
        self::assertSame($before, $services->playerModule()->service()->repository($database)->get($player->id())->toArray());
        self::assertSame(TransferStatus::Completed, $services->transferModule()->service()->repository($database)->get((string) $resolved->context()['completed_transfer_id'])->status());
        self::assertSame($player->id()->value(), $services->playerModule()->service()->careerRepository($database)->get($player->id()->value() . '-career')->playerId()->value());
        self::assertSame(CareerOpportunityStatus::Resolved, $movement->accept($database, $offer->id(), $date)->status());
        self::assertCount(1, $services->transferModule()->service()->repository($database)->byPlayer($player->id()));
        try {
            $movement->accept($database, $offers[1]->id(), $date);
            self::fail('A second offer must be stale after the Player has moved.');
        } catch (TransferException) {
            self::assertTrue(true);
        }

        unset($database);
        $database = $store->openDatabase('domain-012-accept');
        $reloaded = (new CareerOpportunityRepository($database))->get($offer->id());
        self::assertSame(CareerOpportunityStatus::Resolved, $reloaded->status());
        self::assertSame($destination, $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $season->id())[0]->clubId()->value());
    }

    public function testWeakPlayerWithoutFitDoesNotReceiveAnOffer(): void
    {
        [$services, $database, $season] = $this->scenario('domain-012-no-interest');
        $services->playerModule()->service()->populationService()->populate($database, $season, 12012);
        $player = $this->createCareerPlayer($services, $database, $season, 'domain-012-no-interest-player', 42, 46, SquadRole::Prospect);

        self::assertSame([], $services->transferModule()->service()->careerMovement()->evaluate($database, $player->id(), $season->id(), SimulationDate::fromIsoString('2024-10-01')));
    }

    public function testExpiredOfferCannotBeAccepted(): void
    {
        [$services, $database, $season] = $this->scenario('domain-012-expiry');
        $services->playerModule()->service()->populationService()->populate($database, $season, 12012);
        $player = $this->createCareerPlayer($services, $database, $season, 'domain-012-expiry-player', 70, 92, SquadRole::Prospect);
        $movement = $services->transferModule()->service()->careerMovement();
        $offers = $movement->evaluate($database, $player->id(), $season->id(), SimulationDate::fromIsoString('2024-10-01'));
        self::assertNotEmpty($offers);
        self::assertSame([], $movement->openOffers($database, $player->id(), SimulationDate::fromIsoString('2024-11-02')));
        self::assertSame(CareerOpportunityStatus::Expired, (new CareerOpportunityRepository($database))->get($offers[0]->id())->status());
        $this->expectException(TransferException::class);
        $movement->accept($database, $offers[0]->id(), SimulationDate::fromIsoString('2024-11-02'));
    }

    private function createCareerPlayer(object $services, object $database, Season $season, string $id, int $attribute, int $potential, SquadRole $role): object
    {
        $playerService = $services->playerModule()->service();
        $player = $playerService->create(new PlayerCreationRequest($id, 'Career', 'Player', $id, '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', $potential, 'regular', 12012, new PlayerAttributeSet($attribute, $attribute, $attribute, $attribute, $attribute, $attribute)));
        $playerService->initializeCareer($database, $player, new CareerPlayerReference(new CareerId($id . '-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), $role));
        $contracts = $services->contractModule()->service();
        $contracts->save($database, $contracts->create(new ContractCreationRequest(new ContractId($id . '-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2025-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));

        return $player;
    }

    /** @return array{0: object, 1: object, 2: Season, 3: SqliteSaveStore} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 12012, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $season, $store];
    }
}
