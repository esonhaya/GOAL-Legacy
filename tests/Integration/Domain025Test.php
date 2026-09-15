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
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\Transfer\Domain\Transfer;
use Goal\Legacy\Modules\Transfer\Domain\TransferExecutionTerms;
use Goal\Legacy\Modules\Transfer\Domain\TransferId;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SeasonStatus;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use Goal\Legacy\Modules\World\Persistence\SeasonRepository;
use PHPUnit\Framework\TestCase;

final class Domain025Test extends TestCase
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

    public function testCareerHubComposesProductionSeasonPerformanceRoleDevelopmentAndReload(): void
    {
        [$services, $database, $season, $store] = $this->scenario('domain-025-production');
        $playerService = $services->playerModule()->service();
        $player = $playerService->create($this->request('hub-production-player', new PlayerAttributeSet(72, 72, 72, 72, 72, 72), 95));
        $playerService->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('hub-production-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Prospect));
        $contracts = $services->contractModule()->service();
        $contracts->save($database, $contracts->create(new ContractCreationRequest(new ContractId('hub-production-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2026-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));

        $matches = array_values(array_filter($services->matchModule()->service()->generateFixtures($database, 'premier-league', $season->id()), static fn ($match): bool => $match->homeClubId()->value() === 'arsenal' || $match->awayClubId()->value() === 'arsenal'));
        self::assertNotEmpty($matches);
        $matchService = $services->matchModule()->service();
        $services->worldModule()->service()->advanceToDate($database, 'domain-025-production', $matches[0]->scheduledDate());
        $completed = $matchService->simulateDue($database, $matches[0]->scheduledDate());
        self::assertNotEmpty(array_filter($completed, fn ($match): bool => $matchService->playerSummary($database, $match->id(), $player->id()) !== null));

        $completedSeason = (new SeasonRepository($database))->get($season->id())->complete();
        (new SeasonRepository($database))->save($completedSeason);
        $rollover = $services->worldModule()->service()->seasonRollover();
        self::assertNotNull($rollover);
        $world = (new \Goal\Legacy\Modules\World\Persistence\WorldRepository($database))->get('domain-025-production');
        $rollover->prepareNext($database, $world, $completedSeason, SimulationDate::fromIsoString('2025-06-01'));
        $next = $rollover->nextSeason($completedSeason);
        $rollover->materializeNext($database, $world, $completedSeason, $next, SimulationDate::fromIsoString('2025-06-01'));
        $nextStored = (new SeasonRepository($database))->get($next->id())->activate();
        (new SeasonRepository($database))->save($nextStored);
        $rollover->activateNext($database, $nextStored);

        $query = new PlayerCareerProgressionQuery($services->clubModule()->service());
        $summary = $query->summary($database, $player->id(), SimulationDate::fromIsoString('2025-08-01'), $next->id());
        self::assertSame('arsenal', $summary['current_club']['id']);
        self::assertSame('premier-league', $summary['current_competition']['id']);
        self::assertSame('rotation', $summary['current_role']);
        self::assertSame('arsenal', $summary['current_contract']['club']['id']);
        self::assertGreaterThanOrEqual(2, count($summary['season_history']));
        $previous = $summary['season_history'][0];
        self::assertSame('arsenal', $previous['club']['id']);
        self::assertSame('breakout', $previous['performance']['classification']);
        self::assertSame(1, $previous['appearances']);
        self::assertSame('breakout', $summary['latest_season_performance']['classification']);
        self::assertArrayNotHasKey('assists', $previous['performance']['statistics']);
        self::assertNotEmpty($summary['development_history']);
        self::assertContains('request_transfer', array_column($summary['available_actions'], 'type'));

        unset($database);
        $database = $store->openDatabase('domain-025-production');
        self::assertSame($summary, $query->summary($database, $player->id(), SimulationDate::fromIsoString('2025-08-01'), $next->id()));
    }

    public function testCareerHubHandlesFreeAgentAndPendingDecisionStates(): void
    {
        [$services, $database, $season] = $this->scenario('domain-025-state');
        $playerService = $services->playerModule()->service();
        $player = $playerService->create($this->request('hub-state-player', new PlayerAttributeSet(70, 70, 70, 70, 70, 70), 90));
        $membership = new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Regular);
        $playerService->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('hub-state-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), $membership);
        $contracts = $services->contractModule()->service();
        $contract = $contracts->create(new ContractCreationRequest(new ContractId('hub-state-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2025-05-31'), 100, SimulationDate::fromIsoString('2024-07-31')));
        $contracts->save($database, $contract);
        $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));

        $next = new Season(new SeasonId('season-2025-26'), '2025/26', SimulationDate::fromIsoString('2025-08-01'), SimulationDate::fromIsoString('2026-05-31'));
        $decision = $services->transferModule()->service()->careerMovement()->prepareContractDecision($database, $player->id(), $season, $next, SimulationDate::fromIsoString('2025-05-31'), $membership, true);
        self::assertNotNull($decision);
        $query = new PlayerCareerProgressionQuery($services->clubModule()->service());
        $pending = $query->summary($database, $player->id(), SimulationDate::fromIsoString('2025-06-01'), $season->id());
        self::assertSame('active', $pending['current_contract']['status']);
        self::assertNotEmpty($pending['pending_decisions']);
        self::assertContains('resolve_opportunity', array_column($pending['available_actions'], 'type'));

        $services->clubModule()->service()->squadRepository($database)->remove($membership);
        $contracts->save($database, $contract->terminate());
        $services->competitionModule()->service()->registrationRepository($database)->unregisterByPlayerClubSeason($player->id(), new ClubId('arsenal'), $season->id());
        $free = $query->summary($database, $player->id(), SimulationDate::fromIsoString('2025-08-01'), $season->id());
        self::assertNull($free['current_club']);
        self::assertNull($free['current_competition']);
        self::assertNull($free['current_role']);
        self::assertNull($free['current_contract']);
        self::assertSame([], $free['season_history']);
        self::assertSame('terminated', $free['contract_history'][0]['status']);
    }

    public function testCareerHubShowsCanonicalCompletedTransferWithoutDuplicatingHistory(): void
    {
        [$services, $database, $season] = $this->scenario('domain-025-transfer');
        $playerService = $services->playerModule()->service();
        $player = $playerService->create($this->request('hub-transfer-player', new PlayerAttributeSet(75, 75, 75, 75, 75, 75), 90));
        $playerService->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('hub-transfer-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Regular));
        $contracts = $services->contractModule()->service();
        $contracts->save($database, $contracts->create(new ContractCreationRequest(new ContractId('hub-transfer-source-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2026-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));
        $transfer = new Transfer(new TransferId('hub-transfer'), $player->id(), new ClubId('arsenal'), new ClubId('chelsea'), $season->id(), 0, SimulationDate::fromIsoString('2024-08-15'));
        $services->transferModule()->service()->execute($database, $transfer, new TransferExecutionTerms(new ContractId('hub-transfer-destination-contract'), SimulationDate::fromIsoString('2026-06-30'), 100, SquadRole::Regular));

        $summary = (new PlayerCareerProgressionQuery($services->clubModule()->service()))->summary($database, $player->id(), SimulationDate::fromIsoString('2024-09-01'), $season->id());
        self::assertSame('chelsea', $summary['current_club']['id']);
        self::assertSame('chelsea', $summary['current_contract']['club']['id']);
        self::assertCount(1, $summary['movement_history']);
        self::assertSame('transfer', $summary['movement_history'][0]['type']);
        self::assertSame('Arsenal', $summary['movement_history'][0]['from_club']);
        self::assertSame('Chelsea', $summary['movement_history'][0]['to_club']);
        self::assertCount(2, $summary['contract_history']);
        self::assertCount(1, $summary['season_history']);
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season,3:SqliteSaveStore} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2026025, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
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
        return new PlayerCreationRequest($id, 'Career Hub', 'Player', $id, '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', $potential, 'regular', 25025, $attributes);
    }
}
