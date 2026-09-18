<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
use Goal\Legacy\Modules\Competition\PromotionRelegationService;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Transfer\Domain\Transfer;
use Goal\Legacy\Modules\Transfer\Domain\TransferExecutionTerms;
use Goal\Legacy\Modules\Transfer\Domain\TransferId;
use PHPUnit\Framework\TestCase;

final class Domain017Test extends TestCase
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

    public function testAllSupportedPairsExchangeMembershipFromFinalStandings(): void
    {
        [$services, $database, $store, $world, $season] = $this->scenario('domain-017-exchange');
        $services->playerModule()->service()->populationService()->populate($database, $season, 17017);
        $matchRepository = new MatchRepository($database);
        $memberships = $services->clubModule()->service()->membershipRepository($database);
        $expectedCounts = [];
        foreach ($services->competitionModule()->service()->loadSelected() as $definition) {
            $clubs = $memberships->byCompetition($definition->id(), $season->id());
            self::assertNotEmpty($clubs);
            $expectedCounts[$definition->id()->value()] = count($clubs);
            $matchRepository->save(new GameMatch(
                new MatchId('domain-017-final-' . $definition->id()->value()),
                new CompetitionId($definition->id()->value()),
                $season->id(),
                1,
                SimulationDate::fromIsoString('2024-08-02'),
                $clubs[0]->clubId(),
                $clubs[1]->clubId(),
                MatchStatus::Completed,
                new MatchResult(1, 0),
            ));
        }

        $worldService = $services->worldModule()->service();
        $worldService->advanceToDate($database, $world->id(), SimulationDate::fromIsoString('2025-06-01'));
        $completed = $worldService->seasonRepository($database)->get($season->id());
        $movement = (new PromotionRelegationService($services->clubModule()->service()))->determine($database, $completed, $services->competitionModule()->service()->loadSelected());
        self::assertCount(10, $movement['promoted']);
        self::assertCount(10, $movement['relegated']);
        self::assertSame(['england' => 2, 'spain' => 2, 'germany' => 2, 'italy' => 2, 'france' => 2], array_count_values(array_map(static fn (array $change): string => $change['nation_id'], $movement['promoted'])));
        $controlledClub = $movement['promoted'][0]['club_id'];
        $playerRepository = new PlayerRepository($database);
        $controlledPlayer = array_values(array_filter(
            $services->clubModule()->service()->squadRepository($database)->byClub($controlledClub, $season->id()),
            static fn ($membership): bool => !$playerRepository->get($membership->playerId())->isRetired(),
        ))[0]->playerId();
        (new CareerPlayerRepository($database))->save(new CareerPlayerReference(new CareerId('domain-017-controlled-career'), $controlledPlayer, SimulationDate::fromIsoString('2024-08-01')));
        $relegatedClub = $movement['relegated'][0]['club_id'];
        $relegatedPlayer = array_values(array_filter(
            $services->clubModule()->service()->squadRepository($database)->byClub($relegatedClub, $season->id()),
            static fn ($membership): bool => !$playerRepository->get($membership->playerId())->isRetired(),
        ))[0]->playerId();
        (new CareerPlayerRepository($database))->save(new CareerPlayerReference(new CareerId('domain-017-relegated-career'), $relegatedPlayer, SimulationDate::fromIsoString('2024-08-01')));

        $worldService->advanceToDate($database, $world->id(), SimulationDate::fromIsoString('2025-08-01'));
        $next = new SeasonId('season-2025-26');
        $nextMemberships = $memberships->bySeason($next);
        $nextLeagueMemberships = array_values(array_filter(
            $nextMemberships,
            fn ($membership): bool => $services->competitionModule()->service()->repository($database)->get($membership->competitionId())->type() === CompetitionType::DomesticLeague,
        ));
        self::assertCount(198, $nextLeagueMemberships);

        $nextByClub = [];
        foreach ($nextLeagueMemberships as $membership) {
            self::assertArrayNotHasKey($membership->clubId()->value(), $nextByClub);
            $nextByClub[$membership->clubId()->value()] = $membership->competitionId()->value();
        }
        foreach (array_merge($movement['promoted'], $movement['relegated']) as $change) {
            self::assertSame($change['to_competition_id'], $nextByClub[$change['club_id']]);
        }
        $previousByClub = [];
        foreach ($memberships->bySeason($season->id()) as $membership) {
            if ($services->competitionModule()->service()->repository($database)->get($membership->competitionId())->type() !== CompetitionType::DomesticLeague) {
                continue;
            }
            $previousByClub[$membership->clubId()->value()] = $membership->competitionId()->value();
        }
        foreach (array_merge($movement['promoted'], $movement['relegated']) as $change) {
            self::assertSame($change['from_competition_id'], $previousByClub[$change['club_id']]);
        }
        self::assertSame($movement['promoted'][0]['to_competition_id'], $nextByClub[$controlledClub]);
        self::assertSame(198, $worldService->seasonRollover()?->lastRecruitment()['clubs_processed']);

        $squads = $services->clubModule()->service()->squadRepository($database);
        self::assertSame($controlledClub, $squads->byPlayer($controlledPlayer, $next)[0]->clubId()->value());
        $registrations = $services->competitionModule()->service()->registrationRepository($database)->byPlayer($controlledPlayer);
        self::assertContains($movement['promoted'][0]['to_competition_id'], array_map(static fn ($registration): string => $registration->competitionId()->value(), $registrations));
        self::assertSame($relegatedClub, $squads->byPlayer($relegatedPlayer, $next)[0]->clubId()->value());
        $relegatedRegistrations = $services->competitionModule()->service()->registrationRepository($database)->byPlayer($relegatedPlayer);
        self::assertContains($movement['relegated'][0]['to_competition_id'], array_map(static fn ($registration): string => $registration->competitionId()->value(), $relegatedRegistrations));

        $sourceClub = $relegatedClub;
        $destinationClub = $movement['promoted'][0]['club_id'];
        $transferPlayer = array_values(array_filter(
            $squads->byClub($sourceClub, $next),
            static fn ($membership): bool => !$playerRepository->get($membership->playerId())->isRetired() && $membership->playerId()->value() !== $controlledPlayer->value(),
        ))[0]->playerId();
        $services->transferModule()->service()->execute(
            $database,
            new Transfer(new TransferId('domain-017-post-tier-transfer'), $transferPlayer, new ClubId($sourceClub), new ClubId($destinationClub), $next, 100, SimulationDate::fromIsoString('2025-08-01')),
            new TransferExecutionTerms(new ContractId('domain-017-post-tier-contract'), SimulationDate::fromIsoString('2026-06-30'), 1000, SquadRole::Rotation),
        );
        self::assertSame($destinationClub, $squads->byPlayer($transferPlayer, $next)[0]->clubId()->value());

        foreach ($services->competitionModule()->service()->loadSelected() as $definition) {
            $count = count($memberships->byCompetition($definition->id(), $next));
            self::assertSame($expectedCounts[$definition->id()->value()], $count);
            if ($definition->type() === CompetitionType::DomesticCup) {
                self::assertGreaterThan(0, count($services->matchModule()->service()->repository($database)->byCompetition($definition->id(), $next)));
                self::assertSame([], $services->matchModule()->service()->standings($database, $definition->id(), $next));
                continue;
            }
            self::assertSame($count * ($count - 1), count($services->matchModule()->service()->repository($database)->byCompetition($definition->id(), $next)));
            self::assertCount($count, $services->matchModule()->service()->standings($database, $definition->id(), $next));
        }

        $snapshot = array_map(static fn ($membership): array => $membership->toArray(), $nextMemberships);
        unset($database);
        $database = $store->openDatabase($world->id()->value());
        self::assertSame('season-2025-26', $worldService->load($database, $world->id())->currentSeasonId()?->value());
        $worldService->advanceToDate($database, $world->id(), SimulationDate::fromIsoString('2025-08-01'));
        self::assertSame($snapshot, array_map(static fn ($membership): array => $membership->toArray(), $memberships->bySeason($next)));
        self::assertCount(10, $worldService->seasonRollover()?->lastMovement()['promoted'] ?? []);
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:SqliteSaveStore,3:World,4:Season} */
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

        return [$services, $database, $store, $world, $season];
    }
}
