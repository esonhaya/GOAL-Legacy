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
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\CompetitionStatisticsQuery;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\Injury;
use Goal\Legacy\Modules\Player\Domain\InjuryCategory;
use Goal\Legacy\Modules\Player\Domain\InjurySeverity;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Finance\PlayerFinanceService;
use Goal\Legacy\Modules\Player\Persistence\PlayerAvailabilityRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerDisciplineRepository;
use Goal\Legacy\Modules\Player\PlayerAvailabilityService;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\Player\PlayerDisciplineService;
use Goal\Legacy\Modules\Transfer\Domain\Loan;
use Goal\Legacy\Modules\Transfer\Domain\LoanStatus;
use Goal\Legacy\Modules\Transfer\Domain\Transfer;
use Goal\Legacy\Modules\Transfer\Domain\TransferException;
use Goal\Legacy\Modules\Transfer\Domain\TransferExecutionTerms;
use Goal\Legacy\Modules\Transfer\Domain\TransferId;
use Goal\Legacy\Modules\Transfer\Persistence\LoanRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use Goal\Legacy\Modules\World\Persistence\SeasonRepository;
use Goal\Legacy\Modules\World\Persistence\WorldRepository;
use PHPUnit\Framework\TestCase;

final class P2038CareerLifecycleIntegrationTest extends TestCase
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

    public function testHealthyLoanCarriesContractStatsWorkloadReloadAndReturn(): void
    {
        [$services, $database, $store, $season, $player] = $this->scenario('p2038-healthy');
        [$movement, $option] = $this->acceptLoan($services, $database, $season, $player);
        $contracts = $services->contractModule()->service()->repository($database);
        $parentContract = $contracts->activeForPlayer($player->id());
        self::assertNotNull($parentContract);
        self::assertSame('arsenal', $parentContract->clubId()->value());
        $contractBefore = $parentContract->toArray();

        $loan = (new LoanRepository($database))->get((string) $option['loan_id']);
        self::assertNotNull($loan);
        self::assertSame(LoanStatus::Active, $loan->status());
        self::assertSame($option['club_id'], $loan->loanClubId()->value());
        self::assertSame(1, count($services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $season->id())));

        $loanCompetitionId = $this->competitionForClub($database, (string) $option['club_id'], $season);
        $loanOpponentId = $this->opponentForClub($database, $loanCompetitionId, (string) $option['club_id'], $season);
        $this->recordMatch($database, $season, $loanCompetitionId, $player->id(), 'p2038-loan-match-1', (string) $option['club_id'], $loanOpponentId, '2024-09-01', 90, 1, 1);
        $this->recordMatch($database, $season, $loanCompetitionId, $player->id(), 'p2038-loan-match-2', (string) $option['club_id'], $loanOpponentId, '2024-09-03', 30, 0, 0);
        $availability = new PlayerAvailabilityService();
        foreach (['p2038-loan-match-1' => '2024-09-01', 'p2038-loan-match-2' => '2024-09-03'] as $matchId => $date) {
            $match = (new MatchRepository($database))->get($matchId);
            $stats = (new PlayerMatchStatRepository($database))->byMatch($matchId);
            $database->transaction(fn (): array => $availability->applyMatchInTransaction($database, $match, $stats, true, [$player->id()]));
        }

        $assessment = $availability->assess($database, $player->id(), SimulationDate::fromIsoString('2024-09-03'));
        self::assertSame(120, $assessment->workload()['recent_minutes']);
        self::assertSame(1, $assessment->workload()['short_recovery_matches']);
        self::assertTrue($assessment->workload()['congested']);
        self::assertGreaterThan(0, $assessment->fatigue());

        $competitionStats = (new CompetitionStatisticsQuery())->forCompetitionSeason($database, $loanCompetitionId, $season->id());
        $playerStats = array_values(array_filter($competitionStats, static fn (array $row): bool => ($row['player_id'] ?? null) === $player->id()->value()));
        self::assertCount(1, $playerStats);
        self::assertSame((string) $option['club_id'], $playerStats[0]['club_id']);
        self::assertSame(2, $playerStats[0]['appearances']);
        self::assertSame(120, $playerStats[0]['minutes']);
        self::assertSame(1, $playerStats[0]['goals']);
        self::assertSame(1, $playerStats[0]['assists']);

        $query = new PlayerCareerProgressionQuery($services->clubModule()->service());
        $summaryDuringLoan = $query->summary($database, $player->id(), SimulationDate::fromIsoString('2024-09-03'), $season->id());
        self::assertSame((string) $option['club_id'], $summaryDuringLoan['current_club']['id']);
        self::assertSame('arsenal', $summaryDuringLoan['current_contract']['club']['id']);
        self::assertSame((string) $option['club_id'], $summaryDuringLoan['active_loan']['loan_club']['id']);

        $beforeRead = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();
        $readAgain = $query->summary($database, $player->id(), SimulationDate::fromIsoString('2024-09-03'), $season->id());
        $afterRead = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();
        self::assertSame($summaryDuringLoan['current_club'], $readAgain['current_club']);
        self::assertSame($summaryDuringLoan['readiness'], $readAgain['readiness']);
        self::assertSame($beforeRead, $afterRead);

        unset($database);
        $database = $store->openDatabase('p2038-healthy');
        $contracts = $services->contractModule()->service()->repository($database);
        $reloaded = $query->summary($database, $player->id(), SimulationDate::fromIsoString('2024-09-03'), $season->id());
        self::assertSame($summaryDuringLoan['current_club'], $reloaded['current_club']);
        self::assertSame($summaryDuringLoan['current_contract'], $reloaded['current_contract']);
        self::assertSame($summaryDuringLoan['readiness'], $reloaded['readiness']);

        self::assertSame(1, $this->socialSourceCount($database, $player->id()->value(), 'loan-start|' . $option['loan_id']));
        $rawStateBeforeReturn = (new PlayerAvailabilityRepository($database))->state($player->id());

        self::assertSame(1, $services->transferModule()->service()->returnDueLoans($database, $season->endDate()));
        self::assertSame(0, $services->transferModule()->service()->returnDueLoans($database, $season->endDate()));
        $rawStateAfterReturn = (new PlayerAvailabilityRepository($database))->state($player->id());
        self::assertSame($rawStateBeforeReturn['fatigue'], $rawStateAfterReturn['fatigue']);
        self::assertSame($rawStateBeforeReturn['revision'], $rawStateAfterReturn['revision']);

        $completedLoan = (new LoanRepository($database))->get((string) $option['loan_id']);
        self::assertNotNull($completedLoan);
        self::assertSame(LoanStatus::Completed, $completedLoan->status());
        $memberships = $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $season->id());
        self::assertCount(1, $memberships);
        self::assertSame('arsenal', $memberships[0]->clubId()->value());
        self::assertSame($contractBefore, $contracts->activeForPlayer($player->id())?->toArray());

        $summaryAfterReturn = $query->summary($database, $player->id(), $season->endDate(), $season->id());
        self::assertSame('arsenal', $summaryAfterReturn['current_club']['id']);
        self::assertNull($summaryAfterReturn['active_loan']);
        self::assertContains((string) $option['club_id'], $summaryAfterReturn['career_context']['former_club_ids']);
        self::assertSame(1, $this->socialSourceCount($database, $player->id()->value(), 'loan-start|' . $option['loan_id']));
        self::assertSame(1, $this->socialSourceCount($database, $player->id()->value(), 'loan-return|' . $option['loan_id']));

        $statsAfterReturn = (new CompetitionStatisticsQuery())->forCompetitionSeason($database, $loanCompetitionId, $season->id());
        $playerStatsAfterReturn = array_values(array_filter($statsAfterReturn, static fn (array $row): bool => ($row['player_id'] ?? null) === $player->id()->value()));
        self::assertCount(1, $playerStatsAfterReturn);
        self::assertSame((string) $option['club_id'], $playerStatsAfterReturn[0]['club_id']);
        unset($services);
    }

    public function testInjuryDisciplineAndFinanceRemainPlayerScopedDuringLoan(): void
    {
        [$services, $database, , $season, $player] = $this->scenario('p2038-medical-finance');
        [, $option] = $this->acceptLoan($services, $database, $season, $player);
        $availabilityRepository = new PlayerAvailabilityRepository($database);
        $injury = new Injury('p2038-loan-injury', $player->id(), 'match', 'p2038-loan-injury-match', InjuryCategory::Muscular, InjurySeverity::Moderate, SimulationDate::fromIsoString('2025-05-01'), SimulationDate::fromIsoString('2025-06-10'));
        $database->transaction(fn (): mixed => $availabilityRepository->saveInjuryInTransaction($injury));

        $discipline = new PlayerDisciplineRepository($database);
        $discipline->save([
            'player_id' => $player->id()->value(),
            'scope' => 'domestic_league',
            'accumulation_cycle' => '2024/25',
            'yellow_count' => 0,
            'suspension_matches_remaining' => 1,
            'suspension_reason' => 'red_card',
            'source_match_id' => 'p2038-disciplinary-source',
            'source_competition_id' => 'premier-league',
            'updated_date' => '2025-05-01',
        ]);
        $loanCompetitionId = $this->competitionForClub($database, (string) $option['club_id'], $season);
        $loanMatch = new GameMatch(new MatchId('p2038-discipline-loan'), new CompetitionId($loanCompetitionId), $season->id(), 1, SimulationDate::fromIsoString('2025-05-02'), new ClubId((string) $option['club_id']), new ClubId($this->opponentForClub($database, $loanCompetitionId, (string) $option['club_id'], $season)));
        self::assertFalse((new PlayerDisciplineService())->eligibility($database, $loanMatch, $player->id())['eligible']);
        self::assertNotNull($availabilityRepository->activeInjuryAt($player->id(), SimulationDate::fromIsoString('2025-05-31')));

        $finance = new PlayerFinanceService();
        $finance->initializeInTransaction($database, $player->id(), SimulationDate::fromIsoString('2024-07-31'));
        self::assertSame(1, $finance->processPayroll($database, $player->id(), SimulationDate::fromIsoString('2024-08-07')));
        self::assertSame(0, $finance->processPayroll($database, $player->id(), SimulationDate::fromIsoString('2024-08-07')));
        self::assertSame(1, $finance->processPayroll($database, $player->id(), SimulationDate::fromIsoString('2024-08-14')));
        self::assertSame(2, (int) $database->connection()->query("SELECT COUNT(*) FROM player_finance_transactions WHERE player_id = '" . $player->id()->value() . "' AND type = 'wage'")->fetchColumn());

        $services->transferModule()->service()->returnDueLoans($database, $season->endDate());
        self::assertNotNull($availabilityRepository->activeInjuryAt($player->id(), SimulationDate::fromIsoString('2025-05-31')));
        $parentMatch = new GameMatch(new MatchId('p2038-discipline-parent'), new CompetitionId('premier-league'), $season->id(), 1, SimulationDate::fromIsoString('2025-05-31'), new ClubId('arsenal'), new ClubId('chelsea'));
        self::assertFalse((new PlayerDisciplineService())->eligibility($database, $parentMatch, $player->id())['eligible']);
        self::assertSame('arsenal', $services->contractModule()->service()->repository($database)->activeForPlayer($player->id())?->clubId()->value());
        unset($services);
    }

    public function testLoanBoundariesRejectExpiryAndPermanentTransferUntilReturn(): void
    {
        [$services, $database, , $season, $player] = $this->scenario('p2038-boundaries');
        $sourceContract = $services->contractModule()->service()->repository($database)->activeForPlayer($player->id());
        self::assertNotNull($sourceContract);
        $invalidLoan = new Loan('p2038-expiring-loan', $player->id(), new ClubId('arsenal'), new ClubId('cardiff-city'), $season->id(), SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2026-06-01'), SquadRole::Prospect, SquadRole::Rotation);
        $this->expectException(TransferException::class);
        $services->transferModule()->service()->startLoan($database, $invalidLoan);
    }

    public function testPermanentTransferIsDeferredDuringLoanAndWorksAfterReturn(): void
    {
        [$services, $database, , $season, $player] = $this->scenario('p2038-post-loan-transfer');
        [$movement, $option] = $this->acceptLoan($services, $database, $season, $player);
        self::assertNull($movement->prepareControlledTransferDecision($database, $player->id(), $season, SimulationDate::fromIsoString('2024-09-01')));
        $transferService = $services->transferModule()->service();
        $duringLoan = new Transfer(new TransferId('p2038-during-loan-transfer'), $player->id(), new ClubId('arsenal'), new ClubId('cardiff-city'), $season->id(), 0, SimulationDate::fromIsoString('2024-09-01'));
        try {
            $transferService->execute($database, $duringLoan, new TransferExecutionTerms(new ContractId('p2038-during-loan-contract'), SimulationDate::fromIsoString('2026-06-01'), 120, SquadRole::Regular));
            self::fail('Permanent transfer must be deferred while a loan is active.');
        } catch (TransferException) {
            self::assertTrue(true);
        }
        self::assertSame(1, $transferService->returnDueLoans($database, $season->endDate()));
        $afterReturn = new Transfer(new TransferId('p2038-after-return-transfer'), $player->id(), new ClubId('arsenal'), new ClubId('cardiff-city'), $season->id(), 0, SimulationDate::fromIsoString('2025-06-01'));
        $completed = $transferService->execute($database, $afterReturn, new TransferExecutionTerms(new ContractId('p2038-after-return-contract'), SimulationDate::fromIsoString('2026-06-01'), 120, SquadRole::Regular));
        self::assertSame('completed', $completed->status()->value);
        self::assertSame('cardiff-city', $services->contractModule()->service()->repository($database)->activeForPlayer($player->id())?->clubId()->value());
        self::assertCount(1, $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $season->id()));
        self::assertSame('cardiff-city', $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $season->id())[0]->clubId()->value());
    }

    public function testSeasonPreparationReturnsLoanBeforeContinuityAndReplayIsSafe(): void
    {
        [$services, $database, , $season, $player] = $this->scenario('p2038-rollover');
        [, $option] = $this->acceptLoan($services, $database, $season, $player);
        $seasonRepository = new SeasonRepository($database);
        $storedSeason = $seasonRepository->get($season->id());
        $activeSeason = $storedSeason->status()->value === 'upcoming' ? $storedSeason->activate() : $storedSeason;
        $seasonRepository->save($activeSeason);
        self::assertSame('active', $activeSeason->status()->value);
        $completedSeason = $activeSeason->complete();
        $seasonRepository->save($completedSeason);
        $world = (new WorldRepository($database))->get('p2038-rollover');
        $rollover = $services->worldModule()->service()->seasonRollover();
        self::assertNotNull($rollover);
        $rollover->prepareNext($database, $world, $completedSeason, SimulationDate::fromIsoString('2025-06-01'));
        $next = $rollover->nextSeason($completedSeason);
        $loan = (new LoanRepository($database))->get((string) $option['loan_id']);
        self::assertNotNull($loan);
        self::assertSame(LoanStatus::Completed, $loan->status());
        self::assertCount(1, $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $next->id()));
        self::assertSame('arsenal', $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $next->id())[0]->clubId()->value());

        $rollover->prepareNext($database, $world, $completedSeason, SimulationDate::fromIsoString('2025-06-01'));
        self::assertSame(1, count((new LoanRepository($database))->byPlayer($player->id())));
        self::assertCount(1, $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $next->id()));
        unset($services);
    }

    /** @return array{0:object,1:object,2:SqliteSaveStore,3:Season,4:object} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2038001, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $root = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($root, 0775, true);
        $this->roots[] = $root;
        $store = new SqliteSaveStore($root, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);
        $services->playerModule()->service()->populationService()->populate($database, $season, 2038002);
        $existing = $services->clubModule()->service()->squadRepository($database)->byClub(new ClubId('cardiff-city'), $season->id())[0] ?? null;
        if ($existing !== null) {
            $services->clubModule()->service()->squadRepository($database)->remove($existing);
        }
        $player = $services->playerModule()->service()->create(new PlayerCreationRequest($id . '-player', 'Lifecycle', 'Player', 'Lifecycle Player', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 92, 'prodigy', 2038001, new PlayerAttributeSet(72, 72, 72, 72, 72, 72)));
        $services->playerModule()->service()->initializeCareer($database, $player, new CareerPlayerReference(new CareerId($id . '-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Prospect));
        $contracts = $services->contractModule()->service();
        $contracts->save($database, $contracts->create(new ContractCreationRequest(new ContractId($id . '-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2026-05-31'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));

        return [$services, $database, $store, $season, $player];
    }

    /** @return array{0:\Goal\Legacy\Modules\Transfer\CareerMovementService,1:array<string,mixed>} */
    private function acceptLoan(object $services, object $database, Season $season, object $player): array
    {
        $movement = $services->transferModule()->service()->careerMovement();
        $opportunity = $movement->prepareControlledLoanDecision($database, $player->id(), $season, SimulationDate::fromIsoString('2024-08-01'));
        self::assertNotNull($opportunity);
        $option = array_values(array_filter($opportunity->context()['options'], static fn (array $row): bool => ($row['kind'] ?? null) === 'accept_loan'))[0] ?? null;
        self::assertIsArray($option);
        $resolved = $movement->resolveLoanDecision($database, $opportunity->id(), (string) $option['id'], SimulationDate::fromIsoString('2024-08-01'));
        self::assertSame('accepted', $resolved->context()['offer_status']);

        return [$movement, $option];
    }

    private function competitionForClub($database, string $clubId, Season $season): string
    {
        $statement = $database->connection()->prepare('SELECT competition_id FROM club_competition_memberships WHERE club_id = :club_id AND season_id = :season_id ORDER BY competition_id LIMIT 1');
        $statement->execute(['club_id' => $clubId, 'season_id' => $season->id()->value()]);
        $competitionId = $statement->fetchColumn();
        self::assertIsString($competitionId);

        return $competitionId;
    }

    private function opponentForClub($database, string $competitionId, string $clubId, Season $season): string
    {
        $statement = $database->connection()->prepare('SELECT club_id FROM club_competition_memberships WHERE competition_id = :competition_id AND season_id = :season_id AND club_id <> :club_id ORDER BY club_id LIMIT 1');
        $statement->execute(['competition_id' => $competitionId, 'season_id' => $season->id()->value(), 'club_id' => $clubId]);
        $opponentId = $statement->fetchColumn();
        self::assertIsString($opponentId);

        return $opponentId;
    }

    private function socialSourceCount($database, string $playerId, string $sourceKey): int
    {
        $statement = $database->connection()->prepare("SELECT COUNT(*) FROM player_social_history WHERE player_id = :player_id AND source_key = :source_key || '|' || :player_id");
        $statement->execute(['player_id' => $playerId, 'source_key' => $sourceKey]);

        return (int) $statement->fetchColumn();
    }

    private function recordMatch($database, Season $season, string $competitionId, PlayerId $playerId, string $matchId, string $clubId, string $opponentId, string $date, int $minutes, int $goals, int $assists): void
    {
        $match = new GameMatch(new MatchId($matchId), new CompetitionId($competitionId), $season->id(), 1, SimulationDate::fromIsoString($date), new ClubId($clubId), new ClubId($opponentId));
        (new MatchRepository($database))->save($match->complete(new MatchResult($goals, 0)));
        (new PlayerMatchStatRepository($database))->replaceForMatch([new PlayerMatchStat($match->id(), $playerId, new ClubId($clubId), true, true, $minutes, $goals, $assists)]);
    }
}
