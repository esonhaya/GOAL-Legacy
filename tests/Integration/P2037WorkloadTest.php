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
use Goal\Legacy\Modules\Competition\Persistence\PlayerRegistrationRepository;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\PlayerAvailabilityService;
use Goal\Legacy\Modules\Transfer\Domain\LoanStatus;
use Goal\Legacy\Modules\Transfer\Persistence\LoanRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class P2037WorkloadTest extends TestCase
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

    public function testRecentMinutesAndCongestionAreCanonicalAndReadOnly(): void
    {
        [$services, $database, $season, $player] = $this->scenario('p2037-window');
        $this->recordMatch($database, $season, $player->id()->value(), 'p2037-window-one', '2024-08-01', 10, false);
        $this->recordMatch($database, $season, $player->id()->value(), 'p2037-window-two', '2024-08-03', 90, true);
        $this->recordMatch($database, $season, $player->id()->value(), 'p2037-window-three', '2024-08-10', 90, true);
        $stats = new PlayerMatchStatRepository($database, false);
        $before = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();

        $workload = $stats->recentWorkload($player->id(), SimulationDate::fromIsoString('2024-08-10'));
        $assessment = (new PlayerAvailabilityService())->assess($database, $player->id(), SimulationDate::fromIsoString('2024-08-10'));
        $after = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();

        self::assertSame(190, $workload['recent_minutes']);
        self::assertSame(3, $workload['recent_appearances']);
        self::assertSame(2, $workload['recent_starts']);
        self::assertSame(180, $workload['minutes_last_7_days']);
        self::assertSame(1, $workload['short_recovery_matches']);
        self::assertTrue($workload['congested']);
        self::assertSame('2024-08-10', $workload['last_match_date']);
        self::assertSame(0, $assessment->fatigue(), 'Reading canonical minutes does not invent persisted fatigue.');
        foreach ($workload as $key => $value) {
            self::assertSame($value, $assessment->workload()[$key]);
        }
        self::assertSame($before, $after, 'Workload and readiness reads do not mutate the save.');
        unset($services);
    }

    public function testProductionWorldFidelityGateDoesNotCreateNpcWorkloadState(): void
    {
        [$services, $database, $season, $player] = $this->scenario('p2037-world-boundary');
        $match = new GameMatch(new MatchId('p2037-world-match'), new CompetitionId('premier-league'), $season->id(), 1, SimulationDate::fromIsoString('2024-08-03'), new ClubId('arsenal'), new ClubId('chelsea'));
        $stat = new PlayerMatchStat($match->id(), $player->id(), new ClubId('arsenal'), true, true, 90, 0);
        $availability = new PlayerAvailabilityService();
        $database->transaction(fn (): array => $availability->applyMatchInTransaction($database, $match, [$stat], false, []));

        self::assertSame(0, (int) $database->connection()->query('SELECT COUNT(*) FROM player_availability_state')->fetchColumn());
        self::assertSame(0, (int) $database->connection()->query('SELECT COUNT(*) FROM player_availability_sources')->fetchColumn());
        unset($services);
    }

    public function testLoanMatchLoadFollowsPlayerThroughReturnWithoutReset(): void
    {
        [$services, $database, $season, $player] = $this->loanScenario('p2037-loan-continuity');
        $movement = $services->transferModule()->service()->careerMovement();
        $start = SimulationDate::fromIsoString('2024-08-01');
        $opportunity = $movement->prepareControlledLoanDecision($database, $player->id(), $season, $start);
        self::assertNotNull($opportunity);
        $option = array_values(array_filter($opportunity->context()['options'], static fn (array $row): bool => ($row['kind'] ?? null) === 'accept_loan'))[0] ?? null;
        self::assertIsArray($option);
        $movement->resolveLoanDecision($database, $opportunity->id(), (string) $option['id'], $start);

        $match = new GameMatch(new MatchId('p2037-loan-match'), new CompetitionId('premier-league'), $season->id(), 1, SimulationDate::fromIsoString('2024-09-01'), new ClubId((string) $option['club_id']), new ClubId('arsenal'));
        $stat = new PlayerMatchStat($match->id(), $player->id(), new ClubId((string) $option['club_id']), true, true, 90, 0);
        $availability = new PlayerAvailabilityService();
        $database->transaction(fn (): array => $availability->applyMatchInTransaction($database, $match, [$stat], true, [$player->id()]));
        $beforeReturn = $availability->assess($database, $player->id(), SimulationDate::fromIsoString('2024-09-02'))->fatigue();
        self::assertGreaterThan(0, $beforeReturn);
        $storedBeforeReturn = (new \Goal\Legacy\Modules\Player\Persistence\PlayerAvailabilityRepository($database))->state($player->id());

        self::assertSame(1, $services->transferModule()->service()->returnDueLoans($database, $season->endDate()));
        $storedAfterReturn = (new \Goal\Legacy\Modules\Player\Persistence\PlayerAvailabilityRepository($database))->state($player->id());
        self::assertSame($storedBeforeReturn['fatigue'], $storedAfterReturn['fatigue'], 'Loan return does not reset workload state.');
        $loan = (new LoanRepository($database))->get((string) $option['loan_id']);
        self::assertNotNull($loan);
        self::assertSame(LoanStatus::Completed, $loan->status());
        self::assertSame('arsenal', $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $season->id())[0]->clubId()->value());
        unset($services);
    }

    /** @return array{0:object,1:object,2:Season,3:object} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2037001, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $root = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($root, 0775, true);
        $this->roots[] = $root;
        $store = new SqliteSaveStore($root, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);
        $player = $services->playerModule()->service()->create(new PlayerCreationRequest($id . '-player', 'Workload', 'Player', 'Workload Player', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 92, 'prodigy', 2037001, new PlayerAttributeSet(72, 72, 72, 72, 72, 72)));
        $services->playerModule()->service()->repository($database)->save($player);

        return [$services, $database, $season, $player];
    }

    /** @return array{0:object,1:object,2:Season,3:object} */
    private function loanScenario(string $id): array
    {
        [$services, $database, $season, $player] = $this->scenario($id);
        $services->playerModule()->service()->populationService()->populate($database, $season, 2037002);
        $existing = $services->clubModule()->service()->squadRepository($database)->byClub(new ClubId('cardiff-city'), $season->id())[0] ?? null;
        if ($existing !== null) {
            $services->clubModule()->service()->squadRepository($database)->remove($existing);
        }
        $services->playerModule()->service()->initializeCareer(
            $database,
            $player,
            new CareerPlayerReference(new CareerId($id . '-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')),
            new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Prospect),
        );
        $contracts = $services->contractModule()->service();
        $contracts->save($database, $contracts->create(new ContractCreationRequest(new ContractId($id . '-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2026-05-31'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        (new PlayerRegistrationRepository($database))->register(new \Goal\Legacy\Modules\Competition\Domain\PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));

        return [$services, $database, $season, $player];
    }

    private function recordMatch($database, Season $season, string $playerId, string $matchId, string $date, int $minutes, bool $started): void
    {
        $match = new GameMatch(new MatchId($matchId), new CompetitionId('premier-league'), $season->id(), 1, SimulationDate::fromIsoString($date), new ClubId('arsenal'), new ClubId('chelsea'));
        (new MatchRepository($database))->save($match->complete(new MatchResult(1, 0)));
        (new PlayerMatchStatRepository($database))->replaceForMatch([new PlayerMatchStat($match->id(), new \Goal\Legacy\Modules\Player\Domain\PlayerId($playerId), new ClubId('arsenal'), true, $started, $minutes, 0)]);
    }
}
