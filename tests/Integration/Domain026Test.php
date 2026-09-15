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
use Goal\Legacy\Modules\Player\CareerOutlookService;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use Goal\Legacy\Modules\World\Persistence\SeasonRepository;
use PHPUnit\Framework\TestCase;

final class Domain026Test extends TestCase
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

    public function testOutlookUsesDeterministicPrecedenceAndConservativeOpportunityGuidance(): void
    {
        $service = new CareerOutlookService();
        $date = SimulationDate::fromIsoString('2025-01-01');

        $breaking = $service->derive($this->snapshot('rotation', 'breakout', 0.80, 0.60, 0, [], [['role' => 'prospect']], [['type' => 'request_transfer']]), $date);
        self::assertSame('breaking_through', $breaking['category']);
        self::assertSame('moderate', $breaking['opportunity_level']);

        $key = $service->derive($this->snapshot('key_player', 'steady', 0.60, 0.45, 0), $date);
        self::assertSame('good_situation', $key['category']);
        self::assertSame('high', $key['opportunity_level']);

        $youngProspect = $service->derive($this->snapshot('prospect', 'steady', 0.15, 0.05, 3), $date);
        self::assertSame('competing_for_role', $youngProspect['category']);

        $blocked = $service->derive($this->snapshot('prospect', 'limited', 0.0, 0.0, 3, [], [], [['type' => 'request_transfer']]), $date);
        self::assertSame('blocked_path', $blocked['category']);
        self::assertSame('request_transfer', $blocked['guidance'][0]['action_type']);

        $limitedWithOpportunity = $service->derive($this->snapshot('rotation', 'limited', 0.20, 0.10, 3), $date);
        self::assertNotSame('blocked_path', $limitedWithOpportunity['category']);

        $pendingContract = $this->snapshot('key_player', 'breakout', 0.80, 0.60, 0, [['type' => 'contract_renewal']]);
        self::assertSame('contract_uncertainty', $service->derive($pendingContract, $date)['category']);

        $pendingTransfer = $this->snapshot('regular', 'steady', 0.40, 0.30, 0, [['type' => 'transfer_interest']]);
        self::assertSame('transfer_opportunity', $service->derive($pendingTransfer, $date)['category']);

        $freeAgent = $this->snapshot(null, 'insufficient_evidence', 0.0, 0.0, 0, [], [], [], false);
        $freeAgent['current_club'] = null;
        $freeAgent['current_contract'] = null;
        self::assertSame('free_agent', $service->derive($freeAgent, $date)['category']);
    }

    public function testRealMatchPerformanceFlowsThroughHubOutlookAndReloadWithoutMutation(): void
    {
        [$services, $database, $season, $store] = $this->scenario('domain-026-production');
        $playerService = $services->playerModule()->service();
        $player = $playerService->create($this->request('outlook-production-player'));
        $playerService->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('outlook-production-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Prospect));
        $contracts = $services->contractModule()->service();
        $contracts->save($database, $contracts->create(new ContractCreationRequest(new ContractId('outlook-production-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2026-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));

        $matches = array_values(array_filter($services->matchModule()->service()->generateFixtures($database, 'premier-league', $season->id()), static fn ($match): bool => $match->homeClubId()->value() === 'arsenal' || $match->awayClubId()->value() === 'arsenal'));
        self::assertNotEmpty($matches);
        $matchService = $services->matchModule()->service();
        $services->worldModule()->service()->advanceToDate($database, 'domain-026-production', $matches[0]->scheduledDate());
        $completed = $matchService->simulateDue($database, $matches[0]->scheduledDate());
        self::assertNotEmpty(array_filter($completed, fn ($match): bool => $matchService->playerSummary($database, $match->id(), $player->id()) !== null));

        $completedSeason = (new SeasonRepository($database))->get($season->id())->complete();
        (new SeasonRepository($database))->save($completedSeason);
        $rollover = $services->worldModule()->service()->seasonRollover();
        self::assertNotNull($rollover);
        $world = (new \Goal\Legacy\Modules\World\Persistence\WorldRepository($database))->get('domain-026-production');
        $rollover->prepareNext($database, $world, $completedSeason, SimulationDate::fromIsoString('2025-06-01'));
        $next = $rollover->nextSeason($completedSeason);
        $rollover->materializeNext($database, $world, $completedSeason, $next, SimulationDate::fromIsoString('2025-06-01'));
        $nextStored = (new SeasonRepository($database))->get($next->id())->activate();
        (new SeasonRepository($database))->save($nextStored);
        $rollover->activateNext($database, $nextStored);

        $query = new PlayerCareerProgressionQuery($services->clubModule()->service());
        $beforeContract = $services->contractModule()->service()->repository($database)->activeForPlayer($player->id())?->toArray();
        $beforeMembership = $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $next->id())[0]->toArray();
        $summary = $query->summary($database, $player->id(), SimulationDate::fromIsoString('2025-08-01'), $next->id());
        self::assertSame('breaking_through', $summary['career_outlook']['category']);
        self::assertSame('breakout', $summary['career_outlook']['evidence']['performance']);
        self::assertSame('request_transfer', $summary['available_actions'][0]['type']);
        self::assertSame('none', $summary['transfer_request']['status']);
        self::assertSame($beforeContract, $services->contractModule()->service()->repository($database)->activeForPlayer($player->id())?->toArray());
        self::assertSame($beforeMembership, $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $next->id())[0]->toArray());

        unset($database);
        $database = $store->openDatabase('domain-026-production');
        $reloaded = $query->summary($database, $player->id(), SimulationDate::fromIsoString('2025-08-01'), $next->id());
        self::assertSame($summary['career_outlook'], $reloaded['career_outlook']);
        self::assertSame($summary['position_competition'], $reloaded['position_competition']);
    }

    /** @param array<string, mixed> $position @param list<array<string, mixed>> $pending @param list<array<string, mixed>> $roleHistory @param list<array<string, mixed>> $actions @return array<string, mixed> */
    private function snapshot(?string $role, string $classification, float $minutesShare, float $startsShare, int $higherOvrCount, array $pending = [], array $roleHistory = [], array $actions = [], bool $contracted = true): array
    {
        return [
            'current_club' => $contracted ? ['id' => 'arsenal'] : null,
            'current_contract' => $contracted ? ['status' => 'active', 'end_date' => '2027-06-30'] : null,
            'current_role' => $role,
            'squad_role' => $role,
            'position_competition' => ['same_position_count' => max(0, $higherOvrCount), 'higher_ovr_count' => $higherOvrCount],
            'latest_season_performance' => ['classification' => $classification, 'statistics' => ['minutes_share' => $minutesShare, 'start_share' => $startsShare]],
            'recent_form' => ['appearances' => 0],
            'pending_decisions' => $pending,
            'role_history' => $roleHistory,
            'transfer_request' => ['status' => 'none'],
            'available_actions' => $actions,
        ];
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season,3:SqliteSaveStore} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2026026, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $season, $store];
    }

    private function request(string $id): PlayerCreationRequest
    {
        return new PlayerCreationRequest($id, 'Career Outlook', 'Player', $id, '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 95, 'regular', 26026, new PlayerAttributeSet(72, 72, 72, 72, 72, 72));
    }
}
