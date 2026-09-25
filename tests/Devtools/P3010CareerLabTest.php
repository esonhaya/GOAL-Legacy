<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Devtools;

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Simulation\SimulationMutation;
use Goal\Legacy\Core\Simulation\SimulationPermission;
use Goal\Legacy\Devtools\Simulation\GoalScenarioBuilder;
use Goal\Legacy\Devtools\Simulation\GoalSimulationAdapter;
use Goal\Legacy\Modules\Contract\Persistence\ContractRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Transfer\Persistence\LoanRepository;
use Goal\Legacy\Modules\Transfer\Persistence\TransferRepository;
use Goal\Legacy\Tests\Support\SimulationAssertions;
use PHPUnit\Framework\TestCase;

final class P3010CareerLabTest extends TestCase
{
    use SimulationAssertions;

    public function testContractAndMovementScenariosUseCanonicalOwners(): void
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $builder = new GoalScenarioBuilder($services);

        $expiring = $builder->build('CONTRACT_EXPIRING', 3010);
        try {
            $contract = (new ContractRepository($expiring->database()))->activeForPlayer($expiring->playerId());
            self::assertSame('2024-12-31', $contract?->endDate()->toIsoString());
            $this->assertContractClub($expiring->database(), $expiring->playerId()->value(), 'arsenal');
            $this->assertActiveClub($expiring->database(), $expiring->playerId()->value(), $expiring->seasonId()->value(), 'arsenal');
        } finally {
            $expiring->close();
        }

        $free = $builder->build('FREE_AGENT', 3011);
        try {
            $this->assertFreeAgent($free->database(), $free->playerId()->value());
        } finally {
            $free->close();
        }

        $requested = $builder->build('TRANSFER_REQUESTED', 3012);
        try {
            $reference = (new CareerPlayerRepository($requested->database()))->byPlayer($requested->playerId());
            self::assertSame('requested', $reference?->transferRequestStatus()->value);
        } finally {
            $requested->close();
        }

        $loan = $builder->build('LOAN_ACTIVE', 3013);
        try {
            $activeLoan = (new LoanRepository($loan->database()))->activeForPlayer($loan->playerId());
            self::assertNotNull($activeLoan);
            $this->assertContractClub($loan->database(), $loan->playerId()->value(), 'arsenal');
            $this->assertLoanActive($loan->database(), $loan->playerId()->value(), $activeLoan->loanClubId()->value());
            try {
                (new GoalSimulationAdapter($services, dirname(__DIR__, 2), $loan->store()))->mutate(new SimulationMutation('goal.contract.set_term', $loan->saveId(), $loan->playerId()->value(), ['end_date' => '2024-08-02'], 'system-test', SimulationPermission::SYSTEM_TEST));
                self::fail('An active loan must prevent a parent Contract from ending early.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('outlive', $exception->getMessage());
            }
        } finally {
            $loan->close();
        }

        $returned = $builder->build('POST_LOAN_RETURN', 3014);
        try {
            self::assertNull((new LoanRepository($returned->database()))->activeForPlayer($returned->playerId()));
            $this->assertActiveClub($returned->database(), $returned->playerId()->value(), $returned->seasonId()->value(), 'arsenal');
            $this->assertContractClub($returned->database(), $returned->playerId()->value(), 'arsenal');
        } finally {
            $returned->close();
        }
    }

    public function testContractMutationDiffAndSnapshotRestoreAreSaveScoped(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        $fixture = (new GoalScenarioBuilder($services))->build('CONTRACT_LONG_TERM', 3015);
        try {
            $adapter = new GoalSimulationAdapter($services, $root, $fixture->store());
            $before = $adapter->inspect($fixture->saveId());
            $changed = $adapter->mutate(new SimulationMutation('goal.contract.set_wage', $fixture->saveId(), $fixture->playerId()->value(), ['wage' => 2400], 'system-test', SimulationPermission::SYSTEM_TEST));
            self::assertSame('PASS', $changed->status());
            $after = $adapter->inspect($fixture->saveId());
            self::assertSame(2400, $after['career']['current_contract']['wage']);
            self::assertNotEmpty($adapter->stateDiff($before, $after));
            $adapter->mutate(new SimulationMutation('sandbox.restore_snapshot', $fixture->saveId(), $fixture->saveId(), [], 'system-test', SimulationPermission::SYSTEM_TEST));
            self::assertSame($before['career']['current_contract'], $adapter->inspect($fixture->saveId())['career']['current_contract']);
            self::assertNotEmpty($adapter->audit($fixture->saveId()));
        } finally {
            $fixture->close();
        }
    }

    public function testPremiumMutationRequiresOwnedSandboxAndMovementMutationUsesCanonicalTransfer(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        $fixture = (new GoalScenarioBuilder($services))->build('HEALTHY_LOW_MINUTES', 3016);
        try {
            $fixture->store()->update($fixture->store()->open($fixture->saveId())->withOwner('premium-1'));
            $adapter = new GoalSimulationAdapter($services, $root, $fixture->store());
            $this->expectExceptionMessage('SANDBOX SAVE');
            $adapter->mutate(new SimulationMutation('goal.contract.set_wage', $fixture->saveId(), $fixture->playerId()->value(), ['wage' => 2200], 'premium-1', SimulationPermission::PREMIUM_SANDBOX));
        } finally {
            $fixture->close();
        }

        $fixture = (new GoalScenarioBuilder($services))->build('HEALTHY_LOW_MINUTES', 3017);
        try {
            $fixture->store()->update($fixture->store()->open($fixture->saveId())->withOwner('developer-1')->asSandbox('source-save'));
            $adapter = new GoalSimulationAdapter($services, $root, $fixture->store());
            $adapter->mutate(new SimulationMutation('goal.movement.permanent_transfer', $fixture->saveId(), $fixture->playerId()->value(), ['club' => 'chelsea', 'wage' => 1800, 'end_date' => '2025-06-30'], 'developer-1', SimulationPermission::DEVELOPER));
            self::assertSame('chelsea', (new ContractRepository($fixture->database()))->activeForPlayer($fixture->playerId())?->clubId()->value());
            self::assertCount(1, (new TransferRepository($fixture->database()))->byPlayer($fixture->playerId()));
        } finally {
            $fixture->close();
        }
    }
}
