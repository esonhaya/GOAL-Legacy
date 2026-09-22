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
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\Transfer\Domain\LoanStatus;
use Goal\Legacy\Modules\Transfer\Persistence\LoanRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class Domain036LoanTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            foreach (glob($root . '/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
            if (is_dir($root)) { rmdir($root); }
        }
    }

    public function testControlledLoanPreservesParentContractAndUsesOneActiveMembership(): void
    {
        [$services, $database, $season, $player] = $this->scenario('domain-036-loan');
        $movement = $services->transferModule()->service()->careerMovement();
        $date = SimulationDate::fromIsoString('2024-08-01');
        $opportunity = $movement->prepareControlledLoanDecision($database, $player->id(), $season, $date);
        self::assertNotNull($opportunity);
        $option = array_values(array_filter($opportunity->context()['options'], static fn (array $row): bool => ($row['kind'] ?? null) === 'accept_loan'))[0] ?? null;
        self::assertIsArray($option);
        self::assertSame('arsenal', $option['parent_club_id']);
        self::assertSame($season->endDate()->toIsoString(), $option['loan_end_date']);

        $parentContract = $services->contractModule()->service()->repository($database)->activeForPlayer($player->id());
        self::assertNotNull($parentContract);
        $resolved = $movement->resolveLoanDecision($database, $opportunity->id(), $option['id'], $date);
        self::assertSame('accepted', $resolved->context()['offer_status']);
        $loan = (new LoanRepository($database))->get($option['loan_id']);
        self::assertNotNull($loan);
        self::assertSame(LoanStatus::Active, $loan->status());
        self::assertSame('arsenal', $parentContract->clubId()->value());
        self::assertSame($parentContract->id()->value(), $services->contractModule()->service()->repository($database)->activeForPlayer($player->id())->id()->value());

        $memberships = $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $season->id());
        self::assertCount(1, $memberships);
        self::assertSame($option['club_id'], $memberships[0]->clubId()->value());
        $summary = (new PlayerCareerProgressionQuery($services->clubModule()->service()))->summary($database, $player->id(), SimulationDate::fromIsoString('2024-09-01'), $season->id());
        self::assertSame($option['club_id'], $summary['current_club']['id']);
        self::assertSame('arsenal', $summary['current_contract']['club']['id']);
        self::assertSame($option['club_id'], $services->playerModule()->service()->socialService()->context($database, $player->id())['current_club_id']);
        self::assertNotEmpty($services->competitionModule()->service()->registrationRepository($database)->byPlayer($player->id()));

        $replayed = $movement->resolveLoanDecision($database, $opportunity->id(), $option['id'], $date);
        self::assertSame($resolved->id(), $replayed->id());
    }

    public function testLoanReturnIsIdempotentAndReadPresentationDoesNotWrite(): void
    {
        [$services, $database, $season, $player] = $this->scenario('domain-036-return');
        $movement = $services->transferModule()->service()->careerMovement();
        $date = SimulationDate::fromIsoString('2024-08-01');
        $opportunity = $movement->prepareControlledLoanDecision($database, $player->id(), $season, $date);
        self::assertNotNull($opportunity);
        $option = array_values(array_filter($opportunity->context()['options'], static fn (array $row): bool => ($row['kind'] ?? null) === 'accept_loan'))[0];
        $movement->resolveLoanDecision($database, $opportunity->id(), $option['id'], $date);

        $before = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();
        $summary = (new PlayerCareerProgressionQuery($services->clubModule()->service()))->summary($database, $player->id(), SimulationDate::fromIsoString('2024-09-01'), $season->id());
        (new CareerPresentationService($services))->decision($summary, $database);
        $after = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();
        self::assertSame($before, $after);

        $loan = (new LoanRepository($database))->get($option['loan_id']);
        self::assertNotNull($loan);
        self::assertSame(1, $services->transferModule()->service()->returnDueLoans($database, $season->endDate()));
        $returned = (new LoanRepository($database))->get($loan->id());
        self::assertNotNull($returned);
        self::assertSame(LoanStatus::Completed, $returned->status());
        self::assertSame('arsenal', $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $season->id())[0]->clubId()->value());
        self::assertSame('arsenal', $services->contractModule()->service()->repository($database)->activeForPlayer($player->id())->clubId()->value());
        $again = $services->transferModule()->service()->returnLoan($database, $returned, $season->endDate());
        self::assertSame($returned->id(), $again->id());
        self::assertCount(1, $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $season->id()));
    }

    /** @return array{0:object,1:object,2:Season,3:object} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 36036, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);
        $services->playerModule()->service()->populationService()->populate($database, $season, 36036);
        $existing = $services->clubModule()->service()->squadRepository($database)->byClub(new ClubId('cardiff-city'), $season->id())[0];
        $services->clubModule()->service()->squadRepository($database)->remove($existing);
        $player = $services->playerModule()->service()->create(new PlayerCreationRequest($id . '-player', 'Career', 'Loan', 'Career Loan', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 92, 'prodigy', 36036, new PlayerAttributeSet(72, 72, 72, 72, 72, 72)));
        $services->playerModule()->service()->initializeCareer($database, $player, new CareerPlayerReference(new CareerId($id . '-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Prospect));
        $contracts = $services->contractModule()->service();
        $contracts->save($database, $contracts->create(new ContractCreationRequest(new ContractId($id . '-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2026-05-31'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));

        return [$services, $database, $season, $player];
    }
}
