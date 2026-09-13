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
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\TrainingRequest;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\Player\Persistence\PlayerDevelopmentRepository;
use Goal\Legacy\Modules\Transfer\Domain\Transfer;
use Goal\Legacy\Modules\Transfer\Domain\TransferExecutionTerms;
use Goal\Legacy\Modules\Transfer\Domain\TransferId;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PDOException;
use PHPUnit\Framework\TestCase;

final class Domain007Test extends TestCase
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

    public function testAgeProfileAndTrainingAreDeterministicAndIdempotent(): void
    {
        [$services, $database] = $this->scenario('domain-007-training');
        $playerService = $services->playerModule()->service();
        $attributes = new PlayerAttributeSet(50, 50, 50, 50, 50, 50);
        $late = $playerService->create($this->request('late-player', 'late_bloomer', $attributes));
        $regular = $playerService->create($this->request('regular-player', 'regular', $attributes));
        $prodigy = $playerService->create($this->request('prodigy-player', 'prodigy', $attributes));
        $repository = $playerService->repository($database);
        foreach ([$late, $regular, $prodigy] as $player) { $repository->save($player); }
        self::assertSame(19, $late->ageAt(SimulationDate::fromIsoString('2024-08-01')));
        self::assertSame(18, $late->ageAt(SimulationDate::fromIsoString('2023-12-31')));
        $development = $playerService->developmentService();
        $request = new TrainingRequest($prodigy->id(), 'training-1', 'passing', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2024-09-26'));
        $first = $playerService->trainingService()->complete($database, $request);
        $second = $playerService->trainingService()->complete($database, $request);
        self::assertTrue($first->applied());
        self::assertFalse($second->applied());
        self::assertSame($first->afterOverall(), $second->afterOverall());
        self::assertGreaterThan($late->overallRating(), $repository->get($prodigy->id())->overallRating());
        self::assertCount(1, $development->history($database, $prodigy->id()));
        self::assertSame([], array_diff($repository->get($prodigy->id())->attributes()->toArray(), array_filter($repository->get($prodigy->id())->attributes()->toArray(), static fn (int $value): bool => $value <= 99)));
    }

    public function testTrainingFocusTargetsAttributesAndPotentialIsARealCeiling(): void
    {
        [$services, $database] = $this->scenario('domain-007-ceiling');
        $playerService = $services->playerModule()->service();
        $player = $playerService->create($this->request('ceiling-player', 'regular', new PlayerAttributeSet(70, 70, 70, 70, 70, 70), 70));
        $playerService->repository($database)->save($player);
        $development = $playerService->developmentService();
        $result = $playerService->trainingService()->complete($database, new TrainingRequest($player->id(), 'ceiling-block', 'pace', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-08-01')));
        $updated = $playerService->repository($database)->get($player->id());
        self::assertLessThanOrEqual($player->potential(), $updated->overallRating());
        self::assertGreaterThanOrEqual($result->attributeDeltas()['passing'] ?? 0, $result->attributeDeltas()['pace'] ?? 0);
        self::assertSame('pace', $development->state($database, $player->id())->currentFocus()?->value);
    }

    public function testMatchDevelopmentAndStatisticsArePersistedExactlyOnce(): void
    {
        [$services, $database, $season, $store] = $this->scenario('domain-007-match');
        $matchService = $services->matchModule()->service();
        $matches = $matchService->generateFixtures($database, 'premier-league', $season->id());
        $playerService = $services->playerModule()->service();
        $player = $playerService->create($this->request('career-player', 'regular', new PlayerAttributeSet(50, 50, 50, 50, 50, 50)));
        $playerService->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('domain-007-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id()));
        $contract = $services->contractModule()->service();
        $contract->save($database, $contract->create(new ContractCreationRequest(new ContractId('domain-007-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2025-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));
        $date = $matches[0]->scheduledDate();
        $services->worldModule()->service()->advanceToDate($database, 'domain-007-match', $date);
        $completed = $matchService->simulateDue($database, $date);
        self::assertCount(10, $completed);
        $match = null;
        foreach ($completed as $candidate) { if ($matchService->playerSummary($database, $candidate->id(), $player->id()) !== null) { $match = $candidate; break; } }
        self::assertNotNull($match);
        $development = $playerService->developmentService();
        self::assertCount(1, $development->history($database, $player->id()));
        $before = $playerService->repository($database)->get($player->id())->toArray();
        $development->applyMatch($database, $match);
        self::assertSame($before, $playerService->repository($database)->get($player->id())->toArray());
        $stats = new \Goal\Legacy\Modules\Player\PlayerCareerStatisticsService();
        self::assertSame(['appearances' => 1, 'starts' => 1, 'minutes' => 90, 'goals' => $matchService->playerSummary($database, $match->id(), $player->id())['goals']], $stats->season($database, $player->id(), $season->id()));
        $summary = (new PlayerCareerProgressionQuery($services->clubModule()->service()))->summary($database, $player->id(), $date, $season->id());
        self::assertSame($player->id()->value(), $summary['player']['id']);
        unset($database);
        $database = $store->openDatabase('domain-007-match');
        self::assertSame($summary, (new PlayerCareerProgressionQuery($services->clubModule()->service()))->summary($database, $player->id(), $date, $season->id()));
    }

    public function testMatchTransactionRollsBackDevelopmentWithMatchWrites(): void
    {
        [$services, $database, $season] = $this->scenario('domain-007-rollback');
        $matchService = $services->matchModule()->service();
        $match = $matchService->generateFixtures($database, 'premier-league', $season->id())[0];
        $matchService->highlightRepository($database);
        $database->connection()->exec("CREATE TRIGGER fail_domain_007_highlight BEFORE INSERT ON match_highlights BEGIN SELECT RAISE(ABORT, 'forced domain 007 failure'); END");
        try { $matchService->simulate($database, $match->id()); self::fail('The forced Match failure should abort the transaction.'); } catch (PDOException $exception) { self::assertStringContainsString('forced domain 007 failure', $exception->getMessage()); }
        self::assertSame(MatchStatus::Scheduled, $matchService->repository($database)->get($match->id())->status());
        self::assertSame([], $matchService->statRepository($database)->byMatch($match->id()));
        self::assertSame([], $matchService->highlightRepository($database)->byMatch($match->id()));
        self::assertSame([], (new PlayerDevelopmentRepository($database))->byPlayer(new \Goal\Legacy\Modules\Player\Domain\PlayerId('missing-player')));
    }

    public function testTransferPreservesPlayerDevelopmentStateAndHistory(): void
    {
        [$services, $database, $season] = $this->scenario('domain-007-transfer');
        $playerService = $services->playerModule()->service();
        $player = $playerService->create($this->request('continuity-player', 'prodigy', new PlayerAttributeSet(50, 50, 50, 50, 50, 50)));
        $playerService->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('continuity-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id()));
        $contracts = $services->contractModule()->service();
        $contracts->save($database, $contracts->create(new ContractCreationRequest(new ContractId('continuity-source-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2025-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));
        $playerService->trainingService()->complete($database, new TrainingRequest($player->id(), 'continuity-training', 'passing', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2024-09-26')));
        $before = $playerService->repository($database)->get($player->id())->toArray();
        $transfer = new Transfer(new TransferId('continuity-transfer'), $player->id(), new ClubId('arsenal'), new ClubId('chelsea'), $season->id(), 0, SimulationDate::fromIsoString('2024-10-01'));
        $transferService = $services->transferModule()->service(); $transferService->save($database, $transfer); $transferService->execute($database, $transfer, new TransferExecutionTerms(new ContractId('continuity-destination-contract'), SimulationDate::fromIsoString('2025-06-30'), 150));
        self::assertSame($before, $playerService->repository($database)->get($player->id())->toArray());
        self::assertCount(1, $playerService->developmentService()->history($database, $player->id()));
        self::assertSame($player->id()->value(), $playerService->careerRepository($database)->get('continuity-career')->playerId()->value());
    }

    /** @return array{0: \Goal\Legacy\Core\Bootstrap\CoreServices, 1: \Goal\Legacy\Core\Persistence\DatabaseInterface, 2: Season, 3: SqliteSaveStore} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2026007, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8)); mkdir($directory, 0775, true); $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer()); $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0'))); $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $season, $store];
    }

    private function request(string $id, string $profile, PlayerAttributeSet $attributes, int $potential = 90): PlayerCreationRequest
    {
        return new PlayerCreationRequest($id, 'Career', 'Player', $id, '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', $potential, $profile, 17, $attributes);
    }
}
