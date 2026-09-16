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
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\Player\PlayerSeasonPerformanceService;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class Domain022Test extends TestCase
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

    public function testRealMatchStatisticsProduceNormalizedAssessmentAndReloadSafely(): void
    {
        [$services, $database, $season, $store] = $this->scenario('domain-022-production');
        $playerService = $services->playerModule()->service();
        $player = $playerService->create($this->request('performance-career-player', new PlayerAttributeSet(75, 75, 75, 75, 75, 75), 95));
        $playerService->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('domain-022-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id()));
        $contracts = $services->contractModule()->service();
        $contracts->save($database, $contracts->create(new ContractCreationRequest(new ContractId('domain-022-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2026-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));

        $fixtures = array_values(array_filter($services->matchModule()->service()->generateFixtures($database, 'premier-league', $season->id()), static fn ($match): bool => $match->homeClubId()->value() === 'arsenal' || $match->awayClubId()->value() === 'arsenal'));
        self::assertNotEmpty($fixtures);
        $matchService = $services->matchModule()->service();
        $services->worldModule()->service()->advanceToDate($database, 'domain-022-production', $fixtures[0]->scheduledDate());
        $completed = $matchService->simulateDue($database, $fixtures[0]->scheduledDate());
        self::assertNotEmpty($completed);
        self::assertNotEmpty(array_values(array_filter($completed, fn ($match): bool => $matchService->playerSummary($database, $match->id(), $player->id()) !== null)));

        $performance = (new PlayerSeasonPerformanceService())->assess($database, $player->id(), $season->id(), new ClubId('arsenal'));
        self::assertSame('breakout', $performance->classification());
        self::assertSame(1, $performance->statistics()['appearances']);
        self::assertSame(1, $performance->statistics()['expected_matches']);
        self::assertGreaterThan(0, $performance->score());

        $next = new Season(new SeasonId('season-2025-26'), '2025/26', SimulationDate::fromIsoString('2025-08-01'), SimulationDate::fromIsoString('2026-05-31'));
        $decision = $services->transferModule()->service()->careerMovement()->prepareContractDecision($database, $player->id(), $season, $next, $fixtures[0]->scheduledDate(), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id()), true);
        self::assertNotNull($decision);
        self::assertSame('breakout', $decision->context()['performance']['classification']);

        $summary = (new PlayerCareerProgressionQuery($services->clubModule()->service()))->summary($database, $player->id(), $fixtures[0]->scheduledDate(), $season->id());
        self::assertSame($performance->toArray(), $summary['season_performance']);
        unset($database);
        $database = $store->openDatabase('domain-022-production');
        self::assertSame($summary, (new PlayerCareerProgressionQuery($services->clubModule()->service()))->summary($database, $player->id(), $fixtures[0]->scheduledDate(), $season->id()));
    }

    public function testHighOvrWithoutAuthoritativeParticipationIsInsufficientEvidence(): void
    {
        [$services, $database, $season] = $this->scenario('domain-022-no-evidence');
        $player = $services->playerModule()->service()->create($this->request('no-evidence-player', new PlayerAttributeSet(99, 99, 99, 99, 99, 99), 99));
        $services->playerModule()->service()->repository($database)->save($player);

        $assessment = (new PlayerSeasonPerformanceService())->assess($database, $player->id(), $season->id());
        self::assertSame('insufficient_evidence', $assessment->classification());
        self::assertSame(0, $assessment->score());
        self::assertSame(0, $assessment->statistics()['appearances']);
    }

    public function testParticipationBandsAreNormalizedAndPositionNeutral(): void
    {
        [$services, $database, $season] = $this->scenario('domain-022-bands');
        $playerService = $services->playerModule()->service();
        $steady = $playerService->create($this->request('steady-player', new PlayerAttributeSet(60, 60, 60, 60, 60, 60), 80));
        $limited = $playerService->create($this->request('limited-player', new PlayerAttributeSet(60, 60, 60, 60, 60, 60), 80));
        $goalkeeper = $playerService->create(new PlayerCreationRequest('goalkeeper-player', 'Performance', 'Player', 'goalkeeper-player', '2005-01-01', 'england', [], 'england', ['england'], 190, 75, 'GK', 80, 'regular', 9022, new PlayerAttributeSet(60, 60, 60, 60, 60, 60)));
        foreach ([$steady, $limited, $goalkeeper] as $player) {
            $playerService->repository($database)->save($player);
        }
        $services->matchModule()->service()->generateFixtures($database, 'premier-league', $season->id());
        $fixtures = array_values(array_filter((new MatchRepository($database))->byClub(new ClubId('arsenal'), $season->id()), static fn ($match): bool => $match->status()->value === 'scheduled'));
        self::assertGreaterThanOrEqual(4, count($fixtures));
        $stats = new PlayerMatchStatRepository($database);
        foreach (array_slice($fixtures, 0, 4) as $index => $fixture) {
            $completed = $fixture->complete(new MatchResult(1, 0));
            (new MatchRepository($database))->save($completed);
            if ($index === 0) {
                $stats->replaceForMatch([
                    new PlayerMatchStat($fixture->id(), $steady->id(), new ClubId('arsenal'), true, true, 90, 0),
                    new PlayerMatchStat($fixture->id(), $limited->id(), new ClubId('arsenal'), true, false, 20, 0),
                    new PlayerMatchStat($fixture->id(), $goalkeeper->id(), new ClubId('arsenal'), true, true, 90, 0),
                ]);
            }
        }
        $service = new PlayerSeasonPerformanceService();
        self::assertSame('steady', $service->assess($database, $steady->id(), $season->id(), new ClubId('arsenal'))->classification());
        self::assertSame('limited', $service->assess($database, $limited->id(), $season->id(), new ClubId('arsenal'))->classification());
        self::assertSame('steady', $service->assess($database, $goalkeeper->id(), $season->id(), new ClubId('arsenal'))->classification());
    }

    public function testSeasonRatingsArePositionAwareTransferSafeAndExcludeIncompleteEvidence(): void
    {
        [$services, $database, $season] = $this->scenario('domain-033-season-ratings');
        $players = $services->playerModule()->service();
        $attacker = $players->create($this->request('rating-attacker', new PlayerAttributeSet(70, 90, 70, 70, 40, 70), 90, 'ST'));
        $midfielder = $players->create($this->request('rating-midfielder', new PlayerAttributeSet(70, 65, 90, 70, 80, 75), 90, 'CM'));
        $defender = $players->create($this->request('rating-defender', new PlayerAttributeSet(65, 45, 70, 60, 90, 85), 90, 'CB'));
        $goalkeeper = $players->create($this->request('rating-goalkeeper', new PlayerAttributeSet(60, 40, 70, 50, 70, 75), 90, 'GK'));
        $ordinary = $players->create($this->request('rating-ordinary', new PlayerAttributeSet(60, 60, 60, 60, 60, 60), 90, 'CM'));
        $short = $players->create($this->request('rating-short', new PlayerAttributeSet(70, 90, 70, 70, 40, 70), 90, 'ST'));
        $weak = $players->create($this->request('rating-weak', new PlayerAttributeSet(60, 40, 50, 50, 50, 50), 90, 'CM'));
        $volume = $players->create($this->request('rating-volume', new PlayerAttributeSet(60, 50, 70, 50, 50, 50), 90, 'CM'));
        $transfer = $players->create($this->request('rating-transfer', new PlayerAttributeSet(65, 45, 70, 60, 90, 85), 90, 'CB'));
        $improving = $players->create($this->request('rating-improving', new PlayerAttributeSet(60, 85, 70, 60, 60, 60), 90, 'CM'));
        $declining = $players->create($this->request('rating-declining', new PlayerAttributeSet(60, 85, 70, 60, 60, 60), 90, 'CM'));
        $strongSeasonPoorRecent = $players->create($this->request('rating-strong-season-poor-recent', new PlayerAttributeSet(60, 85, 70, 60, 60, 60), 90, 'CM'));
        $weakSeasonStrongRecent = $players->create($this->request('rating-weak-season-strong-recent', new PlayerAttributeSet(60, 85, 70, 60, 60, 60), 90, 'CM'));
        foreach ([$attacker, $midfielder, $defender, $goalkeeper, $ordinary, $short, $weak, $volume, $transfer, $improving, $declining, $strongSeasonPoorRecent, $weakSeasonStrongRecent] as $player) { $players->repository($database)->save($player); }

        $matches = $services->matchModule()->service()->generateFixtures($database, 'premier-league', $season->id());
        $arsenal = array_values(array_filter($matches, static fn ($match): bool => $match->homeClubId()->value() === 'arsenal' || $match->awayClubId()->value() === 'arsenal'));
        $chelsea = array_values(array_filter($matches, static fn ($match): bool => ($match->homeClubId()->value() === 'chelsea' || $match->awayClubId()->value() === 'chelsea') && !in_array($match->id()->value(), array_map(static fn ($match): string => $match->id()->value(), array_slice($arsenal, 0, 10)), true)));
        $matchesRepository = new MatchRepository($database);
        $stats = new PlayerMatchStatRepository($database);
        $formCandidates = [...array_slice($arsenal, 0, 10), ...array_slice($chelsea, 4, 5)];
        usort($formCandidates, static fn ($left, $right): int => strcmp($right->scheduledDate()->toIsoString(), $left->scheduledDate()->toIsoString()));
        $recentStrongMatchIds = array_map(static fn ($match): string => $match->id()->value(), array_slice($formCandidates, 0, 5));
        foreach (array_slice($arsenal, 0, 10) as $index => $match) {
            $matchesRepository->save($match->complete(new MatchResult(1, 0)));
            $rows = [
                new PlayerMatchStat($match->id(), $attacker->id(), new ClubId('arsenal'), true, true, 90, 2, 0, 2, 2),
                new PlayerMatchStat($match->id(), $midfielder->id(), new ClubId('arsenal'), true, true, 90, 0, 1, 0, 0, 0, 0, 3, 3, 1, 50, 45),
                new PlayerMatchStat($match->id(), $defender->id(), new ClubId('arsenal'), true, true, 90, 0, 0, 0, 0, 0, 1, 4, 3, 2, 35, 30),
                new PlayerMatchStat($match->id(), $goalkeeper->id(), new ClubId('arsenal'), true, true, 90, 0, 0, 0, 0, 4, 1, 0, 0, 0, 25, 20),
                new PlayerMatchStat($match->id(), $ordinary->id(), new ClubId('arsenal'), true, true, 90, 0),
                new PlayerMatchStat($match->id(), $weak->id(), new ClubId('arsenal'), true, true, 90, 0, 0, 0, 0, 0, 0, 0, 0, 0, 20, 10, 4, 2, 1),
                new PlayerMatchStat($match->id(), $volume->id(), new ClubId('arsenal'), true, true, 90, 0, 0, 0, 0, 0, 0, 0, 0, 0, 100, 70),
            ];
            if ($index < 5) {
                $strong = static fn ($player): PlayerMatchStat => new PlayerMatchStat($match->id(), $player->id(), new ClubId('arsenal'), true, true, 90, 2, 0, 2, 2);
                $weakStat = static fn ($player): PlayerMatchStat => new PlayerMatchStat($match->id(), $player->id(), new ClubId('arsenal'), true, true, 90, 0, 0, 0, 0, 0, 0, 0, 0, 0, 20, 10, 4, 2, 1);
                $neutral = static fn ($player): PlayerMatchStat => new PlayerMatchStat($match->id(), $player->id(), new ClubId('arsenal'), true, true, 90, 0);
                $rows[] = $index < 2 ? $weakStat($improving) : ($index === 2 ? $neutral($improving) : $strong($improving));
                $rows[] = $index < 2 ? $strong($declining) : ($index === 2 ? $neutral($declining) : $weakStat($declining));
            }
            $rows[] = $index < 5
                ? new PlayerMatchStat($match->id(), $strongSeasonPoorRecent->id(), new ClubId('arsenal'), true, true, 90, 2, 0, 2, 2)
                : new PlayerMatchStat($match->id(), $strongSeasonPoorRecent->id(), new ClubId('arsenal'), true, true, 90, 0, 0, 0, 0, 0, 0, 0, 0, 0, 20, 10, 4, 2, 1);
            $rows[] = in_array($match->id()->value(), $recentStrongMatchIds, true)
                ? new PlayerMatchStat($match->id(), $weakSeasonStrongRecent->id(), new ClubId('arsenal'), true, true, 90, 2, 0, 2, 2)
                : new PlayerMatchStat($match->id(), $weakSeasonStrongRecent->id(), new ClubId('arsenal'), true, true, 90, 0, 0, 0, 0, 0, 0, 0, 0, 0, 20, 10, 4, 2, 1);
            if ($index === 0) { $rows[] = new PlayerMatchStat($match->id(), $short->id(), new ClubId('arsenal'), true, false, 15, 2, 0, 2, 2); }
            if ($index < 4) { $rows[] = new PlayerMatchStat($match->id(), $transfer->id(), new ClubId('arsenal'), true, true, 90, 0, 0, 0, 0, 0, 1, 4, 3, 2, 35, 30); }
            $stats->replaceForMatch($rows);
        }
        foreach (array_slice($chelsea, 0, 4) as $match) {
            $matchesRepository->save($match->complete(new MatchResult(1, 0)));
            $stats->replaceForMatch([new PlayerMatchStat($match->id(), $transfer->id(), new ClubId('chelsea'), true, true, 90, 0, 0, 0, 0, 0, 1, 4, 3, 2, 35, 30)]);
        }
        $incomplete = $arsenal[10];
        $stats->replaceForMatch([new PlayerMatchStat($incomplete->id(), $attacker->id(), new ClubId('arsenal'), true, true, 90, 9, 0, 9, 9)]);
        foreach (array_slice($chelsea, 4, 5) as $match) {
            $matchesRepository->save($match->complete(new MatchResult(1, 0)));
            $stats->replaceForMatch([in_array($match->id()->value(), $recentStrongMatchIds, true)
                ? new PlayerMatchStat($match->id(), $weakSeasonStrongRecent->id(), new ClubId('chelsea'), true, true, 90, 2, 0, 2, 2)
                : new PlayerMatchStat($match->id(), $weakSeasonStrongRecent->id(), new ClubId('chelsea'), true, true, 90, 0, 0, 0, 0, 0, 0, 0, 0, 0, 20, 10, 4, 2, 1)]);
        }

        $service = new PlayerSeasonPerformanceService();
        foreach ([$attacker, $midfielder, $defender, $goalkeeper] as $player) { self::assertSame('breakout', $service->assess($database, $player->id(), $season->id())->classification()); }
        self::assertSame('steady', $service->assess($database, $ordinary->id(), $season->id())->classification());
        self::assertSame('insufficient_evidence', $service->assess($database, $short->id(), $season->id())->classification());
        self::assertSame('stagnant', $service->assess($database, $weak->id(), $season->id())->classification());
        self::assertSame('steady', $service->assess($database, $volume->id(), $season->id())->classification());
        self::assertSame('insufficient_evidence', $service->assess($database, 'no-season-appearances', $season->id())->classification());
        $transferAssessment = $service->assess($database, $transfer->id(), $season->id(), new ClubId('chelsea'));
        self::assertSame(8, $transferAssessment->statistics()['rated_appearances']);
        self::assertSame('breakout', $transferAssessment->classification());
        $attackerAssessment = $service->assess($database, $attacker->id(), $season->id());
        self::assertSame(10, $attackerAssessment->statistics()['rated_appearances']);
        $attackerStat = array_values(array_filter($stats->byMatch($arsenal[0]->id()), static fn (PlayerMatchStat $stat): bool => $stat->playerId()->value() === 'rating-attacker'))[0];
        self::assertSame((new \Goal\Legacy\Modules\Match\PlayerMatchRatingService())->rate($attackerStat, $attacker->primaryPosition()), $attackerAssessment->statistics()['average_match_rating']);
        $form = new \Goal\Legacy\Modules\Player\PlayerFormService();
        self::assertSame('insufficient_evidence', $form->recent($database, $short->id())['classification']);
        foreach ([$attacker, $midfielder, $defender, $goalkeeper] as $player) { self::assertContains($form->recent($database, $player->id())['classification'], ['good', 'excellent']); }
        self::assertSame('neutral', $form->recent($database, $ordinary->id())['classification']);
        self::assertSame('poor', $form->recent($database, $weak->id())['classification']);
        self::assertGreaterThan($form->recent($database, $declining->id())['average_match_rating'], $form->recent($database, $improving->id())['average_match_rating']);
        self::assertSame('strong', $service->assess($database, $strongSeasonPoorRecent->id(), $season->id())->classification());
        self::assertSame('poor', $form->recent($database, $strongSeasonPoorRecent->id())['classification']);
        self::assertContains($service->assess($database, $weakSeasonStrongRecent->id(), $season->id())->classification(), ['steady', 'limited', 'stagnant']);
        self::assertContains($form->recent($database, $weakSeasonStrongRecent->id())['classification'], ['good', 'excellent']);
        self::assertSame(5, $form->recent($database, $transfer->id())['rated_appearances']);
        $attackerForm = $form->recent($database, $attacker->id());
        self::assertSame(5, $attackerForm['appearances']);
        $players->repository($database)->save($attacker->withAttributes(new PlayerAttributeSet(90, 90, 90, 90, 90, 90)));
        self::assertSame($attackerAssessment->toArray(), $service->assess($database, $attacker->id(), $season->id())->toArray());
        self::assertSame($attackerForm, $form->recent($database, $attacker->id()));
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season,3:SqliteSaveStore} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2026022, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $season, $store];
    }

    private function request(string $id, PlayerAttributeSet $attributes, int $potential, string $position = 'CM'): PlayerCreationRequest
    {
        return new PlayerCreationRequest($id, 'Performance', 'Player', $id, '2005-01-01', 'england', [], 'england', ['england'], 180, 75, $position, $potential, 'regular', 9022, $attributes);
    }
}
