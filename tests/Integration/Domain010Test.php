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
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class Domain010Test extends TestCase
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

    public function testBigFivePopulationIsCompleteAndIdempotent(): void
    {
        [$services, $database, $season] = $this->scenario('domain-010-population');
        $population = $services->playerModule()->service()->populationService();
        $first = $population->populate($database, $season, 1010);

        self::assertSame(96, $first['clubs_populated']);
        self::assertSame(2400, $first['players_total']);
        self::assertSame(25, $first['min_squad_size']);
        self::assertSame(25, $first['max_squad_size']);
        self::assertSame(2400, count($services->contractModule()->service()->repository($database)->all()));
        self::assertSame(2400, count($services->competitionModule()->service()->registrationRepository($database)->all()));
        self::assertGreaterThan(0, $first['position_counts']['GK']);
        self::assertGreaterThan(0, $first['position_counts']['CB']);
        self::assertGreaterThan(0, $first['position_counts']['CM']);
        self::assertGreaterThan(0, $first['position_counts']['ST']);
        self::assertGreaterThan(0, $first['role_counts']['key_player']);
        self::assertGreaterThan(0, $first['role_counts']['prospect']);

        $playerRepository = new PlayerRepository($database);
        $sampleBefore = $playerRepository->get('npc-v1-arsenal-01')->toArray();
        $second = $population->populate($database, $season, 1010);

        self::assertSame(0, $second['players_generated']);
        self::assertSame($first['players_total'], $second['players_total']);
        self::assertSame($sampleBefore, $playerRepository->get('npc-v1-arsenal-01')->toArray());
        self::assertCount(2400, $playerRepository->all());
    }

    public function testCareerPlayerCoexistsWithGeneratedSquad(): void
    {
        [$services, $database, $season] = $this->scenario('domain-010-career-coexistence');
        $playerService = $services->playerModule()->service();
        $player = $playerService->create(new PlayerCreationRequest(
            'career-population-player', 'Career', 'Population', 'Career Population', '2005-01-01',
            'england', [], 'england', ['england'], 180, 75, 'CM', 99, 'regular', 7,
            new PlayerAttributeSet(90, 90, 90, 90, 90, 90),
        ));
        $playerService->repository($database)->save($player);
        $services->clubModule()->service()->squadRepository($database)->save(new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::KeyPlayer));
        $contract = $services->contractModule()->service()->create(new ContractCreationRequest(new ContractId('career-population-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2026-06-30'), 500, SimulationDate::fromIsoString('2024-08-01')));
        $services->contractModule()->service()->save($database, $contract);
        $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration(new SeasonId('season-2024-25'), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));

        $summary = $playerService->populationService()->populate($database, $season, 1010);
        $squad = $services->clubModule()->service()->squadRepository($database)->byClub('arsenal', $season->id());

        self::assertSame(25, count($squad));
        self::assertTrue($playerService->repository($database)->exists($player->id()));
        self::assertSame(25, $summary['min_squad_size']);
        self::assertSame('key_player', array_values(array_filter($squad, static fn ($member): bool => $member->playerId()->value() === 'career-population-player'))[0]->role()->value);
    }

    public function testPopulatedClubsUseRealPlayersAndFatigueCreatesRotation(): void
    {
        [$services, $database, $season] = $this->scenario('domain-010-match');
        $services->playerModule()->service()->populationService()->populate($database, $season, 1010);
        $matches = $services->matchModule()->service()->repository($database);
        $first = new GameMatch(new MatchId('domain-010-match-1'), new CompetitionId('premier-league'), $season->id(), 1, SimulationDate::fromIsoString('2024-08-01'), new ClubId('arsenal'), new ClubId('chelsea'));
        $second = new GameMatch(new MatchId('domain-010-match-2'), new CompetitionId('premier-league'), $season->id(), 2, SimulationDate::fromIsoString('2024-08-03'), new ClubId('chelsea'), new ClubId('arsenal'));
        $matches->save($first);
        $matches->save($second);
        $matchService = $services->matchModule()->service();
        $matchService->simulate($database, $first->id());
        $firstSelections = (new MatchSelectionRepository($database))->byMatch($first->id());
        $firstStarters = array_values(array_map(static fn ($selection): string => $selection->playerId()->value(), array_filter($firstSelections, static fn ($selection): bool => $selection->status()->value === 'starter' && $selection->clubId()->value() === 'arsenal')));
        $completed = $matchService->simulate($database, $second->id());
        $secondSelections = (new MatchSelectionRepository($database))->byMatch($second->id());
        $secondStarters = array_values(array_map(static fn ($selection): string => $selection->playerId()->value(), array_filter($secondSelections, static fn ($selection): bool => $selection->status()->value === 'starter' && $selection->clubId()->value() === 'arsenal')));
        $stats = (new PlayerMatchStatRepository($database))->byMatch($first->id());
        $players = new PlayerRepository($database);

        self::assertGreaterThan(22, count($stats));
        self::assertCount(11, array_filter($stats, static fn ($stat): bool => $stat->clubId()->value() === 'arsenal' && $stat->started()));
        self::assertNotEmpty(array_filter($stats, static fn ($stat): bool => $stat->clubId()->value() === 'arsenal' && !$stat->started()));
        self::assertSame(990, array_sum(array_map(static fn ($stat): int => $stat->clubId()->value() === 'arsenal' ? $stat->minutes() : 0, $stats)));
        self::assertCount(11, $firstStarters);
        self::assertCount(11, $secondStarters);
        self::assertNotSame($firstStarters, $secondStarters);
        self::assertNotEmpty(array_filter($stats, static fn ($stat): bool => str_starts_with($stat->playerId()->value(), 'npc-v1-')));
        foreach ($stats as $stat) {
            self::assertTrue($players->exists($stat->playerId()));
        }
        self::assertSame('completed', $completed->status()->value);
    }

    /** @return array{0: \Goal\Legacy\Core\Bootstrap\CoreServices,1: \Goal\Legacy\Core\Persistence\DatabaseInterface,2: Season} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 1010, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
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
