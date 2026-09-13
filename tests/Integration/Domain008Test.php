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
use Goal\Legacy\Modules\Match\Domain\SelectionStatus;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
use Goal\Legacy\Modules\Player\CareerOpportunityService;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunityStatus;
use Goal\Legacy\Modules\Player\Persistence\CareerEvaluationRepository;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\Player\PlayerFormService;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class Domain008Test extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) { foreach (glob($root . '/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } } if (is_dir($root)) { rmdir($root); } }
    }

    public function testSelectionIsDeterministicRoleAwareAndSupportsIncompleteRosters(): void
    {
        [$services, $database, $season] = $this->scenario('domain-008-selection');
        $playerService = $services->playerModule()->service(); $registration = $services->competitionModule()->service()->registrationRepository($database); $contract = $services->contractModule()->service();
        $keyPlayerId = null;
        for ($index = 1; $index <= 20; ++$index) {
            $id = 'selection-player-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT); $player = $playerService->create($this->request($id, new PlayerAttributeSet(50, 50, 50, 50, 50, 50)));
            $playerService->repository($database)->save($player); $role = $index === 20 ? SquadRole::KeyPlayer : SquadRole::Prospect; if ($index === 20) { $keyPlayerId = $player->id(); }
            $services->clubModule()->service()->squadRepository($database)->save(new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), $role));
            $contract->save($database, $contract->create(new ContractCreationRequest(new ContractId('selection-contract-' . $index), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2025-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
            $registration->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));
        }
        $matches = $services->matchModule()->service()->generateFixtures($database, 'premier-league', $season->id()); $match = array_values(array_filter($matches, static fn ($value): bool => $value->homeClubId()->value() === 'arsenal' || $value->awayClubId()->value() === 'arsenal'))[0]; $services->matchModule()->service()->simulate($database, $match->id());
        $selections = (new MatchSelectionRepository($database))->byMatch($match->id()); $arsenal = array_values(array_filter($selections, static fn ($selection): bool => $selection->clubId()->value() === 'arsenal'));
        self::assertCount(20, $arsenal); self::assertCount(11, array_filter($arsenal, static fn ($selection): bool => $selection->status() === SelectionStatus::Starter)); self::assertCount(7, array_filter($arsenal, static fn ($selection): bool => $selection->status() === SelectionStatus::Bench)); self::assertCount(2, array_filter($arsenal, static fn ($selection): bool => $selection->status() === SelectionStatus::NotSelected));
        $keySelection = array_values(array_filter($arsenal, static fn ($selection): bool => $selection->playerId()->value() === $keyPlayerId->value()))[0]; self::assertSame(SelectionStatus::Starter, $keySelection->status());
        $before = array_map(static fn ($selection): array => $selection->toArray(), $selections); $services->matchModule()->service()->selectionRepository($database)->byMatch($match->id()); self::assertSame($before, array_map(static fn ($selection): array => $selection->toArray(), (new MatchSelectionRepository($database))->byMatch($match->id())));
    }

    public function testTwoMatchesProduceEvaluationRolePromotionOpportunityAndCareerSummary(): void
    {
        [$services, $database, $season] = $this->scenario('domain-008-career'); $playerService = $services->playerModule()->service();
        $player = $playerService->create($this->request('pressure-player', new PlayerAttributeSet(65, 65, 65, 65, 65, 65))); $playerService->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('pressure-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Prospect));
        $contract = $services->contractModule()->service(); $contract->save($database, $contract->create(new ContractCreationRequest(new ContractId('pressure-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2025-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));
        $matches = array_values(array_filter($services->matchModule()->service()->generateFixtures($database, 'premier-league', $season->id()), static fn ($match): bool => $match->homeClubId()->value() === 'arsenal' || $match->awayClubId()->value() === 'arsenal'));
        foreach (array_slice($matches, 0, 2) as $match) { $services->worldModule()->service()->advanceToDate($database, 'domain-008-career', $match->scheduledDate()); $services->matchModule()->service()->simulateDue($database, $match->scheduledDate()); }
        $evaluations = (new CareerEvaluationRepository($database))->byPlayer($player->id(), new ClubId('arsenal')); self::assertCount(2, $evaluations); self::assertNotSame('not_played', $evaluations[0]['expectation_status']);
        $membership = $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $season->id())[0]; self::assertSame(SquadRole::Rotation, $membership->role());
        $opportunities = (new CareerOpportunityService())->openForPlayer($database, $player->id()); self::assertCount(1, $opportunities); self::assertSame('role_increase', $opportunities[0]->type()->value); self::assertSame(CareerOpportunityStatus::Open, $opportunities[0]->status());
        $form = (new PlayerFormService())->recent($database, $player->id()); self::assertSame(2, $form['appearances']); self::assertGreaterThan(0, $form['average_score']);
        $summary = (new PlayerCareerProgressionQuery($services->clubModule()->service()))->summary($database, $player->id(), $matches[1]->scheduledDate(), $season->id()); self::assertSame('rotation', $summary['squad_role']); self::assertCount(1, $summary['open_opportunities']); self::assertCount(2, $summary['recent_selection']);
    }

    public function testOpportunityCanBeAcknowledgedWithoutExecutingTransfer(): void
    {
        [$services, $database, $season] = $this->scenario('domain-008-opportunity'); $playerService = $services->playerModule()->service(); $player = $playerService->create($this->request('opportunity-player', new PlayerAttributeSet(60, 60, 60, 60, 60, 60))); $playerService->repository($database)->save($player);
        $opportunity = new \Goal\Legacy\Modules\Player\Domain\CareerOpportunity('opportunity-1', $player->id(), \Goal\Legacy\Modules\Player\Domain\CareerOpportunityType::PlayingTime, new ClubId('arsenal'), null, SimulationDate::fromIsoString('2024-08-01'), null, CareerOpportunityStatus::Open, ['reason' => 'limited_playing_time'], 'test-opportunity-1');
        $service = new CareerOpportunityService(); $database->transaction(function () use ($database, $service, $opportunity): void { $service->createInTransaction($database, $opportunity); }); $accepted = $service->accept($database, 'opportunity-1'); self::assertSame(CareerOpportunityStatus::Accepted, $accepted->status()); self::assertTrue($services->clubModule()->service()->repository($database)->exists('arsenal')); self::assertSame([], $services->transferModule()->service()->repository($database)->byPlayer($player->id()));
    }

    /** @return array{0: \Goal\Legacy\Core\Bootstrap\CoreServices, 1: \Goal\Legacy\Core\Persistence\DatabaseInterface, 2: Season, 3: SqliteSaveStore} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']); $nations = $services->nationModule()->service()->loadSelected(); $competitions = $services->competitionModule()->service()->loadSelected(); $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31')); $calendar = $services->worldModule()->service()->calendar(); $world = new World(new WorldId($id), $id, 2026008, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds()); $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8)); mkdir($directory, 0775, true); $this->roots[] = $directory; $store = new SqliteSaveStore($directory, new JsonSerializer()); $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0'))); $database = $store->openDatabase($id); $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $season, $store];
    }

    private function request(string $id, PlayerAttributeSet $attributes): PlayerCreationRequest
    {
        return new PlayerCreationRequest($id, 'Pressure', 'Player', $id, '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 95, 'regular', 8008, $attributes);
    }
}
