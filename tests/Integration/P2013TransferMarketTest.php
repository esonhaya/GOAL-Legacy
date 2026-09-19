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
use Goal\Legacy\Modules\Transfer\Domain\TransferStatus;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PHPUnit\Framework\TestCase;

final class P2013TransferMarketTest extends TestCase
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

    public function testMarketStatureKeepsUnprovenPotentialBoundedAndUsesAge(): void
    {
        [$services, $database, $season] = $this->scenario('p2013-stature');
        $player = $services->playerModule()->service()->create(new PlayerCreationRequest('p2013-prospect', 'Young', 'Prospect', 'Young Prospect', '2005-01-01', 'england', [], 'england', ['england'], 180, 70, 'CM', 99, 'prodigy', 2013, new PlayerAttributeSet(50, 50, 50, 50, 50, 50)));
        $services->playerModule()->service()->repository($database)->save($player);
        $market = $services->transferModule()->service()->careerMovement()->marketContext($database, $player->id(), $season->id(), $season->startDate());

        self::assertSame('Developing Prospect', $market['label']);
        self::assertSame(19, $market['age']);
        self::assertLessThan(76, $market['score']);
        self::assertSame(0, $market['international_caps']);
    }

    public function testEstablishedPlayerProducesDeterministicBoundedOffersWithRoleAndClubContext(): void
    {
        [$services, $database, $season] = $this->scenario('p2013-offers');
        $player = $services->playerModule()->service()->create(new PlayerCreationRequest('p2013-star', 'Established', 'Player', 'Established Player', '1999-01-01', 'england', [], 'england', ['england'], 184, 78, 'CM', 95, 'regular', 2014, new PlayerAttributeSet(90, 90, 90, 90, 90, 90)));
        $services->playerModule()->service()->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('p2013-star-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Regular));
        $contracts = $services->contractModule()->service();
        $contracts->save($database, $contracts->create(new ContractCreationRequest(new ContractId('p2013-star-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2026-05-31'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));
        $movement = $services->transferModule()->service()->careerMovement();
        $offers = $movement->evaluate($database, $player->id(), $season->id(), $season->startDate(), 'premier-league');

        self::assertLessThanOrEqual(3, count($offers));
        self::assertNotEmpty($offers);
        $context = $offers[0]->context();
        self::assertContains($context['target_club_level'], ['Lower Level', 'Mid Level', 'Upper Level', 'Elite']);
        self::assertContains($context['proposed_role'], ['prospect', 'rotation', 'regular', 'key_player']);
        self::assertGreaterThanOrEqual(100, $context['wage']);
        self::assertLessThanOrEqual(5000, $context['wage']);
        self::assertNotSame('', (string) ($context['market_stature'] ?? ''));
        self::assertSame([], array_values(array_filter((new \Goal\Legacy\Modules\Transfer\Persistence\TransferRepository($database))->byPlayer($player->id()), static fn ($transfer): bool => $transfer->status() === TransferStatus::Completed)));
    }

    /** @return array{0:object,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2013, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);
        $services->playerModule()->service()->populationService()->populate($database, $season, 2013);

        return [$services, $database, $season];
    }
}
