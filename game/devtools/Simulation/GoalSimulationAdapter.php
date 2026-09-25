<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Simulation;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SaveStore;
use Goal\Legacy\Core\Simulation\GameSimulationAdapter;
use Goal\Legacy\Core\Simulation\SimulationCapability;
use Goal\Legacy\Core\Simulation\SimulationCheckpoint;
use Goal\Legacy\Core\Simulation\SimulationDiagnosticResult;
use Goal\Legacy\Core\Simulation\SimulationMutation;
use Goal\Legacy\Core\Simulation\SimulationMutationResult;
use Goal\Legacy\Core\Simulation\SimulationPermission;
use Goal\Legacy\Core\Simulation\SimulationResult;
use Goal\Legacy\Core\Simulation\SimulationScenario;
use Goal\Legacy\Core\Simulation\SimulationStateDiff;
use Goal\Legacy\Devtools\BufferedConsoleOutput;
use Goal\Legacy\Devtools\Commands\CareerMultiSeasonAuditCommand;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Club\Persistence\ClubSquadRepository;
use Goal\Legacy\Modules\Contract\Persistence\ContractRepository;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\Injury;
use Goal\Legacy\Modules\Player\Domain\InjuryCategory;
use Goal\Legacy\Modules\Player\Domain\InjurySeverity;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Finance\PlayerFinanceRepository;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\Player\Persistence\PlayerAvailabilityRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerDisciplineRepository;
use Goal\Legacy\Modules\Player\PlayerDisciplineService;
use Goal\Legacy\Modules\Transfer\Persistence\LoanRepository;
use Goal\Legacy\Modules\Transfer\Persistence\TransferRepository;
use Goal\Legacy\Modules\Transfer\Domain\Transfer;
use Goal\Legacy\Modules\Transfer\Domain\TransferExecutionTerms;
use Goal\Legacy\Modules\Transfer\Domain\TransferId;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\WorldId;
use Goal\Legacy\Modules\World\Persistence\WorldRepository;
use PDO;
use RuntimeException;

/** GOAL's one bridge into the game-neutral Haya simulation toolkit. */
final class GoalSimulationAdapter implements GameSimulationAdapter
{
    public function __construct(
        private readonly CoreServices $services,
        private readonly string $projectRoot,
        private readonly ?SaveStore $simulationStore = null,
    ) {
    }

    public function gameIdentifier(): string { return 'goal-legacy'; }

    public function scenarioCatalog(): GoalScenarioCatalog { return new GoalScenarioCatalog(); }

    /** @return list<array<string,mixed>> */
    public function scenarioDescriptors(): array { return $this->scenarioCatalog()->descriptors(); }

    /** @return list<SimulationCapability> */
    public function capabilities(): array
    {
        return [
            new SimulationCapability('state.inspect', 'Inspect save', 'Read a curated Career projection.', 'STATE', true, 'SAVE', SimulationPermission::PLAYER),
            new SimulationCapability('goal.player.inspect', 'Inspect Player', 'Read controlled Player, Club, Contract, availability, and Career state.', 'GOAL', true, 'PLAYER', SimulationPermission::PLAYER),
            new SimulationCapability('goal.diagnostics.playing_time', 'Playing-time diagnostic', 'Explain controlled-player selection and minutes outcomes.', 'DIAGNOSTICS', true, 'SAVE', SimulationPermission::DEVELOPER),
            new SimulationCapability('goal.diagnostics.squad_competition', 'Squad competition', 'Show deployable positions and relevant canonical squad context.', 'DIAGNOSTICS', true, 'SAVE', SimulationPermission::DEVELOPER),
            new SimulationCapability('goal.diagnostics.mobility', 'Mobility diagnostic', 'Inspect existing transfer, loan, and Contract opportunity state.', 'DIAGNOSTICS', true, 'SAVE', SimulationPermission::DEVELOPER),
            new SimulationCapability('goal.validation.integrity', 'Career integrity', 'Check controlled-player identity and active-state consistency.', 'DIAGNOSTICS', true, 'SAVE', SimulationPermission::DEVELOPER),
            new SimulationCapability('sandbox.audit', 'Sandbox audit', 'Read the bounded mutation audit for the selected save.', 'SANDBOX', true, 'SAVE', SimulationPermission::PREMIUM_SANDBOX),
            new SimulationCapability('goal.simulation.multi_period', 'Bounded Career simulation', 'Run the canonical observatory for a bounded number of Seasons.', 'SIMULATION', true, 'SCENARIO', SimulationPermission::DEVELOPER, ['horizon' => ['type' => 'integer', 'min' => 1, 'max' => 5], 'archetype' => ['type' => 'string']]),
            new SimulationCapability('goal.player.set_attribute', 'Set Player attribute', 'Change one controlled-player attribute inside canonical bounds.', 'MUTATION', false, 'PLAYER', SimulationPermission::PREMIUM_SANDBOX, ['name' => ['type' => 'string'], 'value' => ['type' => 'integer', 'min' => 0, 'max' => 99]]),
            new SimulationCapability('goal.player.set_potential', 'Set potential', 'Change potential without allowing OVR to exceed it.', 'MUTATION', false, 'PLAYER', SimulationPermission::PREMIUM_SANDBOX, ['value' => ['type' => 'integer', 'min' => 1, 'max' => 99]]),
            new SimulationCapability('goal.player.set_role', 'Set squad role', 'Use the canonical squad role repository for the current Season.', 'MUTATION', false, 'PLAYER', SimulationPermission::PREMIUM_SANDBOX, ['role' => ['type' => 'string']]),
            new SimulationCapability('goal.player.set_balance', 'Set finance balance', 'Set the controlled-player sandbox balance inside a safe bound.', 'MUTATION', false, 'PLAYER', SimulationPermission::PREMIUM_SANDBOX, ['balance' => ['type' => 'integer', 'min' => 0, 'max' => 100000000]]),
            new SimulationCapability('goal.player.apply_injury', 'Apply injury', 'Apply a bounded canonical sandbox injury.', 'MUTATION', false, 'PLAYER', SimulationPermission::PREMIUM_SANDBOX, ['severity' => ['type' => 'string', 'values' => ['minor', 'moderate', 'major']]]),
            new SimulationCapability('goal.player.clear_injury', 'Clear injury', 'Mark the current sandbox injury recovered.', 'MUTATION', false, 'PLAYER', SimulationPermission::PREMIUM_SANDBOX),
            new SimulationCapability('goal.player.apply_suspension', 'Apply suspension', 'Apply a bounded domestic disciplinary suspension.', 'MUTATION', false, 'PLAYER', SimulationPermission::PREMIUM_SANDBOX, ['matches' => ['type' => 'integer', 'min' => 1, 'max' => 5]]),
            new SimulationCapability('goal.player.clear_suspension', 'Clear suspension', 'Clear current sandbox disciplinary suspensions.', 'MUTATION', false, 'PLAYER', SimulationPermission::PREMIUM_SANDBOX),
            new SimulationCapability('goal.contract.set_wage', 'Set Contract wage', 'Change the active controlled-player Contract wage on a Sandbox save.', 'MUTATION', false, 'PLAYER', SimulationPermission::PREMIUM_SANDBOX, ['wage' => ['type' => 'integer', 'min' => 0, 'max' => 500000]]),
            new SimulationCapability('goal.contract.set_term', 'Set Contract term', 'Change the active controlled-player Contract end date on a Sandbox save.', 'MUTATION', false, 'PLAYER', SimulationPermission::PREMIUM_SANDBOX, ['end_date' => ['type' => 'date']]),
            new SimulationCapability('goal.movement.request_transfer', 'Request transfer', 'Create the canonical controlled-player transfer request for the current window.', 'MUTATION', false, 'PLAYER', SimulationPermission::PREMIUM_SANDBOX),
            new SimulationCapability('goal.movement.return_loan', 'Return loan', 'Return a due active loan through the canonical loan lifecycle.', 'MUTATION', false, 'PLAYER', SimulationPermission::PREMIUM_SANDBOX),
            new SimulationCapability('goal.movement.permanent_transfer', 'Permanent transfer', 'Run a validated permanent movement through TransferService.', 'MUTATION', false, 'PLAYER', SimulationPermission::PREMIUM_SANDBOX, ['club' => ['type' => 'string'], 'wage' => ['type' => 'integer', 'min' => 0, 'max' => 500000], 'end_date' => ['type' => 'date']]),
            new SimulationCapability('time.advance', 'Advance Career time', 'Advance a save by at most 31 days through WorldService.', 'SIMULATION', false, 'SAVE', SimulationPermission::PREMIUM_SANDBOX, ['days' => ['type' => 'integer', 'min' => 1, 'max' => 31]]),
            new SimulationCapability('sandbox.clone', 'Clone to Sandbox', 'Copy an owned Career to a new save without overwriting the source.', 'SANDBOX', false, 'SAVE', SimulationPermission::PREMIUM_SANDBOX, ['destination' => ['type' => 'string']]),
            new SimulationCapability('sandbox.restore_snapshot', 'Restore snapshot', 'Restore the bounded pre-mutation sandbox snapshot.', 'SANDBOX', false, 'SAVE', SimulationPermission::PREMIUM_SANDBOX),
        ];
    }

    /** @return array<string,mixed> */
    public function inspect(string $saveId): array
    {
        $database = $this->database($saveId);
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->load($database, $saveId);
        $seasonId = $world->currentSeasonId();
        if ($seasonId === null) {
            throw new RuntimeException('The save has no current Season.');
        }
        $career = (new CareerPlayerRepository($database))->get($saveId);
        $date = $world->currentDate($worldService->calendar());
        $summary = (new PlayerCareerProgressionQuery($this->services->clubModule()->service()))->summary($database, $career->playerId(), $date, $seasonId);
        $summary['discipline'] = (new PlayerDisciplineService())->context($database, $career->playerId());

        return [
            'save' => $this->store()->open($saveId)->toArray(),
            'date' => $date->toIsoString(),
            'player_id' => $career->playerId()->value(),
            'player' => $summary['player'] ?? [],
            'career' => $summary,
            'finance' => $this->services->playerFinanceService()->summary($database, $career->playerId(), $date),
        ];
    }

    public function run(SimulationScenario $scenario): SimulationResult
    {
        $options = $scenario->options();
        $horizon = max(1, min(5, $scenario->horizon()));
        $archetype = strtolower((string) ($options['archetype'] ?? 'regular'));
        $output = new BufferedConsoleOutput();
        $command = new CareerMultiSeasonAuditCommand($this->services);
        $exitCode = $command->execute(['--observatory', '--seasons=' . $horizon, '--seed=' . $scenario->seed(), '--archetype=' . $archetype], $output);
        if ($exitCode !== 0) {
            throw new RuntimeException(implode(' ', $output->errors()) ?: 'GOAL simulation failed.');
        }
        $checkpoints = [];
        foreach ($output->messages() as $message) {
            if (str_starts_with($message, 'CHECKPOINT ')) {
                $parsed = $this->parseCheckpoint(substr($message, strlen('CHECKPOINT ')));
                if ($parsed !== null) {
                    $checkpoints[] = $parsed;
                }
            }
        }

        return new SimulationResult('PASS', $checkpoints, [], ['seed' => $scenario->seed(), 'archetype' => $archetype, 'horizon' => $horizon, 'console' => $output->messages()]);
    }

    public function runMatches(string $saveId, int $count = 1, ?string $firstMatchId = null): SimulationResult
    {
        $database = $this->database($saveId);
        $career = (new CareerPlayerRepository($database))->get($saveId);

        return (new GoalMatchRunner($this->services))->many($database, $saveId, $career->playerId()->value(), $count, $firstMatchId);
    }

    public function runScenario(string $scenarioId, int $seed = 3009): SimulationResult
    {
        $fixture = (new GoalScenarioBuilder($this->services))->build($scenarioId, $seed);
        try {
            $adapter = new self($this->services, $this->projectRoot, $fixture->store());
            $inspection = $adapter->inspect($fixture->saveId());
            if (($inspection['career']['current_club'] ?? null) === null || ($inspection['career']['current_contract'] ?? null) === null) {
                return new SimulationResult('PASS', [new SimulationCheckpoint('SCENARIO', 'GOAL_PLAYER', $adapter->compactState($inspection), ['source' => 'GoalScenarioBuilder'])], [], ['scenario' => strtoupper($scenarioId), 'seed' => $seed]);
            }

            return (new GoalMatchRunner($this->services))->one($fixture->database(), $fixture->saveId(), $fixture->playerId()->value());
        } finally {
            $fixture->close();
        }
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after @return list<array{path:string,status:string,before:mixed,after:mixed}> */
    public function stateDiff(array $before, array $after, int $limit = 100): array
    {
        return SimulationStateDiff::compare($before, $after, $limit);
    }

    /** @return list<array<string,mixed>> */
    public function audit(string $saveId): array
    {
        $connection = $this->database($saveId)->connection();
        $exists = $connection->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'haya_simulation_mutation_audit'")->fetchColumn();
        if ($exists === false) {
            return [];
        }

        return $connection->query('SELECT occurred_at, actor, capability, target, status, before_json, after_json FROM haya_simulation_mutation_audit ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<SimulationDiagnosticResult> */
    public function diagnostics(string $saveId, ?array $ids = null): array
    {
        $available = [
            'goal.diagnostics.playing_time' => fn (): SimulationDiagnosticResult => $this->playingTimeDiagnostic($saveId),
            'goal.diagnostics.squad_competition' => fn (): SimulationDiagnosticResult => $this->squadCompetitionDiagnostic($saveId),
            'goal.diagnostics.mobility' => fn (): SimulationDiagnosticResult => $this->mobilityDiagnostic($saveId),
            'goal.validation.integrity' => fn (): SimulationDiagnosticResult => $this->integrityDiagnostic($saveId),
        ];
        $selected = $ids === null ? array_keys($available) : array_values(array_intersect(array_keys($available), $ids));
        return array_map(static fn (string $id): SimulationDiagnosticResult => $available[$id](), $selected);
    }

    public function mutate(SimulationMutation $mutation): SimulationMutationResult
    {
        $this->assertMutationAccess($mutation);
        if ($mutation->capability() === 'sandbox.clone') {
            return $this->cloneSandbox($mutation);
        }
        if ($mutation->capability() === 'sandbox.restore_snapshot') {
            return $this->restoreSnapshot($mutation);
        }
        $before = $this->inspect($mutation->saveId());
        $this->snapshot($mutation->saveId(), $mutation->actor());
        $database = $this->database($mutation->saveId());
        $playerId = (string) $before['player_id'];
        $date = SimulationDate::fromIsoString((string) $before['date']);
        $input = $mutation->input();
        try {
            switch ($mutation->capability()) {
            case 'goal.player.set_attribute':
                $player = (new PlayerRepository($database))->get($playerId);
                $attributes = $player->attributes()->toArray();
                $name = strtolower(trim((string) ($input['name'] ?? '')));
                if (!array_key_exists($name, $attributes)) { throw new RuntimeException('Unknown Player attribute.'); }
                $value = filter_var($input['value'] ?? null, FILTER_VALIDATE_INT);
                if ($value === false || $value < 0 || $value > 99) { throw new RuntimeException('Player attributes must be integers from 0 to 99.'); }
                $attributes[$name] = $value;
                $updated = $player->withAttributes(new PlayerAttributeSet(...array_values($attributes)));
                if ($updated->overallRating() > $updated->potential()) { throw new RuntimeException('Attribute change would place OVR above potential.'); }
                (new PlayerRepository($database))->save($updated);
                break;
            case 'goal.player.set_potential':
                $player = (new PlayerRepository($database))->get($playerId);
                $value = filter_var($input['value'] ?? null, FILTER_VALIDATE_INT);
                if ($value === false || $value < $player->overallRating() || $value > 99) { throw new RuntimeException('Potential must be an integer from current OVR to 99.'); }
                (new PlayerRepository($database))->save($player->withPotential($value));
                break;
            case 'goal.player.set_role':
                $world = $this->services->worldModule()->service()->load($database, $mutation->saveId());
                $seasonId = $world->currentSeasonId();
                if ($seasonId === null) { throw new RuntimeException('The save has no active Season.'); }
                $membership = (new ClubSquadRepository($database))->byPlayer($playerId, $seasonId)[0] ?? null;
                if ($membership === null) { throw new RuntimeException('The controlled Player has no current squad membership.'); }
                (new ClubSquadRepository($database))->updateRole($membership, SquadRole::fromInput((string) ($input['role'] ?? '')), $date->toIsoString(), 'sandbox');
                break;
            case 'goal.player.set_balance':
                $value = filter_var($input['balance'] ?? null, FILTER_VALIDATE_INT);
                if ($value === false || $value < 0 || $value > 100000000) { throw new RuntimeException('Balance is outside the safe sandbox range.'); }
                $finance = new PlayerFinanceRepository($database);
                $state = $finance->state($playerId);
                if ($state === null) { throw new RuntimeException('The controlled Player has no initialized finance state.'); }
                $finance->saveStateInTransaction($playerId, $value, (string) $state['initialized_date'], (string) $state['last_payroll_date']);
                break;
            case 'goal.player.apply_injury':
                $availability = new PlayerAvailabilityRepository($database);
                if ($availability->activeInjuryAt(new PlayerId($playerId), $date) !== null) { throw new RuntimeException('The controlled Player already has an active injury.'); }
                $severity = InjurySeverity::from(strtolower(trim((string) ($input['severity'] ?? 'moderate'))));
                $injury = new Injury(hash('sha256', 'sandbox-injury|' . $playerId . '|' . $date->toIsoString()), new PlayerId($playerId), 'sandbox', 'sandbox-injury-' . $date->toIsoString(), InjuryCategory::Other, $severity, $date, $date->addDays($severity->durationDays()));
                $database->transaction(static fn () => $availability->saveInjuryInTransaction($injury));
                break;
            case 'goal.player.clear_injury':
                $availability = new PlayerAvailabilityRepository($database);
                $injury = $availability->activeInjuryAt(new PlayerId($playerId), $date);
                if ($injury === null) { throw new RuntimeException('The controlled Player has no active injury.'); }
                $database->transaction(static fn () => $availability->markRecoveredInTransaction($injury, $date));
                break;
            case 'goal.player.apply_suspension':
                $matches = filter_var($input['matches'] ?? 1, FILTER_VALIDATE_INT);
                if ($matches === false || $matches < 1 || $matches > 5) { throw new RuntimeException('Sandbox suspensions must last from 1 to 5 Matches.'); }
                (new PlayerDisciplineRepository($database))->save(['player_id' => $playerId, 'scope' => 'domestic_league', 'accumulation_cycle' => $date->toIsoString(), 'yellow_count' => 0, 'suspension_matches_remaining' => $matches, 'suspension_reason' => 'sandbox', 'source_match_id' => null, 'source_competition_id' => 'premier-league', 'updated_date' => $date->toIsoString()]);
                break;
            case 'goal.player.clear_suspension':
                $discipline = new PlayerDisciplineRepository($database);
                foreach ($discipline->byPlayer($playerId) as $state) {
                    $state['suspension_matches_remaining'] = 0;
                    $state['suspension_reason'] = null;
                    $discipline->save($state);
                }
                break;
            case 'goal.contract.set_wage':
                $contract = (new ContractRepository($database))->activeForPlayer($playerId);
                if ($contract === null) { throw new RuntimeException('The controlled Player has no active Contract.'); }
                $wage = filter_var($input['wage'] ?? null, FILTER_VALIDATE_INT);
                if ($wage === false || $wage < 0 || $wage > 500000) { throw new RuntimeException('Contract wage must be an integer from 0 to 500000.'); }
                $loan = (new LoanRepository($database, false))->activeForPlayer(new PlayerId($playerId));
                if ($loan !== null && $contract->clubId()->value() !== $loan->parentClubId()->value()) { throw new RuntimeException('An active loan must retain its parent Contract.'); }
                $this->services->contractModule()->service()->updateActiveTerms($database, $playerId, $contract->endDate(), $wage);
                break;
            case 'goal.contract.set_term':
                $contract = (new ContractRepository($database))->activeForPlayer($playerId);
                if ($contract === null) { throw new RuntimeException('The controlled Player has no active Contract.'); }
                $endDate = SimulationDate::fromIsoString(trim((string) ($input['end_date'] ?? '')));
                if (!$endDate->isAfter($date)) { throw new RuntimeException('Contract term must end after the current Career date.'); }
                $loan = (new LoanRepository($database, false))->activeForPlayer(new PlayerId($playerId));
                if ($loan !== null && $endDate->isBefore($loan->scheduledEndDate())) { throw new RuntimeException('An active loan cannot outlive the parent Contract.'); }
                $this->services->contractModule()->service()->updateActiveTerms($database, $playerId, $endDate, $contract->wage());
                break;
            case 'goal.movement.request_transfer':
                $worldService = $this->services->worldModule()->service();
                $world = $worldService->load($database, $mutation->saveId());
                $seasonId = $world->currentSeasonId();
                if ($seasonId === null) { throw new RuntimeException('The save has no active Season.'); }
                $season = (new \Goal\Legacy\Modules\World\Persistence\SeasonRepository($database))->get($seasonId);
                $this->services->transferModule()->service()->careerMovement()->requestTransfer($database, $playerId, $season, $date);
                break;
            case 'goal.movement.return_loan':
                $loan = (new LoanRepository($database, false))->activeForPlayer(new PlayerId($playerId));
                if ($loan === null) { throw new RuntimeException('The controlled Player has no due active loan.'); }
                $this->services->transferModule()->service()->returnLoan($database, $loan, $date);
                break;
            case 'goal.movement.permanent_transfer':
                $worldService = $this->services->worldModule()->service();
                $world = $worldService->load($database, $mutation->saveId());
                $seasonId = $world->currentSeasonId();
                if ($seasonId === null) { throw new RuntimeException('The save has no active Season.'); }
                $season = (new \Goal\Legacy\Modules\World\Persistence\SeasonRepository($database))->get($seasonId);
                $loan = (new LoanRepository($database, false))->activeForPlayer(new PlayerId($playerId), null, $date);
                if ($loan !== null) { throw new RuntimeException('Permanent movement is unavailable while the Player is on loan.'); }
                $membership = (new ClubSquadRepository($database))->byPlayer($playerId, $seasonId)[0] ?? null;
                $contract = (new ContractRepository($database))->activeForPlayer($playerId);
                $targetClubId = trim((string) ($input['club'] ?? ''));
                if ($membership === null || $contract === null) { throw new RuntimeException('Permanent movement requires an active Club membership and Contract.'); }
                if ($targetClubId === '' || $targetClubId === $membership->clubId()->value() || !$this->services->clubModule()->service()->repository($database)->exists(new \Goal\Legacy\Modules\Club\Domain\ClubId($targetClubId))) { throw new RuntimeException('Choose a different existing destination Club.'); }
                $targetMemberships = array_values(array_filter($this->services->clubModule()->service()->membershipRepository($database)->byClub($targetClubId), static fn ($row): bool => $row->seasonId()->value() === $seasonId->value()));
                if ($targetMemberships === []) { throw new RuntimeException('Destination Club is not registered for the current Season.'); }
                if (count($this->services->clubModule()->service()->squadRepository($database)->byClub($targetClubId, $seasonId)) >= \Goal\Legacy\Modules\Player\PlayerPopulationService::TARGET_SQUAD_SIZE) { throw new RuntimeException('Destination Club has no safe squad capacity.'); }
                foreach ((new TransferRepository($database))->byPlayer($playerId) as $existing) { if ($existing->seasonId()->value() === $seasonId->value() && $existing->status()->value === 'completed') { throw new RuntimeException('The Player has already moved in this Season.'); } }
                $wage = filter_var($input['wage'] ?? $contract->wage(), FILTER_VALIDATE_INT);
                if ($wage === false || $wage < 0 || $wage > 500000) { throw new RuntimeException('Destination wage must be an integer from 0 to 500000.'); }
                $endDate = isset($input['end_date']) && trim((string) $input['end_date']) !== '' ? SimulationDate::fromIsoString(trim((string) $input['end_date'])) : $date->addDays(365);
                $transferId = new TransferId('sandbox-transfer-' . substr(hash('sha256', $playerId . '|' . $targetClubId . '|' . $date->toIsoString()), 0, 24));
                $transfer = new Transfer($transferId, new PlayerId($playerId), $membership->clubId(), new \Goal\Legacy\Modules\Club\Domain\ClubId($targetClubId), $seasonId, 0, $date);
                $this->services->transferModule()->service()->execute($database, $transfer, new TransferExecutionTerms(new \Goal\Legacy\Modules\Contract\Domain\ContractId('sandbox-destination-contract-' . substr(hash('sha256', $transferId->value()), 0, 20)), $endDate, $wage, $membership->role()));
                break;
            case 'time.advance':
                $days = filter_var($input['days'] ?? null, FILTER_VALIDATE_INT);
                if ($days === false || $days < 1 || $days > 31) { throw new RuntimeException('Time advancement is limited to 1-31 days.'); }
                $worldService = $this->services->worldModule()->service();
                $worldService->advanceToDate($database, $mutation->saveId(), $date->addDays($days));
                break;
                default:
                    throw new RuntimeException('Unsupported GOAL mutation.');
            }
        } catch (\Throwable $exception) {
            $this->recordAudit($mutation, 'failure', $this->compactState($before), ['error' => $exception->getMessage()]);
            throw $exception;
        }
        $after = $this->inspect($mutation->saveId());
        $metadata = $this->store()->open($mutation->saveId())->asSandbox($mutation->saveId(), new DateTimeImmutable());
        $this->store()->update($metadata);
        $this->recordAudit($mutation, 'success', $this->compactState($before), $this->compactState($after));

        return new SimulationMutationResult('PASS', $this->compactState($before), $this->compactState($after), 'Sandbox mutation applied to the selected save.');
    }

    private function database(string $saveId): DatabaseInterface
    {
        if (!$this->store()->exists($saveId)) { throw new RuntimeException('That save does not exist.'); }
        return $this->store()->openDatabase($saveId);
    }

    private function assertMutationAccess(SimulationMutation $mutation): void
    {
        if (!in_array($mutation->permission(), [SimulationPermission::PREMIUM_SANDBOX, SimulationPermission::DEVELOPER, SimulationPermission::SYSTEM_TEST], true)) {
            throw new RuntimeException('Sandbox mutations require Premium Sandbox, Developer, or System Test permission.');
        }
        $metadata = $this->store()->open($mutation->saveId());
        if ($mutation->permission() === SimulationPermission::PREMIUM_SANDBOX && $mutation->capability() !== 'sandbox.clone' && !$metadata->isSandbox()) {
            throw new RuntimeException('Premium gameplay experiments require a SANDBOX SAVE. Clone the Career first.');
        }
        if ($mutation->permission() === SimulationPermission::PREMIUM_SANDBOX && ($metadata->ownerId() === null || $metadata->ownerId() !== $mutation->actor())) {
            throw new RuntimeException('You may only mutate saves owned by your account.');
        }
    }

    private function snapshot(string $saveId, string $actor): void
    {
        $metadata = $this->store()->open($saveId);
        $snapshotId = $this->snapshotId($saveId);
        if ($this->store()->exists($snapshotId)) { $this->store()->delete($snapshotId); }
        $snapshot = \Goal\Legacy\Core\Persistence\SaveMetadata::create($snapshotId, $metadata->name() . ' snapshot', $metadata->simulationTime(), new DateTimeImmutable(), $metadata->ownerId())->asSandbox($saveId);
        $this->store()->cloneSave($saveId, $snapshot);
    }

    private function restoreSnapshot(SimulationMutation $mutation): SimulationMutationResult
    {
        $metadata = $this->store()->open($mutation->saveId());
        $snapshotId = $this->snapshotId($mutation->saveId());
        if (!$this->store()->exists($snapshotId)) { throw new RuntimeException('No sandbox snapshot is available.'); }
        $before = $this->inspect($mutation->saveId());
        $this->store()->delete($mutation->saveId());
        $restored = \Goal\Legacy\Core\Persistence\SaveMetadata::create($mutation->saveId(), $metadata->name(), $metadata->simulationTime(), new DateTimeImmutable($metadata->createdAt()), $metadata->ownerId())->asSandbox($metadata->sandboxSourceId(), new DateTimeImmutable());
        $this->store()->cloneSave($snapshotId, $restored);
        $after = $this->inspect($mutation->saveId());
        $this->recordAudit($mutation, 'success', $this->compactState($before), $this->compactState($after));
        return new SimulationMutationResult('PASS', $this->compactState($before), $this->compactState($after), 'Sandbox snapshot restored.');
    }

    private function cloneSandbox(SimulationMutation $mutation): SimulationMutationResult
    {
        $destinationId = trim((string) ($mutation->input()['destination'] ?? $mutation->target()));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $destinationId) !== 1 || $destinationId === $mutation->saveId()) {
            throw new RuntimeException('Choose a different destination save ID using letters, numbers, hyphens, or underscores.');
        }
        if ($this->store()->exists($destinationId)) { throw new RuntimeException('The destination sandbox save already exists.'); }
        $source = $this->store()->open($mutation->saveId());
        $destination = \Goal\Legacy\Core\Persistence\SaveMetadata::create($destinationId, $source->name() . ' Sandbox', $source->simulationTime(), new DateTimeImmutable($source->createdAt()), $mutation->actor())->asSandbox($mutation->saveId());
        $this->store()->cloneSave($mutation->saveId(), $destination);
        $destinationDatabase = $this->database($destinationId);
        (new CareerPlayerRepository($destinationDatabase))->rebindCareerId(new CareerId($mutation->saveId()), new CareerId($destinationId));
        (new WorldRepository($destinationDatabase))->rebindRoot(new WorldId($mutation->saveId()), new WorldId($destinationId));
        $after = $this->inspect($destinationId);
        $this->recordAudit(new SimulationMutation('sandbox.clone', $destinationId, $destinationId, [], $mutation->actor(), $mutation->permission()), 'success', [], $this->compactState($after));
        return new SimulationMutationResult('PASS', [], $this->compactState($after), 'Career cloned to a save-scoped Sandbox.');
    }

    private function snapshotId(string $saveId): string { return substr($saveId, 0, 43) . '-sandbox-snapshot'; }

    private function store(): SaveStore
    {
        return $this->simulationStore ?? $this->services->saveStore();
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function compactState(array $state): array
    {
        $career = (array) ($state['career'] ?? []);
        return ['save' => $state['save'] ?? [], 'date' => $state['date'] ?? null, 'player_id' => $state['player_id'] ?? null, 'player' => $state['player'] ?? [], 'finance' => ['balance' => $state['finance']['balance'] ?? null], 'career' => ['current_club' => $career['current_club'] ?? null, 'current_role' => $career['current_role'] ?? null, 'career_state' => $career['career_state'] ?? null, 'current_contract' => $career['current_contract'] ?? null, 'active_loan' => $career['active_loan'] ?? null, 'transfer_request' => $career['transfer_request'] ?? null]];
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private function recordAudit(SimulationMutation $mutation, string $status, array $before, array $after): void
    {
        $database = $this->database($mutation->saveId());
        $connection = $database->connection();
        $connection->exec('CREATE TABLE IF NOT EXISTS haya_simulation_mutation_audit (id INTEGER PRIMARY KEY AUTOINCREMENT, occurred_at TEXT NOT NULL, actor TEXT NOT NULL, capability TEXT NOT NULL, target TEXT NOT NULL, status TEXT NOT NULL, before_json TEXT NOT NULL, after_json TEXT NOT NULL)');
        $statement = $connection->prepare('INSERT INTO haya_simulation_mutation_audit (occurred_at, actor, capability, target, status, before_json, after_json) VALUES (:occurred_at, :actor, :capability, :target, :status, :before_json, :after_json)');
        $statement->execute(['occurred_at' => (new DateTimeImmutable())->format(DATE_ATOM), 'actor' => $mutation->actor(), 'capability' => $mutation->capability(), 'target' => $mutation->target(), 'status' => $status, 'before_json' => json_encode($before, JSON_THROW_ON_ERROR), 'after_json' => json_encode($after, JSON_THROW_ON_ERROR)]);
        $connection->exec('DELETE FROM haya_simulation_mutation_audit WHERE id NOT IN (SELECT id FROM haya_simulation_mutation_audit ORDER BY id DESC LIMIT 50)');
    }

    private function integrityDiagnostic(string $saveId): SimulationDiagnosticResult
    {
        $database = $this->database($saveId);
        $career = (new CareerPlayerRepository($database))->get($saveId);
        $player = (new PlayerRepository($database))->get($career->playerId());
        $world = $this->services->worldModule()->service()->load($database, $saveId);
        $seasonId = $world->currentSeasonId();
        $memberships = $seasonId === null ? [] : (new ClubSquadRepository($database))->byPlayer($player->id(), $seasonId);
        $contracts = (new ContractRepository($database))->activeForPlayer($player->id());
        $loan = (new LoanRepository($database, false))->activeForPlayer($player->id(), $seasonId, $world->currentDate($this->services->worldModule()->service()->calendar()));
        $ok = count($memberships) <= 1 && (!$player->isRetired() || ($contracts === null && $memberships === []));
        return new SimulationDiagnosticResult('goal.validation.integrity', $ok ? 'PASS' : 'FAIL', $ok ? 'INFO' : 'ERROR', $ok ? 'Controlled-player identity and active-state checks passed.' : 'Controlled-player state has a contradictory active relationship.', ['memberships=' . count($memberships), 'active_contract=' . ($contracts === null ? 'none' : 'one'), 'active_loan=' . ($loan === null ? 'none' : 'one')], 'SAVE', ['memberships' => count($memberships), 'active_contract' => $contracts !== null, 'active_loan' => $loan !== null], 'Investigate the canonical lifecycle owner.', 'CHEAP');
    }

    private function playingTimeDiagnostic(string $saveId): SimulationDiagnosticResult
    {
        $context = $this->diagnosticContext($saveId);
        $counts = ['eligible_matches' => 0, 'starts' => 0, 'used_substitute' => 0, 'unused_bench' => 0, 'not_selected' => 0, 'injured' => 0, 'suspended' => 0, 'other_unavailable' => 0, 'minutes' => 0];
        foreach ($context['matches'] as $match) {
            if ($match->status() !== MatchStatus::Completed) { continue; }
            ++$counts['eligible_matches'];
            $selection = $context['selections'][$match->id()->value()] ?? null;
            $stat = $context['stats'][$match->id()->value()] ?? null;
            if ($stat?->started()) { ++$counts['starts']; }
            if ($stat?->appeared() && !$stat->started()) { ++$counts['used_substitute']; }
            if ($selection !== null && $selection->status()->value === 'bench' && !$stat?->appeared()) { ++$counts['unused_bench']; }
            if ($selection === null || $selection->status()->value === 'not_selected') { ++$counts['not_selected']; }
            if ($selection?->status()->value === 'suspended') { ++$counts['suspended']; }
            if ($selection?->status()->value === 'unavailable') {
                $injury = $context['availability']->activeInjuryAt(new PlayerId((string) $context['player_id']), $match->scheduledDate());
                ++$counts[$injury === null ? 'other_unavailable' : 'injured'];
            }
            $counts['minutes'] += $stat?->minutes() ?? 0;
        }
        return new SimulationDiagnosticResult('goal.diagnostics.playing_time', 'PASS', 'INFO', 'Playing-time evidence collected from canonical Matches and selection records.', ['Current-season opportunities are bounded to the controlled Player\'s active Club.'], 'PLAYER', $counts, '', 'CHEAP');
    }

    private function squadCompetitionDiagnostic(string $saveId): SimulationDiagnosticResult
    {
        $inspection = $this->inspect($saveId);
        $competition = (array) (($inspection['career']['position_competition'] ?? []));
        return new SimulationDiagnosticResult('goal.diagnostics.squad_competition', 'PASS', 'INFO', 'Canonical position-competition context collected; no selector formula was recreated.', ['The selector remains owned by Club/Match services.'], 'PLAYER', ['primary_position' => $inspection['player']['primary_position'] ?? null, 'position_competition' => $competition, 'role' => $inspection['career']['current_role'] ?? null], '', 'CHEAP');
    }

    private function mobilityDiagnostic(string $saveId): SimulationDiagnosticResult
    {
        $inspection = $this->inspect($saveId);
        $career = $inspection['career'];
        return new SimulationDiagnosticResult('goal.diagnostics.mobility', 'PASS', 'INFO', 'Existing movement and Contract state collected without calculating a hidden score.', ['Opportunities and eligibility remain owned by CareerMovementService.'], 'PLAYER', ['transfer_request' => $career['transfer_request'] ?? null, 'available_actions' => $career['available_actions'] ?? [], 'open_opportunities' => $career['open_opportunities'] ?? [], 'contract' => $career['current_contract'] ?? null, 'loan' => $career['active_loan'] ?? null], '', 'CHEAP');
    }

    /** @return array{matches:list<object>,selections:array<string,object>,stats:array<string,object>,availability:PlayerAvailabilityRepository,player_id:string} */
    private function diagnosticContext(string $saveId): array
    {
        $database = $this->database($saveId);
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->load($database, $saveId);
        $career = (new CareerPlayerRepository($database))->get($saveId);
        $seasonId = $world->currentSeasonId();
        if ($seasonId === null) { return ['matches' => [], 'selections' => [], 'stats' => [], 'availability' => new PlayerAvailabilityRepository($database), 'player_id' => $career->playerId()->value()]; }
        $membership = (new ClubSquadRepository($database))->byPlayer($career->playerId(), $seasonId)[0] ?? null;
        if ($membership === null) { return ['matches' => [], 'selections' => [], 'stats' => [], 'availability' => new PlayerAvailabilityRepository($database), 'player_id' => $career->playerId()->value()]; }
        $matches = (new MatchRepository($database))->byClub($membership->clubId(), $seasonId);
        $selections = [];
        foreach ((new MatchSelectionRepository($database))->byPlayer($career->playerId()) as $selection) { $selections[$selection->matchId()->value()] = $selection; }
        $stats = [];
        foreach ((new PlayerMatchStatRepository($database))->byPlayer($career->playerId()) as $stat) { $stats[$stat->matchId()->value()] = $stat; }
        return ['matches' => $matches, 'selections' => $selections, 'stats' => $stats, 'availability' => new PlayerAvailabilityRepository($database), 'player_id' => $career->playerId()->value()];
    }

    /** @return SimulationCheckpoint|null */
    private function parseCheckpoint(string $line): ?SimulationCheckpoint
    {
        $pattern = '/^label=(\S+) season=(\S+) age=(\d+) club=(.*?) competition=(.*?) tier=(\S+) ovr=(\d+) attrs=(\S+) potential=(\d+) role=(\S+) apps=(\d+) starts=(\d+) minutes=(\d+) goals=(\d+) assists=(\d+) rating=(\S+) availability=(\S+) fatigue=(\d+) injuries=(\d+) contract=(\S+) contract_days=(\S+) wage=(\S+) contracts=(\d+) transfers=(\d+) loans=(\d+) honours=(\d+) awards=(\d+) outlook=(\S+) retirement=(\S+) state=(\S+)$/';
        if (preg_match($pattern, $line, $m) !== 1) { return null; }
        $attributes = array_map('intval', explode('/', $m[8]));
        return new SimulationCheckpoint($m[1], 'GOAL_PLAYER', ['season' => $m[2], 'age' => (int) $m[3], 'club' => trim($m[4]), 'competition' => trim($m[5]), 'tier' => $m[6], 'ovr' => (int) $m[7], 'attributes' => ['pace' => $attributes[0] ?? 0, 'shooting' => $attributes[1] ?? 0, 'passing' => $attributes[2] ?? 0, 'dribbling' => $attributes[3] ?? 0, 'defending' => $attributes[4] ?? 0, 'physicality' => $attributes[5] ?? 0], 'potential' => (int) $m[9], 'role' => $m[10], 'appearances' => (int) $m[11], 'starts' => (int) $m[12], 'minutes' => (int) $m[13], 'goals' => (int) $m[14], 'assists' => (int) $m[15], 'rating' => $m[16], 'availability' => $m[17], 'fatigue' => (int) $m[18], 'injuries' => (int) $m[19], 'contract' => $m[20], 'contract_days' => $m[21], 'wage' => $m[22], 'contracts' => (int) $m[23], 'transfers' => (int) $m[24], 'loans' => (int) $m[25], 'honours' => (int) $m[26], 'awards' => (int) $m[27], 'outlook' => $m[28], 'retirement' => $m[29], 'career_state' => $m[30]], ['source' => 'CareerMultiSeasonAuditCommand']);
    }
}
