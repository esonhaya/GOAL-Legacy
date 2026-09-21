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
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerOpportunityStatus;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Persistence\CareerOpportunityRepository;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\Transfer\Domain\TransferException;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class Domain035Test extends TestCase
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

    public function testContractDecisionExposesStableTermsAndAllowsOnlyOneBoundedCounter(): void
    {
        [$services, $database, $season, $player] = $this->scenario('domain-035-counter');
        $world = $services->worldModule()->service();
        $world->advanceToDate($database, 'domain-035-counter', SimulationDate::fromIsoString('2025-06-01'));
        $next = $world->seasonRepository($database)->get('season-2025-26');
        $opportunity = (new CareerOpportunityRepository($database))->openForPlayer($player->id())[0] ?? null;
        self::assertNotNull($opportunity);
        $renewal = array_values(array_filter($opportunity->context()['options'], static fn (array $option): bool => ($option['kind'] ?? null) === 'renew_current_club'))[0] ?? null;
        self::assertIsArray($renewal);
        self::assertGreaterThanOrEqual(1, $renewal['term_seasons']);
        self::assertLessThanOrEqual(5, $renewal['term_seasons']);
        self::assertSame(100, $renewal['current_wage']);
        self::assertSame('regular', $renewal['role']);
        self::assertSame($next->endDate()->addDays(365 * $renewal['term_seasons'])->toIsoString(), $renewal['contract_end_date']);

        $before = (int) $renewal['wage'];
        $countered = $services->transferModule()->service()->careerMovement()->counterContractDecision($database, $opportunity->id(), $renewal['id'], SimulationDate::fromIsoString('2025-06-01'));
        self::assertSame(CareerOpportunityStatus::Open, $countered->status());
        self::assertTrue($countered->context()['counter_used']);
        self::assertSame('accepted', $countered->context()['counter_response']);
        $revised = array_values(array_filter($countered->context()['options'], static fn (array $option): bool => ($option['id'] ?? null) === $renewal['id']))[0];
        self::assertSame($before, $revised['original_wage']);
        self::assertGreaterThan($before, $revised['wage']);

        $reloaded = (new CareerOpportunityRepository($database))->get($opportunity->id());
        self::assertNotNull($reloaded);
        self::assertSame($countered->context(), $reloaded->context());
        $this->expectException(TransferException::class);
        $services->transferModule()->service()->careerMovement()->counterContractDecision($database, $opportunity->id(), $renewal['id'], SimulationDate::fromIsoString('2025-06-01'));
    }

    public function testAcceptedCounterUsesCanonicalContractAndFinanceWageFlow(): void
    {
        [$services, $database, $season, $player] = $this->scenario('domain-035-accept');
        $world = $services->worldModule()->service();
        $world->advanceToDate($database, 'domain-035-accept', SimulationDate::fromIsoString('2025-06-01'));
        $opportunity = (new CareerOpportunityRepository($database))->openForPlayer($player->id())[0];
        $renewal = array_values(array_filter($opportunity->context()['options'], static fn (array $option): bool => ($option['kind'] ?? null) === 'renew_current_club'))[0];
        $countered = $services->transferModule()->service()->careerMovement()->counterContractDecision($database, $opportunity->id(), $renewal['id'], SimulationDate::fromIsoString('2025-06-01'));
        $revised = array_values(array_filter($countered->context()['options'], static fn (array $option): bool => ($option['id'] ?? null) === $renewal['id']))[0];
        $resolved = $services->transferModule()->service()->careerMovement()->resolveContractDecision($database, $opportunity->id(), $renewal['id'], SimulationDate::fromIsoString('2025-06-01'));
        self::assertSame(CareerOpportunityStatus::Resolved, $resolved->status());
        $world->advanceToDate($database, 'domain-035-accept', SimulationDate::fromIsoString('2025-08-01'));
        $contract = $services->contractModule()->service()->repository($database)->activeForPlayer($player->id());
        self::assertNotNull($contract);
        self::assertSame($revised['wage'], $contract->wage());
        self::assertSame($revised['contract_end_date'], $contract->endDate()->toIsoString());
        self::assertSame($revised['wage'], $services->playerFinanceService()->summary($database, $player->id()->value(), SimulationDate::fromIsoString('2025-08-01'))['current_wage']);

        $query = new PlayerCareerProgressionQuery($services->clubModule()->service());
        self::assertSame($revised['wage'], $query->summary($database, $player->id(), SimulationDate::fromIsoString('2025-08-01'), new SeasonId('season-2025-26'))['current_contract']['wage']);
    }

    public function testDecisionPresentationIsReadOnlyAndKeepsCounterTermsAfterReload(): void
    {
        [$services, $database, $season, $player] = $this->scenario('domain-035-read');
        $world = $services->worldModule()->service();
        $world->advanceToDate($database, 'domain-035-read', SimulationDate::fromIsoString('2025-06-01'));
        $opportunity = (new CareerOpportunityRepository($database))->openForPlayer($player->id())[0];
        $query = new PlayerCareerProgressionQuery($services->clubModule()->service());
        $summary = $query->summary($database, $player->id(), SimulationDate::fromIsoString('2025-06-01'), new SeasonId('season-2024-25'));
        $before = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();
        $presentation = (new CareerPresentationService($services))->decision($summary, $database);
        $after = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();
        self::assertSame($before, $after);
        self::assertNotNull($presentation);
        $offer = array_values(array_filter($presentation['options'], static fn (array $option): bool => ($option['id'] ?? null) === 'renew-current-club'))[0];
        self::assertNotNull($offer['term_seasons']);
        self::assertTrue($offer['counter_available']);
        self::assertSame($opportunity->id(), $presentation['id']);
    }

    /** @return array{0:object,1:object,2:Season,3:object} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 35035, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);
        $services->playerModule()->service()->populationService()->populate($database, $season, 35035);
        $existingSquad = $services->clubModule()->service()->squadRepository($database)->byClub(new ClubId('arsenal'), $season->id())[0];
        $services->clubModule()->service()->squadRepository($database)->remove($existingSquad);
        $player = $services->playerModule()->service()->create(new PlayerCreationRequest('domain-035-player', 'Career', 'Terms', 'Career Terms', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 92, 'regular', 35035, new PlayerAttributeSet(72, 72, 72, 72, 72, 72)));
        $services->playerModule()->service()->initializeCareer($database, $player, new CareerPlayerReference(new CareerId($id . '-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Regular));
        $contracts = $services->contractModule()->service();
        $contracts->save($database, $contracts->create(new ContractCreationRequest(new ContractId($id . '-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2025-05-31'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));

        return [$services, $database, $season, $player];
    }
}
