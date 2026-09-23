<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Devtools\Presentation\CareerPresentationService;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Club\Persistence\ClubSquadRepository;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Competition\Persistence\PlayerRegistrationRepository;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Contract\Domain\ContractStatus;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\MatchSelectionService;
use Goal\Legacy\Modules\Player\CareerLegacyService;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\Injury;
use Goal\Legacy\Modules\Player\Domain\InjuryCategory;
use Goal\Legacy\Modules\Player\Domain\InjurySeverity;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCareerState;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\TrainingFocus;
use Goal\Legacy\Modules\Player\Persistence\CareerEventRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerOpportunityRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerAvailabilityRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRetirementRepository;
use Goal\Legacy\Modules\Transfer\Domain\Loan;
use Goal\Legacy\Modules\Transfer\Domain\LoanStatus;
use Goal\Legacy\Modules\Transfer\Persistence\LoanRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use Goal\Legacy\Modules\World\Persistence\SeasonRepository;
use PHPUnit\Framework\TestCase;

final class P2042RetirementDecisionTest extends TestCase
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

    public function testCanonicalEligibilityControlsExactlyTwoChoicesAndContinueIsSuppressed(): void
    {
        [$services, $database, $store, $next, $saveId] = $this->scenario('p2042-continue');
        $players = $services->playerModule()->service();
        $young = $players->create($this->request('p2042-young', '2007-01-01'));
        $players->initializeCareer($database, $young, new CareerPlayerReference(new CareerId('p2042-young-career'), $young->id(), $this->date('2024-08-01')), $this->membership($young, 'season-2024-25'));
        $npc = $players->create($this->request('p2042-npc', '1980-01-01'));
        $players->repository($database)->save($npc);
        $player = $this->controlledPlayer($services, $database, 'p2042-contract-2', true);
        $lifecycle = $players->lifecycleService();

        $assessment = $lifecycle->retirementAssessment($database, (new PlayerRepository($database))->get($player->id()), $next->startDate());
        self::assertTrue($assessment['eligible']);
        self::assertSame(2, count($this->options($lifecycle, $database, $next, $player)));
        self::assertFalse($lifecycle->retirementAssessment($database, $young, $next->startDate())['eligible']);

        $opportunity = (new CareerOpportunityRepository($database))->openForPlayer($player->id(), $next->startDate())[0];
        self::assertSame(['continue-playing', 'retire'], array_column($opportunity->context()['options'], 'id'));
        self::assertCount(0, (new CareerEventRepository($database))->resolvedForPlayer($player->id(), 20));
        $lifecycle->resolveRetirementDecision($database, $opportunity->id(), 'continue-playing', $next->startDate());
        self::assertSame(PlayerCareerState::Active, (new PlayerRepository($database))->get($player->id())->careerState());
        self::assertSame(ContractStatus::Active, $services->contractModule()->service()->repository($database)->activeForPlayer($player->id())?->status());

        $database->transaction(fn (): array => $lifecycle->processSeasonBoundaryInTransaction($database, $next));
        self::assertCount(0, (new CareerOpportunityRepository($database))->openForPlayer($player->id(), $next->startDate()));
        self::assertNotNull((new CareerOpportunityRepository($database))->bySourceKey('retirement|' . $player->id()->value() . '|' . $next->id()->value()));

        $reloaded = $store->openDatabase($saveId);
        self::assertSame(PlayerCareerState::Active, (new PlayerRepository($reloaded))->get($player->id())->careerState());
        self::assertCount(0, (new CareerOpportunityRepository($reloaded))->openForPlayer($player->id(), $next->startDate()));
        self::assertSame(PlayerCareerState::Retired, (new PlayerRepository($database))->get($npc->id())->careerState());
        self::assertNull((new PlayerRetirementRepository($database, false))->get($npc->id()));
    }

    public function testFreeAgentAndInjuredPlayersUseCanonicalRetirementClosure(): void
    {
        [$services, $database, , $next] = $this->scenario('p2042-free-injury');
        $freeAgent = $this->controlledPlayer($services, $database, 'p2042-free-2', false);
        $injured = $this->controlledPlayer($services, $database, 'p2042-injury-0', true);
        (new PlayerAvailabilityRepository($database))->saveInjuryInTransaction(new Injury(
            'p2042-injury', $injured->id(), 'test', 'p2042-injury', InjuryCategory::Impact, InjurySeverity::Major,
            $this->date('2025-07-01'), $this->date('2025-12-01'),
        ));
        $lifecycle = $services->playerModule()->service()->lifecycleService();
        $database->transaction(fn (): array => $lifecycle->processSeasonBoundaryInTransaction($database, $next));

        foreach ([$freeAgent, $injured] as $player) {
            self::assertCount(1, (new CareerOpportunityRepository($database))->openForPlayer($player->id(), $next->startDate()));
            $opportunity = (new CareerOpportunityRepository($database))->openForPlayer($player->id(), $next->startDate())[0];
            $lifecycle->resolveRetirementDecision($database, $opportunity->id(), 'retire', $next->startDate());
            $lifecycle->resolveRetirementDecision($database, $opportunity->id(), 'retire', $next->startDate());
        }

        $players = new PlayerRepository($database);
        self::assertSame(PlayerCareerState::Retired, $players->get($freeAgent->id())->careerState());
        self::assertSame(PlayerCareerState::Retired, $players->get($injured->id())->careerState());
        self::assertNotNull((new PlayerAvailabilityRepository($database))->activeInjuryAt($injured->id(), $next->startDate()));
        self::assertSame(1, count((new CareerEventRepository($database))->resolvedForPlayer($freeAgent->id(), 20)));
        self::assertSame(1, count((new CareerEventRepository($database))->resolvedForPlayer($injured->id(), 20)));
        self::assertSame([], $lifecycle->integrity($database));
    }

    public function testActiveLoanDefersDecisionAndRetirementRejectsPlayingActions(): void
    {
        [$services, $database, , $next] = $this->scenario('p2042-loan');
        $player = $this->controlledPlayer($services, $database, 'p2042-loan-3', true);
        $nextMembership = $this->membership($player, $next->id()->value());
        (new ClubSquadRepository($database))->save($nextMembership);
        $loans = new LoanRepository($database);
        $loan = new Loan('p2042-loan', $player->id(), new ClubId('arsenal'), new ClubId('chelsea'), $next->id(), $next->startDate(), $next->endDate(), SquadRole::Regular, SquadRole::Rotation, LoanStatus::Active);
        $loans->save($loan);
        $lifecycle = $services->playerModule()->service()->lifecycleService();

        $database->transaction(fn (): array => $lifecycle->processSeasonBoundaryInTransaction($database, $next));
        self::assertCount(0, (new CareerOpportunityRepository($database))->openForPlayer($player->id(), $next->startDate()));
        $loans->save($loan->complete());
        $database->transaction(fn (): array => $lifecycle->processSeasonBoundaryInTransaction($database, $next));
        $opportunity = (new CareerOpportunityRepository($database))->openForPlayer($player->id(), $next->startDate())[0];
        $lifecycle->resolveRetirementDecision($database, $opportunity->id(), 'retire', $next->startDate());

        self::assertSame(LoanStatus::Completed, $loans->get($loan->id())?->status());
        self::assertSame('terminated', $services->contractModule()->service()->repository($database)->byPlayer($player->id())[0]->status()->value);
        self::assertCount(1, (new ClubSquadRepository($database))->byPlayer($player->id()));
        self::assertCount(0, (new ClubSquadRepository($database))->byPlayer($player->id(), $next->id()));
        $match = new GameMatch(new MatchId('p2042-retired-match'), new CompetitionId('premier-league'), $next->id(), 1, $next->startDate(), new ClubId('arsenal'), new ClubId('chelsea'));
        $eligible = (new MatchSelectionService($services->clubModule()->service()))->eligiblePlayers($database, $match, 'arsenal', new PlayerRepository($database));
        self::assertNotContains($player->id()->value(), array_map(static fn (Player $candidate): string => $candidate->id()->value(), $eligible));
        $this->expectException(\RuntimeException::class);
        $services->playerModule()->service()->careerExperienceService()->setTrainingFocus($database, $player->id(), TrainingFocus::Balanced, $next->startDate());
    }

    public function testFinalSeasonReviewAndCareerCompleteRemainCanonicalAfterRetirement(): void
    {
        [$services, $database, $store, $next, $saveId, $season] = $this->scenario('p2042-final');
        $player = $this->controlledPlayer($services, $database, 'p2042-final-10', true, $saveId);
        $completed = $season->activate()->complete();
        (new SeasonRepository($database))->save($completed);
        $match = (new GameMatch(new MatchId('p2042-final-match'), new CompetitionId('premier-league'), $season->id(), 1, $this->date('2025-05-01'), new ClubId('arsenal'), new ClubId('chelsea')))->complete(new MatchResult(1, 0));
        $services->matchModule()->service()->repository($database)->save($match);
        $services->matchModule()->service()->statRepository($database)->replaceForMatch([
            new \Goal\Legacy\Modules\Match\Domain\PlayerMatchStat($match->id(), $player->id(), new ClubId('arsenal'), true, true, 90, 1, 1, 1, 1),
        ]);
        $legacyService = new CareerLegacyService($services->clubModule()->service(), $services->nationalTeams(), $services->internationalCompetitions(), $services->playerModule()->service()->socialService());
        $legacyService->resolveCompletedSeason($database, $completed);
        $presentation = new CareerPresentationService($services);
        $beforeSnapshot = $presentation->snapshot($database, $saveId);
        $beforeReview = $presentation->seasonReview($database, $beforeSnapshot['summary'], $season->id()->value());
        $beforeLegacy = $legacyService->summary($database, $player->id()->value());

        $lifecycle = $services->playerModule()->service()->lifecycleService();
        $database->transaction(fn (): array => $lifecycle->processSeasonBoundaryInTransaction($database, $next));
        $opportunity = (new CareerOpportunityRepository($database))->openForPlayer($player->id(), $next->startDate())[0];
        $lifecycle->resolveRetirementDecision($database, $opportunity->id(), 'retire', $next->startDate());
        $afterSnapshot = $presentation->snapshot($database, $saveId);
        $afterReview = $presentation->seasonReview($database, $afterSnapshot['summary'], $season->id()->value());
        $afterLegacy = $legacyService->summary($database, $player->id()->value());

        self::assertSame($beforeReview['statistics'], $afterReview['statistics']);
        self::assertSame($beforeLegacy['awards'], $afterLegacy['awards']);
        self::assertSame($beforeLegacy['honours'], $afterLegacy['honours']);
        self::assertSame($beforeLegacy['records'], $afterLegacy['records']);
        self::assertSame($beforeLegacy['milestones'], $afterLegacy['milestones']);
        self::assertTrue($afterReview['season']['completed']);
        self::assertSame('retired', $presentation->achievementSummary($afterSnapshot['summary'])['career_state']);
        self::assertSame('retired', $presentation->trophyRoomSummary($database, $saveId)['career_state']);
        self::assertNotNull($afterLegacy['retirement']);

        $changesBefore = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();
        $presentation->snapshot($database, $saveId);
        $presentation->seasonReview($database, $afterSnapshot['summary'], $season->id()->value());
        $presentation->trophyRoomSummary($database, $saveId);
        $changesAfter = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();
        self::assertSame($changesBefore, $changesAfter);

        $reloaded = $store->openDatabase($saveId);
        self::assertSame(PlayerCareerState::Retired, (new PlayerRepository($reloaded))->get($player->id())->careerState());
        self::assertCount(0, (new CareerOpportunityRepository($reloaded))->openForPlayer($player->id(), $next->startDate()));
    }

    /** @return list<array<string, mixed>> */
    private function options(object $lifecycle, $database, Season $next, Player $player): array
    {
        $database->transaction(fn (): array => $lifecycle->processSeasonBoundaryInTransaction($database, $next));
        $opportunity = (new CareerOpportunityRepository($database))->openForPlayer($player->id(), $next->startDate())[0];
        return (array) ($opportunity->context()['options'] ?? []);
    }

    private function controlledPlayer($services, $database, string $id, bool $contract, ?string $careerId = null): Player
    {
        $players = $services->playerModule()->service();
        $player = $players->create($this->request($id, '1989-01-01'));
        $membership = $this->membership($player, 'season-2024-25');
        $players->initializeCareer($database, $player, new CareerPlayerReference(new CareerId($careerId ?? ($id . '-career')), $player->id(), $this->date('2024-08-01')), $membership);
        if (!$contract) {
            (new ClubSquadRepository($database))->remove($membership);
            return $player;
        }
        $contracts = $services->contractModule()->service();
        $contracts->save($database, $contracts->create(new ContractCreationRequest(new ContractId($id . '-contract'), $player->id(), new ClubId('arsenal'), $this->date('2024-08-01'), $this->date('2026-06-30'), 400, $this->date('2024-08-01'))));
        return $player;
    }

    private function membership(Player $player, string $seasonId): ClubSquadMembership
    {
        return new ClubSquadMembership(new ClubId('arsenal'), $player->id(), new SeasonId($seasonId), SquadRole::Regular);
    }

    private function request(string $id, string $birthDate): PlayerCreationRequest
    {
        return new PlayerCreationRequest($id, 'Retirement', 'Player', $id, $birthDate, 'england', [], 'england', ['england'], 180, 75, 'CM', 82, 'regular', 2042, new PlayerAttributeSet(65, 65, 65, 65, 65, 65));
    }

    private function date(string $value): SimulationDate
    {
        return SimulationDate::fromIsoString($value);
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:SqliteSaveStore,3:Season,4:string,5:Season} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', $this->date('2024-08-01'), $this->date('2025-05-31'));
        $next = new Season(new SeasonId('season-2025-26'), '2025/26', $this->date('2025-08-01'), $this->date('2026-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2042042, new DateTimeImmutable('@0'), $calendar->timeAt($this->date('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);
        (new SeasonRepository($database))->save($next);

        return [$services, $database, $store, $next, $id, $season];
    }
}
