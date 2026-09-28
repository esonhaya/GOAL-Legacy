<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Simulation;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\OwnedArtifactCleanup;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Transfer\Domain\Transfer;
use Goal\Legacy\Modules\Transfer\Domain\TransferExecutionTerms;
use Goal\Legacy\Modules\Transfer\Domain\TransferId;
use Goal\Legacy\Modules\Transfer\Persistence\LoanRepository;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\DevelopmentProfile;
use Goal\Legacy\Modules\Player\Domain\Injury;
use Goal\Legacy\Modules\Player\Domain\InjuryCategory;
use Goal\Legacy\Modules\Player\Domain\InjurySeverity;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Domain\TrainingFocus;
use Goal\Legacy\Modules\Player\Domain\TrainingIntensity;
use Goal\Legacy\Modules\Player\Domain\TrainingRequest;
use Goal\Legacy\Modules\Player\Persistence\PlayerAvailabilityRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerDisciplineRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use RuntimeException;

/** Builds only canonical GOAL state; it does not implement gameplay formulas. */
final class GoalScenarioBuilder
{
    public function __construct(private readonly CoreServices $services) {}

    public function build(string $scenarioId, int $seed = 3009): GoalScenarioFixture
    {
        $definition = (new GoalScenarioCatalog())->get($scenarioId);
        $options = $definition->defaults();
        $directory = sys_get_temp_dir() . '/goal-legacy-scenario-' . strtolower($definition->id()) . '-' . bin2hex(random_bytes(6));
        // The directory isolates concurrent runs; the stable save identity keeps
        // source keys and deterministic scenario output independent of the host.
        $saveId = 'scenario-save';
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $this->services->worldModule()->service()->calendar();
        $world = new World(new WorldId($saveId), 'Simulation Lab ' . $definition->label(), $seed, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $this->services->nationModule()->service()->loadSelected()), array_map(static fn ($competition): string => $competition->id()->value(), $this->services->competitionModule()->service()->loadSelected()), $this->services->contentPackages()->selectedIds());
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $database = null;
        try {
            $store->create(SaveMetadata::create($saveId, $world->label(), $world->currentTime(), new DateTimeImmutable('@0')));
            $database = $store->openDatabase($saveId);
            $this->services->worldModule()->service()->initialize($database, $world, $season);
            $this->services->playerModule()->service()->populationService()->populate($database, $season, $seed);

            $player = $this->createPlayer($database, $saveId, $season, $options, $seed);
            $clubId = (string) ($options['club'] ?? 'arsenal');
            $role = SquadRole::fromInput((string) ($options['role'] ?? 'rotation'));
            $membership = new ClubSquadMembership(new ClubId($clubId), $player->id(), $season->id(), $role);
            $this->services->playerModule()->service()->initializeCareer($database, $player, new CareerPlayerReference(new CareerId($saveId), $player->id(), SimulationDate::fromIsoString('2024-07-31')), $membership);
            if (isset($options['secondary_position'])) {
                $date = SimulationDate::fromIsoString('2024-07-31');
                $this->services->playerModule()->service()->positionDevelopmentService()->setFocus($database, $player->id(), PlayerPosition::fromInput((string) $options['secondary_position']), $date);
                $this->services->playerModule()->service()->trainingService()->complete($database, new TrainingRequest($player->id(), 'scenario-secondary-position', TrainingFocus::Balanced, $date, $date->addDays(70), TrainingIntensity::Normal));
            }
            $this->createContract($database, $player->id()->value(), $clubId, $season, $options);
            if (($options['contract'] ?? true) !== false) {
                $this->services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId($clubId), $player->id()));
            } else {
                $this->services->clubModule()->service()->squadRepository($database)->remove($membership);
            }
            $this->createCompetitors($database, $season, $clubId, (array) ($options['competitor_ovrs'] ?? []), $seed);
            $this->applyAvailability($database, $player->id()->value(), $season, (string) ($options['availability'] ?? 'available'));
            $matches = $this->services->matchModule()->service()->generateFixtures($database, 'premier-league', $season->id());
            $this->applyMovement($database, $player->id()->value(), $clubId, $season, $options);

            return new GoalScenarioFixture($directory, $store, $saveId, $database, $player->id(), $clubId, $season->id(), array_map(static fn ($match): string => $match->id()->value(), $matches));
        } catch (\Throwable $exception) {
            unset($database);
            $this->removeOwnedDirectory($directory);
            throw $exception;
        }
    }

    /**
     * Build the smallest reusable production-path archive fixture: one
     * populated completed Season followed by an active successor. The Match
     * service, World rollover, recruitment, and registration lifecycle remain
     * authoritative; this helper only orchestrates them for diagnostics/tests.
     */
    public function buildFinalizedSeasonWithActiveSuccessor(int $seed = 4004): GoalScenarioFixture
    {
        $fixture = $this->build('CONTRACT_LONG_TERM', $seed);
        try {
            $database = $fixture->database();
            $worldService = $this->services->worldModule()->service();
            $matchService = $this->services->matchModule()->service();
            $matches = (new MatchRepository($database))->byCompetition('premier-league', $fixture->seasonId());
            usort($matches, static fn ($left, $right): int => $left->scheduledDate()->compareTo($right->scheduledDate()) ?: strcmp($left->id()->value(), $right->id()->value()));
            foreach ($matches as $match) {
                $worldService->advanceToDate($database, $fixture->saveId(), $match->scheduledDate());
                $matchService->simulateDue($database, $match->scheduledDate());
            }
            $season = $worldService->seasonRepository($database)->get($fixture->seasonId());
            $worldService->advanceToDate($database, $fixture->saveId(), $season->endDate()->addDays(1));
            $next = $worldService->seasonRollover()?->nextSeason($season);
            if ($next === null) {
                throw new RuntimeException('The archive fixture could not derive its successor Season.');
            }
            $worldService->advanceToDate($database, $fixture->saveId(), $next->startDate());

            return $fixture;
        } catch (\Throwable $exception) {
            $fixture->close();
            throw $exception;
        }
    }

    /** @param array<string,mixed> $options */
    private function createPlayer($database, string $saveId, Season $season, array $options, int $seed): \Goal\Legacy\Modules\Player\Domain\Player
    {
        $ovr = (int) ($options['ovr'] ?? 70);
        $potential = max($ovr, (int) ($options['potential'] ?? $ovr + 10));
        if ($ovr < 1 || $ovr > 99 || $potential < $ovr || $potential > 99) {
            throw new RuntimeException('Scenario OVR/potential must remain within canonical Player bounds.');
        }
        $position = PlayerPosition::fromInput((string) ($options['position'] ?? 'CM'));
        $profile = DevelopmentProfile::fromInput((string) ($options['archetype'] ?? 'regular'));
        $age = max(16, min(45, (int) ($options['age'] ?? 19)));
        $birthDate = sprintf('%04d-08-01', 2024 - $age);
        $attributes = (array) ($options['attributes'] ?? array_fill_keys(['pace', 'shooting', 'passing', 'dribbling', 'defending', 'physicality'], $ovr));
        $set = new PlayerAttributeSet((int) ($attributes['pace'] ?? $ovr), (int) ($attributes['shooting'] ?? $ovr), (int) ($attributes['passing'] ?? $ovr), (int) ($attributes['dribbling'] ?? $ovr), (int) ($attributes['defending'] ?? $ovr), (int) ($attributes['physicality'] ?? $ovr));

        return $this->services->playerModule()->service()->create(new PlayerCreationRequest('scenario-player', 'Simulation', 'Lab', 'Simulation Lab', $birthDate, 'england', [], 'england', ['england'], 180, 75, $position->value, $potential, $profile->value, $seed, $set));
    }

    /** @param array<string,mixed> $options */
    private function createContract($database, string $playerId, string $clubId, Season $season, array $options): void
    {
        if (($options['contract'] ?? true) === false) {
            return;
        }
        $start = SimulationDate::fromIsoString('2024-07-31');
        $contractKind = (string) ($options['contract'] ?? '');
        $end = match ($contractKind) {
            'expiring' => SimulationDate::fromIsoString('2024-12-31'),
            'long_term' => $season->endDate()->addDays(365 * 3),
            default => $season->endDate()->addDays(30),
        };
        $service = $this->services->contractModule()->service();
        $service->save($database, $service->create(new ContractCreationRequest(new ContractId('scenario-contract'), new \Goal\Legacy\Modules\Player\Domain\PlayerId($playerId), new ClubId($clubId), $start, $end, (int) ($options['wage'] ?? 1000), $start)));
    }

    /** @param array<string,mixed> $options */
    private function applyMovement($database, string $playerId, string $clubId, Season $season, array $options): void
    {
        $movement = (string) ($options['movement'] ?? '');
        if ($movement === '') {
            return;
        }
        $player = (new \Goal\Legacy\Modules\Player\Persistence\PlayerRepository($database))->get($playerId);
        $date = $season->startDate();
        $careerMovement = $this->services->transferModule()->service()->careerMovement();
        if ($movement === 'transfer_requested') {
            $careerMovement->requestTransfer($database, $player->id(), $season, $date);
            return;
        }
        if ($movement === 'permanent_transfer') {
            $transfer = new Transfer(new TransferId('scenario-transfer'), $player->id(), new ClubId($clubId), new ClubId('chelsea'), $season->id(), 0, $date);
            $this->services->transferModule()->service()->execute($database, $transfer, new TransferExecutionTerms(new ContractId('scenario-destination-contract'), $season->endDate()->addDays(365), 1200, SquadRole::Regular));
            return;
        }
        // The canonical loan market excludes full destination squads.  Make
        // one deterministic vacancy in the fixture so the production
        // opportunity/acceptance path can be exercised without forging a loan.
        $loanSquad = $this->services->clubModule()->service()->squadRepository($database);
        $vacancy = $loanSquad->byClub('cardiff-city', $season->id())[0] ?? null;
        if ($vacancy !== null) {
            $loanSquad->remove($vacancy);
        }
        $opportunity = $careerMovement->prepareControlledLoanDecision($database, $player->id(), $season, $date);
        if ($opportunity === null) {
            throw new RuntimeException('The scenario could not produce a canonical loan opportunity.');
        }
        $option = array_values(array_filter((array) ($opportunity->context()['options'] ?? []), static fn (mixed $row): bool => is_array($row) && ($row['kind'] ?? null) === 'accept_loan'))[0] ?? null;
        if (!is_array($option)) {
            throw new RuntimeException('The scenario loan decision has no accepted loan option.');
        }
        $resolved = $careerMovement->resolveLoanDecision($database, $opportunity->id(), (string) ($option['id'] ?? ''), $date);
        if ($movement === 'loan_active') {
            return;
        }
        if ($movement === 'post_loan_return') {
            $loan = (new LoanRepository($database))->get((string) ($option['loan_id'] ?? ''));
            if ($loan === null) {
                throw new RuntimeException('The scenario loan was not persisted.');
            }
            $this->services->transferModule()->service()->returnLoan($database, $loan, $loan->scheduledEndDate());
            return;
        }
        throw new RuntimeException(sprintf('Unknown scenario movement "%s".', $movement));
    }

    /** @param list<int> $ovrs */
    private function createCompetitors($database, Season $season, string $clubId, array $ovrs, int $seed): void
    {
        $players = $this->services->playerModule()->service();
        $squad = $this->services->clubModule()->service()->squadRepository($database);
        foreach (array_values($ovrs) as $index => $ovr) {
            $id = 'scenario-competitor-' . ($index + 1);
            $player = $players->create(new PlayerCreationRequest($id, 'Competitor', (string) ($index + 1), 'Competitor ' . ($index + 1), '2003-08-01', 'england', [], 'england', ['england'], 180, 75, 'CM', max(1, min(99, (int) $ovr + 5)), 'regular', $seed + $index + 1, new PlayerAttributeSet((int) $ovr, (int) $ovr, (int) $ovr, (int) $ovr, (int) $ovr, (int) $ovr)));
            $players->repository($database)->save($player);
            $squad->save(new ClubSquadMembership(new ClubId($clubId), $player->id(), $season->id(), SquadRole::Regular));
        }
    }

    private function applyAvailability($database, string $playerId, Season $season, string $availability): void
    {
        $player = new \Goal\Legacy\Modules\Player\Domain\PlayerId($playerId);
        $date = SimulationDate::fromIsoString('2024-07-31');
        if ($availability === 'fatigued') {
            $repository = new PlayerAvailabilityRepository($database);
            $repository->saveStateInTransaction($player, 70, $date, 1);
        } elseif ($availability === 'injured') {
            $repository = new PlayerAvailabilityRepository($database);
            $injury = new Injury('scenario-injury', $player, 'scenario', 'scenario-injury', InjuryCategory::Muscular, InjurySeverity::Moderate, $date, $date->addDays(10));
            $database->transaction(static fn () => $repository->saveInjuryInTransaction($injury));
        } elseif ($availability === 'suspended') {
            (new PlayerDisciplineRepository($database))->save(['player_id' => $playerId, 'scope' => 'domestic_league', 'accumulation_cycle' => $season->id()->value(), 'yellow_count' => 0, 'suspension_matches_remaining' => 1, 'suspension_reason' => 'scenario', 'source_match_id' => null, 'source_competition_id' => 'premier-league', 'updated_date' => $date->toIsoString()]);
        } elseif ($availability !== 'available') {
            throw new RuntimeException(sprintf('Unknown scenario availability "%s".', $availability));
        }
    }

    private function removeOwnedDirectory(string $directory): void
    {
        OwnedArtifactCleanup::removeOwnedDirectory($directory, 'goal-legacy-scenario-');
    }
}
