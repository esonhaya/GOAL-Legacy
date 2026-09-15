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

    private function request(string $id, PlayerAttributeSet $attributes, int $potential): PlayerCreationRequest
    {
        return new PlayerCreationRequest($id, 'Performance', 'Player', $id, '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', $potential, 'regular', 9022, $attributes);
    }
}
