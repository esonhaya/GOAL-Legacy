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
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class Domain018Test extends TestCase
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

    public function testFullSquadPositionalNeedDoesNotCreateTwentySixthPlayer(): void
    {
        [$services, $database, $season] = $this->scenario('domain-018-cap');
        $clubId = new ClubId('arsenal');
        $squads = $services->clubModule()->service()->squadRepository($database);
        $registrations = $services->competitionModule()->service()->registrationRepository($database);
        $contracts = $services->contractModule()->service()->repository($database);
        $players = new PlayerRepository($database);

        $goalkeepers = array_values(array_filter(
            $squads->byClub($clubId, $season->id()),
            fn (ClubSquadMembership $membership): bool => $players->get($membership->playerId())->primaryPosition() === PlayerPosition::Goalkeeper,
        ));
        self::assertCount(3, $goalkeepers);
        foreach ($goalkeepers as $membership) {
            $playerId = $membership->playerId();
            $active = $contracts->activeForPlayer($playerId);
            self::assertNotNull($active);
            $contracts->save($active->terminate());
            foreach ($registrations->byPlayer($playerId) as $registration) {
                if ($registration->seasonId()->value() === $season->id()->value() && $registration->clubId()->value() === $clubId->value()) {
                    $registrations->unregister($registration);
                }
            }
            $squads->remove($membership);
        }

        $competitionMembership = $services->clubModule()->service()->membershipRepository($database)->byClub($clubId)[0];
        for ($ordinal = 1; $ordinal <= 3; ++$ordinal) {
            $player = $services->playerModule()->service()->create(new PlayerCreationRequest(
                'domain-018-midfielder-' . $ordinal,
                'Equilibrium',
                'Midfielder' . $ordinal,
                null,
                '2000-01-01',
                'england',
                [],
                'england',
                ['england'],
                180,
                75,
                'CM',
                80,
                'regular',
                18018 + $ordinal,
                new PlayerAttributeSet(65, 65, 65, 65, 65, 65),
            ));
            $players->save($player);
            $contracts->save($services->contractModule()->service()->create(new ContractCreationRequest(
                new ContractId('domain-018-contract-' . $ordinal),
                $player->id(),
                $clubId,
                $season->startDate(),
                $season->endDate()->addDays(365),
                100,
                $season->startDate(),
            )));
            $squads->save(new ClubSquadMembership($clubId, $player->id(), $season->id(), SquadRole::Rotation));
            $registrations->register(new PlayerRegistration($season->id(), $competitionMembership->competitionId(), $clubId, $player->id()));
        }

        self::assertCount(25, $squads->byClub($clubId, $season->id()));
        $services->clubRecruitmentService()->recruit($database, $season, $season->startDate());

        self::assertCount(25, $squads->byClub($clubId, $season->id()));
        self::assertCount(0, array_filter($squads->byClub($clubId, $season->id()), fn (ClubSquadMembership $membership): bool => in_array($membership->playerId()->value(), array_map(static fn (ClubSquadMembership $entry): string => $entry->playerId()->value(), $goalkeepers), true)));
        foreach ($goalkeepers as $membership) {
            self::assertNull($contracts->activeForPlayer($membership->playerId()));
        }
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 18018, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
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
