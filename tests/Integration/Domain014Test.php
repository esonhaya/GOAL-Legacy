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
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCareerState;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Persistence\PlayerDevelopmentRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class Domain014Test extends TestCase
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

    public function testSeasonLifecycleDeclineAndRetirementAreIdempotent(): void
    {
        [$services, $database, $world] = $this->scenario('domain-014-lifecycle');
        $playerService = $services->playerModule()->service();
        $young = $playerService->create($this->request('young', '1993-01-01', 90));
        $old = $playerService->create($this->request('old', '1984-01-01', 90));
        $playerService->repository($database)->save($young);
        $playerService->repository($database)->save($old);
        $contracts = $services->contractModule()->service();
        $contracts->save($database, $contracts->create(new ContractCreationRequest(new ContractId('old-contract'), $old->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2030-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));

        $services->worldModule()->service()->advanceToDate($database, $world->id(), SimulationDate::fromIsoString('2025-06-01'));
        $repository = new PlayerRepository($database);
        $declined = $repository->get($young->id());
        $retired = $repository->get($old->id());

        self::assertSame(PlayerCareerState::Active, $declined->careerState());
        self::assertLessThan($young->overallRating(), $declined->overallRating());
        self::assertSame(PlayerCareerState::Retired, $retired->careerState());
        self::assertSame('terminated', $contracts->repository($database)->byPlayer($old->id())[0]->status()->value);
        self::assertCount(1, array_filter((new PlayerDevelopmentRepository($database))->byPlayer($young->id()), static fn ($entry): bool => $entry->source() === 'season_lifecycle'));

        $services->worldModule()->service()->advanceToDate($database, $world->id(), SimulationDate::fromIsoString('2025-06-02'));
        self::assertCount(1, array_filter((new PlayerDevelopmentRepository($database))->byPlayer($young->id()), static fn ($entry): bool => $entry->source() === 'season_lifecycle'));
    }

    public function testRetirementCreatesOrdinaryDeterministicVacancyRepair(): void
    {
        [$services, $database, $world] = $this->scenario('domain-014-newgen');
        $season = $services->worldModule()->service()->seasonRepository($database)->get('season-2024-25');
        $services->playerModule()->service()->populationService()->populate($database, $season, 14014);
        $squads = $services->clubModule()->service()->squadRepository($database);
        $contracts = $services->contractModule()->service()->repository($database);
        $retireId = $squads->byClub('arsenal', $season->id())[0]->playerId();
        $playerRepository = new PlayerRepository($database);
        $playerRepository->save($playerRepository->get($retireId)->withCareerState(PlayerCareerState::Retired));
        $active = $contracts->activeForPlayer($retireId);
        self::assertNotNull($active);
        $contracts->save($active->terminate());

        $services->worldModule()->service()->advanceToDate($database, $world->id(), SimulationDate::fromIsoString('2025-06-01'));
        $services->worldModule()->service()->advanceToDate($database, $world->id(), SimulationDate::fromIsoString('2025-08-01'));
        $next = $services->worldModule()->service()->seasonRepository($database)->get('season-2025-26');
        $nextSquad = $squads->byClub('arsenal', $next->id());

        self::assertCount(25, $nextSquad);
        self::assertCount(0, array_filter($nextSquad, static fn ($membership): bool => $membership->playerId()->value() === $retireId->value()));
        self::assertSame(PlayerCareerState::Retired, $playerRepository->get($retireId)->careerState());
        $replacementIds = array_values(array_filter(array_map(static fn ($membership): string => $membership->playerId()->value(), $nextSquad), static fn (string $playerId): bool => $playerId !== $retireId->value()));
        self::assertNotEmpty($replacementIds);
        $activeReplacementIds = array_values(array_filter($replacementIds, fn (string $playerId): bool => $contracts->activeForPlayer($playerId) !== null));
        self::assertNotEmpty($activeReplacementIds);
    }

    private function request(string $id, string $birthDate, int $potential): PlayerCreationRequest
    {
        return new PlayerCreationRequest($id, 'Lifecycle', ucfirst($id), 'Lifecycle ' . ucfirst($id), $birthDate, 'england', [], 'england', ['england'], 180, 75, 'CM', $potential, 'regular', 14014, new PlayerAttributeSet(70, 70, 70, 70, 70, 70));
    }

    /** @return array{0: \Goal\Legacy\Core\Bootstrap\CoreServices,1: \Goal\Legacy\Core\Persistence\DatabaseInterface,2: World} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 14014, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $world];
    }
}
