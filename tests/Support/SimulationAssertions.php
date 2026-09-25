<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Support;

use Goal\Legacy\Core\Simulation\SimulationCheckpoint;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Persistence\ClubSquadRepository;
use Goal\Legacy\Modules\Contract\Persistence\ContractRepository;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Transfer\Domain\LoanStatus;
use Goal\Legacy\Modules\Transfer\Persistence\LoanRepository;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use PHPUnit\Framework\TestCase;

/** Small semantic assertions for Simulation Lab tests; gameplay stays canonical. */
trait SimulationAssertions
{
    private function assertCheckpointState(SimulationCheckpoint $checkpoint, string $key, mixed $expected): void
    {
        TestCase::assertArrayHasKey($key, $checkpoint->state(), sprintf('Checkpoint %s is missing state key %s.', $checkpoint->period(), $key));
        TestCase::assertSame($expected, $checkpoint->state()[$key], sprintf('Checkpoint %s state key %s differs.', $checkpoint->period(), $key));
    }

    /** @param array<string,mixed> $state */
    private function assertMatchStatus(array $state, string $expected): void
    {
        TestCase::assertSame($expected, $state['status'] ?? null, 'Canonical Match status differs.');
    }

    /** @param array<string,mixed> $state */
    private function assertMatchPlayer(array $state, string $playerId): void
    {
        TestCase::assertSame($playerId, $state['player_id'] ?? null, 'Canonical controlled Player identity differs.');
        TestCase::assertArrayHasKey('selection', $state, 'Canonical Match checkpoint has no selection projection.');
        TestCase::assertArrayHasKey('availability', $state, 'Canonical Match checkpoint has no availability projection.');
    }

    private function assertContractClub(DatabaseInterface $database, string $playerId, string $clubId): void
    {
        TestCase::assertSame($clubId, (new ContractRepository($database))->activeForPlayer($playerId)?->clubId()->value(), 'Active Contract Club differs from the expected Club.');
    }

    private function assertActiveClub(DatabaseInterface $database, string $playerId, string $seasonId, string $clubId): void
    {
        $memberships = (new ClubSquadRepository($database))->byPlayer($playerId, new SeasonId($seasonId));
        TestCase::assertCount(1, $memberships, 'Controlled Player must have exactly one active squad membership.');
        TestCase::assertSame($clubId, $memberships[0]->clubId()->value(), 'Active squad Club differs from the expected Club.');
    }

    private function assertLoanActive(DatabaseInterface $database, string $playerId, string $loanClubId): void
    {
        $loan = (new LoanRepository($database))->activeForPlayer(new PlayerId($playerId));
        TestCase::assertNotNull($loan, 'Controlled Player should have an active loan.');
        TestCase::assertSame(LoanStatus::Active, $loan->status());
        TestCase::assertSame($loanClubId, $loan->loanClubId()->value(), 'Active loan destination differs from the expected Club.');
    }

    private function assertFreeAgent(DatabaseInterface $database, string $playerId): void
    {
        TestCase::assertNull((new ContractRepository($database))->activeForPlayer($playerId), 'Free-agent scenario unexpectedly has an active Contract.');
        TestCase::assertCount(0, (new ClubSquadRepository($database))->byPlayer($playerId), 'Free-agent scenario unexpectedly has a squad membership.');
    }
}
