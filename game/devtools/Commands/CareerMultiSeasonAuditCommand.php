<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\OwnedArtifactCleanup;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Core\Persistence\SqlProfiler;
use Goal\Legacy\Core\Persistence\SqlProfileReporter;
use Goal\Legacy\Core\Persistence\SqliteQueryPlanExplainer;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Goal\Legacy\Devtools\Performance\CareerPerformanceProbe;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Contract\Domain\ContractStatus;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\CareerStartRequest;
use Goal\Legacy\Modules\Player\Domain\DevelopmentProfile;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCareerState;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\TrainingRequest;
use Goal\Legacy\Modules\Player\Persistence\CareerLegacyRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerOpportunityRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerAvailabilityRepository;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\Player\YouthCareerStartService;
use Goal\Legacy\Modules\Player\PlayerPopulationService;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Transfer\Persistence\LoanRepository;
use Goal\Legacy\Modules\Transfer\Persistence\TransferRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use RuntimeException;
use Throwable;

/** A continuous production-path audit for Season and squad sustainability. */
final class CareerMultiSeasonAuditCommand implements CommandInterface
{
    private const SEASON_ID = 'season-2024-25';
    private const SAVE_ID = 'career-multi-season-audit';
    private const CAREER_COMPETITION = 'premier-league';

    public function __construct(private readonly CoreServices $services) {}

    public function name(): string { return 'career:multi-season-audit'; }

    public function description(): string { return 'Run a continuous long-horizon career audit through real Season rollover.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        if (in_array('--observatory', $arguments, true)) {
            return $this->executeObservatory($arguments, $output);
        }
        $requested = $this->argumentInt($arguments, '--seasons=', 3);
        $seed = $this->argumentInt($arguments, '--seed=', 13003);
        $performancePath = $this->argumentValue($arguments, '--performance-json=', '');
        $lifecycleOnly = in_array('--lifecycle-only', $arguments, true);
        $minimumSeasons = $lifecycleOnly ? 1 : 3;
        if ($requested < $minimumSeasons || $requested > ($lifecycleOnly ? 10 : 5)) {
            $output->error($lifecycleOnly ? 'Lifecycle audit requires --seasons between 1 and 10.' : 'Multi-season audit requires --seasons between 3 and 5.');
            return 1;
        }

        $directory = sys_get_temp_dir() . '/goal-legacy-multi-season-' . bin2hex(random_bytes(8));
        $database = null;
        try {
            [$store, $database, $season, $world] = $this->initialize($directory, $seed);
            $population = $this->services->playerModule()->service()->populationService()->populate($database, $season, $seed);
            $performanceMode = $performancePath !== '';
            $players = $lifecycleOnly
                ? []
                : ($performanceMode ? ['fringe' => $this->installPerformancePlayer($database, $season, $seed)] : $this->installControlledPlayers($database, $season));
            if ($lifecycleOnly) {
                $profiler = in_array('--profile-sql', $arguments, true) ? new SqlProfiler() : null;
                if ($profiler !== null) {
                    $database = $store->openDatabase(self::SAVE_ID, $profiler);
                }
                return $this->executeLifecycleOnly($store, $database, $world, $season, $requested, $seed, $directory, $output, $profiler);
            }
            // Keep the long-horizon audit comparable to CAREER-003 and
            // Termux-feasible. The World still contains all selected
            // competitions and all 96 Clubs; this harness drives one full
            // league while rollover processes the populated world.
            $competitionIds = [self::CAREER_COMPETITION];
            $matchService = $this->services->matchModule()->service();
            $this->generateSeasonFixtures($database, $matchService, $competitionIds, $season);

            $performanceProbe = $performancePath === '' ? null : new CareerPerformanceProbe($this->services, dirname(__DIR__, 3));
            $performanceStates = [];
            if ($performanceProbe !== null) {
                $performanceStates['AGE0'] = $performanceProbe->capture(
                    $database,
                    $store,
                    self::SAVE_ID,
                    $directory . '/' . self::SAVE_ID . '.sqlite',
                    $players['fringe']->id()->value(),
                    0,
                );
            }

            $seasonReports = [];
            $saveSizes = ['initial' => filesize($directory . '/' . self::SAVE_ID . '.sqlite') ?: 0];
            $reloadChecks = [];
            $movement = $this->services->transferModule()->service()->careerMovement();
            $movementMetrics = ['checkpoints' => 0, 'generated' => 0, 'accepted' => 0, 'completed' => 0, 'duplicates' => 0, 'destination' => null];

            for ($seasonNumber = 1; $seasonNumber <= $requested; ++$seasonNumber) {
                $season = $this->services->worldModule()->service()->seasonRepository($database)->get($season->id());
                $matches = $this->matchesForSeason($database, $competitionIds, $season);
                if ($matches === []) { throw new RuntimeException('No fixtures exist for ' . $season->id()->value() . '.'); }
                $this->simulateSeason($store, $database, $matchService, $matches, $season, $reloadChecks);

                if ($seasonNumber === 1 && !$performanceMode) { $movementMetrics = $this->movementAudit($database, $season, $players, $movement); }

                $database = $this->advanceAndReload($store, $database, $season->endDate()->addDays(1), $reloadChecks, 'season-' . $seasonNumber . '-boundary');
                $season = $this->services->worldModule()->service()->seasonRepository($database)->get($season->id());
                $seasonReports[] = $this->seasonMetrics($database, $competitionIds, $season, $directory);
                $saveSizes['season_' . $seasonNumber] = filesize($directory . '/' . self::SAVE_ID . '.sqlite') ?: 0;
                if ($performanceProbe !== null && in_array($seasonNumber, [1, 3, 5], true)) {
                    $performanceStates['AGE' . $seasonNumber] = $performanceProbe->capture(
                        $database,
                        $store,
                        self::SAVE_ID,
                        $directory . '/' . self::SAVE_ID . '.sqlite',
                        $players['fringe']->id()->value(),
                        $seasonNumber,
                    );
                }

                if ($seasonNumber < $requested) {
                    $nextId = new SeasonId(sprintf('season-%04d-%02d', $season->startDate()->year() + 1, ($season->startDate()->year() + 2) % 100));
                    $next = $this->services->worldModule()->service()->seasonRepository($database)->get($nextId);
                    $database = $this->advanceAndReload($store, $database, $next->startDate(), $reloadChecks, 'season-' . ($seasonNumber + 1) . '-start');
                    $season = $this->services->worldModule()->service()->seasonRepository($database)->get($next->id());
                    if ($season->status()->value !== 'active') { throw new RuntimeException('Next Season did not activate: ' . $season->id()->value()); }
                }
            }

            $finalCompletedSeason = $seasonReports[count($seasonReports) - 1]['season'];
            $nextId = new SeasonId(sprintf('season-%04d-%02d', $finalCompletedSeason->startDate()->year() + 1, ($finalCompletedSeason->startDate()->year() + 2) % 100));
            $next = $this->services->worldModule()->service()->seasonRepository($database)->get($nextId);
            $database = $this->advanceAndReload($store, $database, $next->startDate(), $reloadChecks, 'final-season-start');
            $finalSeason = $this->services->worldModule()->service()->seasonRepository($database)->get($nextId);
            $saveSizes['final'] = filesize($directory . '/' . self::SAVE_ID . '.sqlite') ?: 0;
            $populationMetrics = $this->populationMetrics($database, $finalSeason, $population, $directory);
            $careerMetrics = $this->careerMetrics($database, $players, $finalSeason);
            $this->writeReport($output, $requested, $seed, $seasonReports, $populationMetrics, $careerMetrics, $movementMetrics, $saveSizes, $reloadChecks);
            if ($performanceProbe !== null) {
                $performanceDirectory = dirname($performancePath);
                if (!is_dir($performanceDirectory) && !mkdir($performanceDirectory, 0775, true) && !is_dir($performanceDirectory)) {
                    throw new RuntimeException('Unable to create performance report directory: ' . $performanceDirectory);
                }
                $report = [
                    'command' => 'career:multi-season-audit --performance-json',
                    'seed' => $seed,
                    'requested_seasons' => $requested,
                    'controlled_player' => $players['fringe']->id()->value(),
                    'states' => $performanceStates,
                ];
                if (file_put_contents($performancePath, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === false) {
                    throw new RuntimeException('Unable to write performance report: ' . $performancePath);
                }
                $output->write(sprintf('PERFORMANCE_GATE path=%s states=%s', $performancePath, implode(',', array_keys($performanceStates))));
                foreach ($performanceStates as $label => $state) {
                    $save = (array) ($state['save'] ?? []);
                    $output->write(sprintf('PERFORMANCE_STATE label=%s age=%d player_age=%d save_bytes=%d rows=%d relevant_rows=%d capture_ms=%.2f', $label, (int) ($state['career_age'] ?? 0), (int) ($state['player_age'] ?? 0), (int) ($save['bytes'] ?? 0), (int) ($save['total_rows'] ?? 0), (int) ($save['relevant_history_rows'] ?? 0), (float) ($state['capture_time_ms'] ?? 0.0)));
                }
            }

            return 0;
        } catch (Throwable $exception) {
            $output->error('Career multi-season audit failed: ' . $exception->getMessage());
            return 1;
        } finally {
            unset($database);
            $this->removeStorage($directory);
        }
    }

    /**
     * Run the same World/Match/Season lifecycle with compact controlled-player
     * checkpoints. This deliberately lives beside the older audit so the
     * observatory cannot drift into a second Career simulation.
     */
    private function executeObservatory(array $arguments, ConsoleOutputInterface $output): int
    {
        $requested = $this->argumentInt($arguments, '--seasons=', 5);
        $seed = $this->argumentInt($arguments, '--seed=', 13007);
        $requestedProfile = strtolower($this->argumentValue($arguments, '--archetype=', 'all'));
        $profiles = $requestedProfile === 'all'
            ? [DevelopmentProfile::LateBloomer, DevelopmentProfile::Regular, DevelopmentProfile::Prodigy]
            : [DevelopmentProfile::fromInput($requestedProfile)];
        if ($requested < 1 || $requested > 5) {
            $output->error('Career observatory requires --seasons between 1 and 5.');

            return 1;
        }
        if (in_array('--equivalence', $arguments, true) && count($profiles) !== 1) {
            $output->error('Career observatory equivalence accepts one --archetype.');

            return 1;
        }

        $overallStart = hrtime(true);
        try {
            foreach ($profiles as $profile) {
                // Each isolated save also gets a fresh core clock. The
                // production clock is intentionally monotonic, so reusing
                // one service graph would make the next profile look like a
                // time-traveling save rather than an independent sample.
                $profileServices = (new Bootstrap())->create(dirname(__DIR__, 3));
                (new self($profileServices))->runObservatoryProfile($profile, $seed, $requested, $arguments, $output);
            }
            $output->write(sprintf('OBSERVATORY_TOTAL runtime_ms=%.2f profiles=%d seasons=%d', $this->elapsedMilliseconds($overallStart), count($profiles), $requested));

            return 0;
        } catch (Throwable $exception) {
            $output->error('Career observatory failed: ' . $exception->getMessage());

            return 1;
        }
    }

    private function runObservatoryProfile(DevelopmentProfile $profile, int $seed, int $requested, array $arguments, ConsoleOutputInterface $output): void
    {
        $directory = sys_get_temp_dir() . '/goal-legacy-pacing-observatory-' . $profile->value . '-' . bin2hex(random_bytes(8));
        $database = null;
        $started = hrtime(true);
        try {
            [$store, $database, $season, $world] = $this->initialize($directory, $seed);
            $population = $this->services->playerModule()->service()->populationService()->populate($database, $season, $seed);
            $installation = $this->installObservatoryPlayer($database, $season, $profile, $seed);
            $player = $installation['player'];
            $competitionIds = [$installation['competition_id']];
            $matchService = $this->services->matchModule()->service();
            $this->generateSeasonFixtures($database, $matchService, $competitionIds, $season);
            $checkpoints = [];
            $checkpoints['START'] = $this->observatoryCheckpoint($database, $player, $season, $season->startDate());
            $seasonRuntimes = [];
            $worldAtStart = $this->services->worldModule()->service()->load($database, self::SAVE_ID)->toArray();

            for ($seasonNumber = 1; $seasonNumber <= $requested; ++$seasonNumber) {
                $season = $this->services->worldModule()->service()->seasonRepository($database)->get($season->id());
                $matches = $this->matchesForSeason($database, $competitionIds, $season);
                if ($matches === []) {
                    throw new RuntimeException('No fixtures exist for ' . $season->id()->value() . '.');
                }
                $seasonStart = hrtime(true);
                $materializedMatches = $this->simulateObservatorySeason($database, $matchService, $matches, $player, $season);
                $remaining = count(array_filter($this->allMatchesForSeason($database, $season), static fn ($match): bool => $match->status() !== MatchStatus::Completed));
                $output->write(sprintf('OBSERVATORY_BOUNDARY profile=%s season=%d matches=%d unresolved=%d', $profile->value, $seasonNumber, $materializedMatches, $remaining));
                $this->services->worldModule()->service()->advanceToDate($database, self::SAVE_ID, $season->endDate()->addDays(1));
                $season = $this->services->worldModule()->service()->seasonRepository($database)->get($season->id());
                $checkpoints['SEASON_' . $seasonNumber] = $this->observatoryCheckpoint($database, $player, $season, $season->endDate()->addDays(1));
                $seasonRuntimes[$seasonNumber] = $this->elapsedMilliseconds($seasonStart);

                if ($seasonNumber < $requested) {
                    $this->resolveObservatoryContractDecision($database, $player, $season->endDate()->addDays(1));
                    $nextId = new SeasonId(sprintf('season-%04d-%02d', $season->startDate()->year() + 1, ($season->startDate()->year() + 2) % 100));
                    $next = $this->services->worldModule()->service()->seasonRepository($database)->get($nextId);
                    $this->services->worldModule()->service()->advanceToDate($database, self::SAVE_ID, $next->startDate());
                    $season = $this->services->worldModule()->service()->seasonRepository($database)->get($next->id());
                    if ($season->status()->value !== 'active') {
                        throw new RuntimeException('Next Season did not activate: ' . $season->id()->value());
                    }
                }
            }

            $equivalence = 'not_requested';
            if (in_array('--equivalence', $arguments, true)) {
                $worldBeforeReload = $this->services->worldModule()->service()->load($database, self::SAVE_ID)->toArray();
                $snapshotBeforeReload = $this->observatoryCheckpoint($database, $player, $season, $season->startDate());
                unset($database);
                $database = $store->openDatabase(self::SAVE_ID);
                $worldAfterReload = $this->services->worldModule()->service()->load($database, self::SAVE_ID)->toArray();
                $snapshotAfterReload = $this->observatoryCheckpoint($database, $player, $season, $season->startDate());
                $equivalence = $worldBeforeReload === $worldAfterReload && $snapshotBeforeReload === $snapshotAfterReload ? 'pass' : 'fail';
                if ($equivalence !== 'pass') {
                    throw new RuntimeException('Observatory save/reload equivalence failed for ' . $profile->value . '.');
                }
            }

            $warnings = $this->observatoryWarnings($checkpoints);
            $output->write(sprintf('OBSERVATORY profile=%s seed=%d requested=%d runtime_ms=%.2f population=%d equivalence=%s', $profile->value, $seed, $requested, $this->elapsedMilliseconds($started), (int) ($population['players_total'] ?? 0), $equivalence));
            $output->write(sprintf('OBSERVATORY_WORLD start_hash=%s final_season=%s final_season_status=%s', substr(hash('sha256', json_encode($worldAtStart, JSON_THROW_ON_ERROR)), 0, 12), $season->id()->value(), $season->status()->value));
            foreach ($checkpoints as $label => $checkpoint) {
                $output->write($this->formatObservatoryCheckpoint($label, $checkpoint));
            }
            foreach ($seasonRuntimes as $number => $runtime) {
                $output->write(sprintf('OBSERVATORY_TIMING profile=%s season=%d runtime_ms=%.2f', $profile->value, $number, $runtime));
            }
            $output->write(sprintf('OBSERVATORY_WARNINGS profile=%s values=%s', $profile->value, $warnings === [] ? 'none' : implode(',', $warnings)));
        } finally {
            unset($database);
            $this->removeStorage($directory);
        }
    }

    /** @return array{player:Player,competition_id:string} */
    private function installObservatoryPlayer($database, Season $season, DevelopmentProfile $profile, int $seed): array
    {
        $playerService = $this->services->playerModule()->service();
        $start = new YouthCareerStartService(
            $playerService,
            $this->services->clubModule()->service(),
            $this->services->competitionModule()->service(),
            $this->services->contractModule()->service(),
            $this->services->playerFinanceService(),
        );
        $careerId = new CareerId('pacing-' . $profile->value . '-career');
        $startDate = SimulationDate::fromIsoString('2024-07-31');
        $player = $start->createProspect(new CareerStartRequest('pacing-' . $profile->value, 'Pacing ' . ucfirst($profile->value), 'england', 180, 75, 'CM', $profile->value, $seed));
        $opportunities = $start->opportunities($database, $player, $season);
        $selected = $opportunities[0] ?? null;
        if ($selected === null) {
            throw new RuntimeException('Youth Camp produced no observatory opportunity for ' . $profile->value . '.');
        }
        $clubId = (string) $selected['club_id'];
        $start->accept($database, $player, $careerId, $season, $startDate, $opportunities, $clubId);

        return ['player' => $player, 'competition_id' => (string) $selected['competition_id']];
    }

    /** @param list<object> $matches */
    private function simulateObservatorySeason($database, $matchService, array $matches, Player $player, Season $season): int
    {
        $trainingBlocks = $this->observatoryTrainingBlocks($season->startDate());
        $trainingIndex = 0;
        $lastDate = null;
        while (true) {
            $scheduled = array_values(array_filter(
                $this->allMatchesForSeason($database, $season),
                static fn ($match): bool => $match->status() === MatchStatus::Scheduled,
            ));
            if ($scheduled === []) {
                break;
            }
            $date = $scheduled[0]->scheduledDate();
            if ($lastDate !== null && !$date->isAfter($lastDate)) {
                throw new RuntimeException('Observatory could not advance scheduled Competition Matches.');
            }
            while (isset($trainingBlocks[$trainingIndex]) && !$trainingBlocks[$trainingIndex]['end']->isAfter($date)) {
                $block = $trainingBlocks[$trainingIndex];
                $this->services->playerModule()->service()->trainingService()->complete(
                    $database,
                    new TrainingRequest($player->id(), $season->id()->value() . ':' . $block['id'] . ':' . $player->id()->value(), 'balanced', $block['start'], $block['end']),
                );
                ++$trainingIndex;
            }
            $this->services->worldModule()->service()->advanceToDate($database, self::SAVE_ID, $date);
            $matchService->simulateDue($database, $date);
            $lastDate = $date;
        }

        return count($this->allMatchesForSeason($database, $season));
    }

    /** @return list<array{id:string,start:SimulationDate,end:SimulationDate}> */
    private function observatoryTrainingBlocks(SimulationDate $seasonStart): array
    {
        $blocks = [];
        for ($index = 0; $index < 10; ++$index) {
            $start = $seasonStart->addDays($index * 28);
            $blocks[] = ['id' => 'pacing-training-' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT), 'start' => $start, 'end' => $start->addDays(27)];
        }

        return $blocks;
    }

    private function resolveObservatoryContractDecision($database, Player $player, SimulationDate $date): void
    {
        $opportunities = (new CareerOpportunityRepository($database))->openForPlayer($player->id(), $date);
        foreach ($opportunities as $opportunity) {
            if ($opportunity->type()->value !== 'contract_renewal') {
                continue;
            }
            $options = is_array($opportunity->context()['options'] ?? null) ? $opportunity->context()['options'] : [];
            $selected = null;
            foreach ($options as $option) {
                if (is_array($option) && ($option['id'] ?? null) === 'renew-current-club') {
                    $selected = $option;
                    break;
                }
            }
            $selected ??= array_values(array_filter($options, static fn ($option): bool => is_array($option) && isset($option['id'])))[0] ?? null;
            if (is_array($selected)) {
                $decisionDate = $date;
                $currentContractId = $opportunity->context()['current_contract_id'] ?? null;
                if (is_string($currentContractId) && $currentContractId !== '') {
                    foreach ($this->services->contractModule()->service()->repository($database)->byPlayer($player->id()) as $contract) {
                        if ($contract->id()->value() === $currentContractId && !$contract->endDate()->isBefore($decisionDate)) {
                            $decisionDate = $contract->endDate()->addDays(1);
                            $this->services->worldModule()->service()->advanceToDate($database, self::SAVE_ID, $decisionDate);
                            break;
                        }
                    }
                }
                $this->services->transferModule()->service()->careerMovement()->resolveContractDecision($database, $opportunity->id(), (string) $selected['id'], $decisionDate);
            }
        }
    }

    /** @return array<string, mixed> */
    private function observatoryCheckpoint($database, Player $player, Season $season, SimulationDate $date): array
    {
        $players = new PlayerRepository($database);
        $current = $players->get($player->id());
        $membership = $this->services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $season->id())[0] ?? null;
        $club = $membership === null ? null : $this->services->clubModule()->service()->repository($database)->get($membership->clubId());
        $query = new PlayerCareerProgressionQuery($this->services->clubModule()->service());
        $summary = $query->summary($database, $player->id(), $date, $season->id());
        $stats = is_array($summary['season_stats'] ?? null) ? $summary['season_stats'] : [];
        $contract = is_array($summary['current_contract'] ?? null) ? $summary['current_contract'] : null;
        $contracts = $this->services->contractModule()->service()->repository($database)->byPlayer($player->id());
        $transfers = array_values(array_filter((new TransferRepository($database))->byPlayer($player->id()), static fn ($transfer): bool => $transfer->status()->value === 'completed'));
        $loans = (new LoanRepository($database, false))->byPlayer($player->id());
        $injuries = (new PlayerAvailabilityRepository($database))->byPlayer($player->id());
        $legacy = new CareerLegacyRepository($database, false);
        $finance = $this->services->playerFinanceService()->summary($database, $player->id(), $date);
        $competition = is_array($summary['current_competition'] ?? null) ? $summary['current_competition'] : null;
        if ($competition === null && $club !== null) {
            foreach ($this->services->clubModule()->service()->membershipRepository($database)->bySeason($season->id()) as $clubMembership) {
                if ($clubMembership->clubId()->value() !== $club->id()->value()) {
                    continue;
                }
                $competitionRecord = $this->services->competitionModule()->service()->repository($database)->get($clubMembership->competitionId());
                $competition = ['name' => $competitionRecord->name(), 'tier' => $competitionRecord->tier()];
                break;
            }
        }
        $outlook = is_array($summary['career_outlook'] ?? null) ? $summary['career_outlook']['category'] ?? null : null;
        $attributes = $current->attributes()->toArray();
        $term = $contract === null ? null : $date->daysUntil(SimulationDate::fromIsoString((string) ($contract['end_date'] ?? $date->toIsoString())));

        return [
            'season' => $season->id()->value(),
            'season_label' => $season->label(),
            'age' => $current->ageAt($date),
            'club' => $club?->canonicalName() ?? 'free-agent',
            'competition' => $competition['name'] ?? null,
            'tier' => $competition['tier'] ?? null,
            'ovr' => $current->overallRating(),
            'attributes' => $attributes,
            'potential' => $current->potential(),
            'role' => $membership?->role()->value ?? 'none',
            'appearances' => (int) ($stats['appearances'] ?? 0),
            'starts' => (int) ($stats['starts'] ?? 0),
            'minutes' => (int) ($stats['minutes'] ?? 0),
            'goals' => (int) ($stats['goals'] ?? 0),
            'assists' => (int) ($stats['assists'] ?? 0),
            'rating' => $stats['average_match_rating'] ?? null,
            'availability' => $summary['availability'] ?? null,
            'fatigue' => $summary['fatigue'] ?? 0,
            'injuries' => count($injuries),
            'contract' => $contract['status'] ?? 'none',
            'contract_days' => $term,
            'wage' => $contract['wage'] ?? ($finance['current_wage'] ?? null),
            'contracts' => count($contracts),
            'transfers' => count($transfers),
            'loans' => count($loans),
            'honours' => count($legacy->honoursForPlayer($player->id()->value())),
            'awards' => count($legacy->awardsForPlayer($player->id()->value())),
            'outlook' => $outlook,
            'retirement' => $summary['retirement'] !== null,
            'career_state' => $current->careerState()->value,
        ];
    }

    /** @param array<string, array<string, mixed>> $checkpoints @return list<string> */
    private function observatoryWarnings(array $checkpoints): array
    {
        $warnings = [];
        foreach ($checkpoints as $label => $checkpoint) {
            if (str_starts_with($label, 'SEASON_') && $checkpoint['ovr'] >= $checkpoint['potential'] && $label !== 'SEASON_5') {
                $warnings[] = 'POTENTIAL_REACHED_EARLY';
            }
        }
        $seasonCheckpoints = array_values(array_filter($checkpoints, static fn (string $label): bool => str_starts_with($label, 'SEASON_'), ARRAY_FILTER_USE_KEY));
        if (count($seasonCheckpoints) >= 3) {
            $latest = $seasonCheckpoints[array_key_last($seasonCheckpoints)];
            if ((int) $latest['minutes'] < 900 && in_array($latest['role'], ['prospect', 'rotation'], true)) {
                $warnings[] = 'MINUTES_TRAP';
            }
        }

        return array_values(array_unique($warnings));
    }

    /** @param array<string, mixed> $checkpoint */
    private function formatObservatoryCheckpoint(string $label, array $checkpoint): string
    {
        $attributes = $checkpoint['attributes'];

        return sprintf(
            'CHECKPOINT label=%s season=%s age=%d club=%s competition=%s tier=%s ovr=%d attrs=%d/%d/%d/%d/%d/%d potential=%d role=%s apps=%d starts=%d minutes=%d goals=%d assists=%d rating=%s availability=%s fatigue=%d injuries=%d contract=%s contract_days=%s wage=%s contracts=%d transfers=%d loans=%d honours=%d awards=%d outlook=%s retirement=%s state=%s',
            $label,
            $checkpoint['season_label'],
            $checkpoint['age'],
            $checkpoint['club'],
            $checkpoint['competition'] ?? 'none',
            $checkpoint['tier'] ?? 'none',
            $checkpoint['ovr'],
            $attributes['pace'],
            $attributes['shooting'],
            $attributes['passing'],
            $attributes['dribbling'],
            $attributes['defending'],
            $attributes['physicality'],
            $checkpoint['potential'],
            $checkpoint['role'],
            $checkpoint['appearances'],
            $checkpoint['starts'],
            $checkpoint['minutes'],
            $checkpoint['goals'],
            $checkpoint['assists'],
            $checkpoint['rating'] ?? 'none',
            $checkpoint['availability'] ?? 'unknown',
            $checkpoint['fatigue'],
            $checkpoint['injuries'],
            $checkpoint['contract'],
            $checkpoint['contract_days'] ?? 'none',
            $checkpoint['wage'] ?? 'none',
            $checkpoint['contracts'],
            $checkpoint['transfers'],
            $checkpoint['loans'],
            $checkpoint['honours'],
            $checkpoint['awards'],
            $checkpoint['outlook'] ?? 'none',
            $checkpoint['retirement'] ? 'eligible' : 'not_eligible',
            $checkpoint['career_state'],
        );
    }

    private function argumentValue(array $arguments, string $prefix, string $default): string
    {
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, $prefix)) {
                return (string) substr($argument, strlen($prefix));
            }
        }

        return $default;
    }

    private function elapsedMilliseconds(int $started): float
    {
        return round((hrtime(true) - $started) / 1_000_000, 2);
    }

    private function executeLifecycleOnly($store, $database, World $world, Season $season, int $requested, int $seed, string $directory, ConsoleOutputInterface $output, ?SqlProfiler $profiler = null): int
    {
            $reports = [];
        $reloadChecks = [];
        for ($number = 1; $number <= $requested; ++$number) {
            $output->write(sprintf('LIFECYCLE_SEASON_START number=%d/%d season=%s phase=complete_and_rollover', $number, $requested, $season->id()->value()));
            $seasonStart = hrtime(true);
            // Rollover materializes the next Season's fixtures. Resolve the
            // current Season's scheduled World-fidelity matches before asking
            // WorldService to close it, otherwise a lifecycle-only audit can
            // strand a generated competition and report a false rollover
            // failure.
            $matchService = $this->services->matchModule()->service();
            do {
                $completed = $matchService->simulateDue($database, $season->endDate());
            } while ($completed !== []);
            $this->services->worldModule()->service()->advanceToDate($database, self::SAVE_ID, $season->endDate()->addDays(1));
            $boundaryMilliseconds = round((hrtime(true) - $seasonStart) / 1_000_000, 2);
            $nextId = new SeasonId(sprintf('season-%04d-%02d', $season->startDate()->year() + 1, ($season->startDate()->year() + 2) % 100));
            $next = $this->services->worldModule()->service()->seasonRepository($database)->get($nextId);
            $reloadStart = hrtime(true);
            $database = $this->advanceAndReload($store, $database, $next->startDate(), $reloadChecks, 'lifecycle-season-' . $number, $profiler);
            $reloadMilliseconds = round((hrtime(true) - $reloadStart) / 1_000_000, 2);
            $season = $this->services->worldModule()->service()->seasonRepository($database)->get($nextId);
            $reports[] = $this->lifecycleMetrics($database, $season, $directory);
            $phases = $this->services->worldModule()->service()->seasonRollover()?->lastPhaseTimings() ?? [];
            $materializeMilliseconds = array_sum(array_intersect_key($phases, array_flip(['promotion_standings_ms', 'membership_materialization_ms', 'recruitment_ms', 'newgens_ms', 'replenishment_ms', 'fixture_generation_ms'])));
            $output->write(sprintf('LIFECYCLE_PHASE number=%d boundary_ms=%.2f player_lifecycle_ms=%.2f contract_squad_ms=%.2f materialize_ms=%.2f reload_ms=%.2f promotion_ms=%.2f membership_ms=%.2f recruitment_ms=%.2f newgens_ms=%.2f replenish_ms=%.2f fixtures_ms=%.2f registration_ms=%.2f', $number, $boundaryMilliseconds, $phases['player_lifecycle_ms'] ?? 0.0, $phases['contract_squad_continuity_ms'] ?? 0.0, $materializeMilliseconds, $reloadMilliseconds, $phases['promotion_standings_ms'] ?? 0.0, $phases['membership_materialization_ms'] ?? 0.0, $phases['recruitment_ms'] ?? 0.0, $phases['newgens_ms'] ?? 0.0, $phases['replenishment_ms'] ?? 0.0, $phases['fixture_generation_ms'] ?? 0.0, $phases['registration_activation_ms'] ?? 0.0));
        }
        $last = $reports[count($reports) - 1];
            $output->write(sprintf('LIFECYCLE_AUDIT seed=%d requested=%d completed=%d start=%s end=%s active_start=%d active_final=%d active_free_final=%d contracted_squad_final=%d retired_final=%d newgens_final=%d records_final=%d avg_age_final=%.1f oldest_final=%d squad_min=%d squad_max=%d clubs_above_25=%d reload_failures=%s', $seed, $requested, count($reports), $reports[0]['season']->startDate()->toIsoString(), $last['season']->startDate()->toIsoString(), $reports[0]['active'], $last['active'], $last['active_free'], $last['contracted_squad'], $last['retired'], $last['newgens'], $last['records'], $last['age_avg'], $last['oldest'], $last['squad_min'], $last['squad_max'], $last['clubs_above_25'], $reloadChecks === [] || count(array_filter($reloadChecks, static fn (bool $value): bool => !$value)) === 0 ? 'none' : 'present'));
        foreach ($reports as $index => $report) {
            $output->write(sprintf('LIFECYCLE_SEASON_%d season=%s promoted=%d relegated=%d active=%d active_free=%d contracted_squad=%d retired=%d newgens=%d renewals=%d releases=%d free_signings=%d npc_transfers=%d movement_budget=%d candidates=%d clubs_active=%d newgens_avoided=%d cross_league=%d upward=%d lateral=%d downward=%d records=%d unclubbed_active=%d squad_slots=%d below25=%d at25=%d above25=%d avg_age=%.1f oldest=%d squad_avg=%.1f squad_min=%d squad_max=%d save_size=%d', $index + 1, $report['season']->id()->value(), $report['promoted'], $report['relegated'], $report['active'], $report['active_free'], $report['contracted_squad'], $report['retired'], $report['newgens'], $report['renewed'], $report['released'], $report['free_agent_signings'], $report['npc_transfers'], $report['movement_budget'], $report['candidates_evaluated'], $report['clubs_with_activity'], $report['newgens_avoided'], $report['cross_league'], $report['upward'], $report['lateral'], $report['downward'], $report['records'], $report['unclubbed_active'], $report['squad_slots'], $report['clubs_below_25'], $report['clubs_at_25'], $report['clubs_above_25'], $report['age_avg'], $report['oldest'], $report['squad_avg'], $report['squad_min'], $report['squad_max'], $report['save_size']));
        }
        if ($profiler !== null) {
            (new SqliteQueryPlanExplainer())->explain($database->connection(), $profiler, 10);
            $reporter = new SqlProfileReporter();
            foreach (explode("\n", $reporter->text($profiler, 10)) as $line) {
                $output->write($line);
            }
        }

        return 0;
    }

    private function lifecycleMetrics($database, Season $season, string $directory): array
    {
        $players = (new PlayerRepository($database))->all();
        $active = array_values(array_filter($players, static fn (Player $player): bool => $player->careerState() === PlayerCareerState::Active));
        $retired = count($players) - count($active);
        $newgens = count(array_filter($players, static fn (Player $player): bool => str_contains($player->id()->value(), '-newgen-')));
        $squads = $this->squadsBySeason($database, $season);
        $sizes = [];
        foreach ($squads as $membership) { $sizes[$membership->clubId()->value()] = ($sizes[$membership->clubId()->value()] ?? 0) + 1; }
        $squadPlayerIds = array_fill_keys(array_map(static fn (ClubSquadMembership $membership): string => $membership->playerId()->value(), $squads), true);
        $contracts = $this->services->contractModule()->service()->repository($database)->all();
        $activeContractPlayerIds = [];
        foreach ($contracts as $contract) {
            if ($contract->status() === ContractStatus::Active) {
                $activeContractPlayerIds[$contract->playerId()->value()] = true;
            }
        }
        $activeFree = count(array_filter($active, static fn (Player $player): bool => !isset($squadPlayerIds[$player->id()->value()]) && !isset($activeContractPlayerIds[$player->id()->value()])));
        $contractedSquadPlayers = count(array_filter(array_keys($squadPlayerIds), static fn (string $playerId): bool => isset($activeContractPlayerIds[$playerId])));
        $ages = array_map(static fn (Player $player): int => $player->ageAt($season->startDate()), $active);

        $recruitment = $this->services->worldModule()->service()->seasonRollover()?->lastRecruitment() ?? ['free_agent_signings' => 0, 'npc_transfers' => 0, 'newgens_avoided' => 0, 'movement_budget' => 0, 'candidates_evaluated' => 0, 'clubs_with_activity' => 0];
        $lifecycle = $this->services->worldModule()->service()->seasonRollover()?->lastLifecycle() ?? ['renewed' => 0, 'released' => 0, 'carried' => 0];
        $movement = $this->services->worldModule()->service()->seasonRollover()?->lastMovement() ?? ['promoted' => [], 'relegated' => []];
        $transfers = $this->transferMetrics($database, $season);
        return ['season' => $season, 'active' => count($active), 'retired' => $retired, 'newgens' => $newgens, 'renewed' => $lifecycle['renewed'], 'released' => $lifecycle['released'], 'free_agent_signings' => $recruitment['free_agent_signings'], 'npc_transfers' => $recruitment['npc_transfers'], 'movement_budget' => $recruitment['movement_budget'], 'candidates_evaluated' => $recruitment['candidates_evaluated'], 'clubs_with_activity' => $recruitment['clubs_with_activity'], 'newgens_avoided' => $recruitment['newgens_avoided'], 'promoted' => count($movement['promoted']), 'relegated' => count($movement['relegated']), 'cross_league' => $transfers['cross_league'], 'upward' => $transfers['upward'], 'lateral' => $transfers['lateral'], 'downward' => $transfers['downward'], 'records' => count($players), 'unclubbed_active' => count(array_filter($active, static fn (Player $player): bool => !isset($squadPlayerIds[$player->id()->value()]))), 'active_free' => $activeFree, 'contracted_squad' => $contractedSquadPlayers, 'squad_slots' => count($squads), 'clubs_below_25' => count(array_filter($sizes, static fn (int $size): bool => $size < PlayerPopulationService::TARGET_SQUAD_SIZE)), 'clubs_at_25' => count(array_filter($sizes, static fn (int $size): bool => $size === PlayerPopulationService::TARGET_SQUAD_SIZE)), 'clubs_above_25' => count(array_filter($sizes, static fn (int $size): bool => $size > PlayerPopulationService::TARGET_SQUAD_SIZE)), 'age_avg' => $ages === [] ? 0.0 : round(array_sum($ages) / count($ages), 1), 'oldest' => $ages === [] ? 0 : max($ages), 'squad_avg' => $sizes === [] ? 0.0 : round(array_sum($sizes) / count($sizes), 1), 'squad_min' => $sizes === [] ? 0 : min($sizes), 'squad_max' => $sizes === [] ? 0 : max($sizes), 'save_size' => filesize($directory . '/' . self::SAVE_ID . '.sqlite') ?: 0];
    }

    /** @return array{cross_league:int,upward:int,lateral:int,downward:int} */
    private function transferMetrics($database, Season $season): array
    {
        $clubs = [];
        foreach ($this->services->clubModule()->service()->repository($database)->all() as $club) {
            $clubs[$club->id()->value()] = $club;
        }
        $competitions = [];
        foreach ($this->services->clubModule()->service()->membershipRepository($database)->bySeason($season->id()) as $membership) {
            $competitions[$membership->clubId()->value()] = $membership->competitionId()->value();
        }
        $metrics = ['cross_league' => 0, 'upward' => 0, 'lateral' => 0, 'downward' => 0];
        foreach ((new TransferRepository($database))->all() as $transfer) {
            if ($transfer->seasonId()->value() !== $season->id()->value() || $transfer->status()->value !== 'completed') {
                continue;
            }
            if (($competitions[$transfer->sourceClubId()->value()] ?? null) !== ($competitions[$transfer->destinationClubId()->value()] ?? null)) {
                ++$metrics['cross_league'];
            }
            $source = $clubs[$transfer->sourceClubId()->value()] ?? null;
            $destination = $clubs[$transfer->destinationClubId()->value()] ?? null;
            if ($source === null || $destination === null) {
                continue;
            }
            if ($destination->reputation() > $source->reputation() + 5) {
                ++$metrics['upward'];
            } elseif ($source->reputation() > $destination->reputation() + 5) {
                ++$metrics['downward'];
            } else {
                ++$metrics['lateral'];
            }
        }

        return $metrics;
    }

    /** @return array{0: SqliteSaveStore, 1: \Goal\Legacy\Core\Persistence\DatabaseInterface, 2: Season, 3: World} */
    private function initialize(string $directory, int $seed): array
    {
        $nations = $this->services->nationModule()->service()->loadSelected();
        $competitions = $this->services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId(self::SEASON_ID), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $this->services->worldModule()->service()->calendar();
        $world = new World(new WorldId(self::SAVE_ID), 'Career multi-season audit', $seed, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $this->services->contentPackages()->selectedIds());
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create(self::SAVE_ID, $world->label(), $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase(self::SAVE_ID);
        $this->services->worldModule()->service()->initialize($database, $world, $season);

        return [$store, $database, $season, $world];
    }

    /** @return array<string, Player> */
    private function installControlledPlayers($database, Season $season): array
    {
        $definitions = [
            ['fringe', 'arsenal', 70, 92, SquadRole::Prospect],
            ['breakout', 'ipswich-town', 80, 94, SquadRole::Regular],
            ['stayer', 'arsenal', 75, 88, SquadRole::Regular],
            ['weak', 'arsenal', 42, 46, SquadRole::Prospect],
        ];
        $players = [];
        $playerService = $this->services->playerModule()->service();
        $contracts = $this->services->contractModule()->service();
        $registrations = $this->services->competitionModule()->service()->registrationRepository($database);
        foreach ($definitions as [$key, $club, $attribute, $potential, $role]) {
            $id = 'audit-' . $key;
            $player = $playerService->create(new PlayerCreationRequest($id, 'Audit', ucfirst($key), 'Audit ' . ucfirst($key), '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', $potential, 'regular', 13003, new PlayerAttributeSet($attribute, $attribute, $attribute, $attribute, $attribute, $attribute)));
            $playerService->initializeCareer($database, $player, new CareerPlayerReference(new CareerId($id . '-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId($club), $player->id(), $season->id(), $role));
            $contracts->save($database, $contracts->create(new ContractCreationRequest(new ContractId($id . '-contract'), $player->id(), new ClubId($club), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2030-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
            $registrations->register(new PlayerRegistration($season->id(), new CompetitionId(self::CAREER_COMPETITION), new ClubId($club), $player->id()));
            $players[$key] = $player;
        }

        return $players;
    }

    /** Install one real save-scoped Career for production WebApplication reads. */
    private function installPerformancePlayer($database, Season $season, int $seed): Player
    {
        $playerService = $this->services->playerModule()->service();
        $player = $playerService->create(new PlayerCreationRequest(
            'p3020-player',
            'P3-020',
            'Performance Player',
            'P3-020 Performance Player',
            '2005-01-01',
            'england',
            [],
            'england',
            ['england'],
            180,
            75,
            'CM',
            92,
            'regular',
            $seed,
            new PlayerAttributeSet(70, 70, 70, 70, 70, 70),
        ));
        $clubId = new ClubId('arsenal');
        $membership = new ClubSquadMembership($clubId, $player->id(), $season->id(), SquadRole::Prospect);
        $playerService->initializeCareer($database, $player, new CareerPlayerReference(new CareerId(self::SAVE_ID), $player->id(), SimulationDate::fromIsoString('2024-07-31')), $membership);
        $contracts = $this->services->contractModule()->service();
        $contracts->save($database, $contracts->create(new ContractCreationRequest(new ContractId('p3020-player-contract'), $player->id(), $clubId, SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2030-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        $this->services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId(self::CAREER_COMPETITION), $clubId, $player->id()));

        return $player;
    }

    /** @param list<string> $competitionIds */
    private function generateSeasonFixtures($database, $matchService, array $competitionIds, Season $season): void
    {
        $matchService->generateSeasonFixtures($database, $competitionIds, $season->id());
    }

    /** @param list<string> $competitionIds @return list<object> */
    private function matchesForSeason($database, array $competitionIds, Season $season): array
    {
        $repository = new MatchRepository($database);
        $matches = [];
        foreach ($competitionIds as $competitionId) { $matches = array_merge($matches, $repository->byCompetition($competitionId, $season->id())); }
        usort($matches, static fn ($left, $right): int => $left->scheduledDate()->compareTo($right->scheduledDate()) ?: strcmp($left->id()->value(), $right->id()->value()));
        return $matches;
    }

    /** @return list<object> */
    private function allMatchesForSeason($database, Season $season): array
    {
        $matches = array_values(array_filter(
            (new MatchRepository($database))->all(),
            static fn ($match): bool => $match->seasonId()->value() === $season->id()->value(),
        ));
        usort($matches, static fn ($left, $right): int => $left->scheduledDate()->compareTo($right->scheduledDate()) ?: strcmp($left->id()->value(), $right->id()->value()));

        return $matches;
    }

    /** @param list<object> $matches @param list<string> $reloadChecks */
    private function simulateSeason($store, &$database, $matchService, array $matches, Season $season, array &$reloadChecks): void
    {
        // Season rollover validates every scheduled competition owned by the
        // World, not only the primary league passed by the audit caller. Cup
        // and European rounds are also scheduled lazily after their preceding
        // round completes, so refresh the canonical scheduled set after every
        // Match date rather than taking a one-time league-only date snapshot.
        $initialScheduled = array_values(array_filter(
            $this->allMatchesForSeason($database, $season),
            static fn ($match): bool => $match->status() === MatchStatus::Scheduled,
        ));
        $midpoint = max(1, intdiv(count($initialScheduled), 2));
        $processedDates = 0;
        $reloaded = false;
        while (true) {
            $scheduled = array_values(array_filter(
                $this->allMatchesForSeason($database, $season),
                static fn ($match): bool => $match->status() === MatchStatus::Scheduled,
            ));
            if ($scheduled === []) { break; }
            usort($scheduled, static fn ($left, $right): int => $left->scheduledDate()->compareTo($right->scheduledDate()) ?: strcmp($left->id()->value(), $right->id()->value()));
            $date = $scheduled[0]->scheduledDate();
            $this->services->worldModule()->service()->advanceToDate($database, self::SAVE_ID, $date);
            $matchService->simulateDue($database, $date);
            ++$processedDates;
            if (!$reloaded && $processedDates >= $midpoint) {
                $database = $this->advanceAndReload($store, $database, $date, $reloadChecks, $season->id()->value() . '-midseason');
                $reloaded = true;
            }
        }
    }

    private function advanceAndReload($store, $database, SimulationDate $date, array &$reloadChecks, string $label, ?SqlProfiler $profiler = null)
    {
        $this->services->worldModule()->service()->advanceToDate($database, self::SAVE_ID, $date);
        $worldAfter = $this->services->worldModule()->service()->load($database, self::SAVE_ID);
        unset($database);
        $database = $store->openDatabase(self::SAVE_ID, $profiler);
        $worldReloaded = $this->services->worldModule()->service()->load($database, self::SAVE_ID);
        $reloadChecks[$label] = $worldAfter->toArray() === $worldReloaded->toArray();
        return $database;
    }

    /** @param list<string> $competitionIds */
    private function seasonMetrics($database, array $competitionIds, Season $season, string $directory): array
    {
        $matches = $this->matchesForSeason($database, $competitionIds, $season);
        $completed = array_values(array_filter($matches, static fn ($match): bool => $match->status() === MatchStatus::Completed));
        $goals = array_sum(array_map(static fn ($match): int => ($match->result()?->homeGoals() ?? 0) + ($match->result()?->awayGoals() ?? 0), $completed));
        $homeWins = $draws = $awayWins = 0;
        foreach ($completed as $match) {
            $home = $match->result()?->homeGoals() ?? 0; $away = $match->result()?->awayGoals() ?? 0;
            if ($home === $away) { ++$draws; } elseif ($home > $away) { ++$homeWins; } else { ++$awayWins; }
        }
        $standings = $this->services->matchModule()->service()->standings($database, self::CAREER_COMPETITION, $season->id());
        $squads = $this->squadsBySeason($database, $season);
        $sizes = [];
        foreach ($squads as $membership) { $sizes[$membership->clubId()->value()] = ($sizes[$membership->clubId()->value()] ?? 0) + 1; }
        $contracts = $this->services->contractModule()->service()->repository($database)->all();
        $players = (new PlayerRepository($database))->all();
        $ages = array_map(static fn (Player $player): int => $player->ageAt($season->endDate()), $players);
        $ovrs = array_map(static fn (Player $player): int => $player->overallRating(), $players);
        $squadPlayerIds = array_fill_keys(array_map(static fn (ClubSquadMembership $membership): string => $membership->playerId()->value(), $squads), true);
        $active = array_values(array_filter($players, static fn (Player $player): bool => $player->careerState() === PlayerCareerState::Active));
        $retired = array_values(array_filter($players, static fn (Player $player): bool => $player->careerState() === PlayerCareerState::Retired));
        $newgens = array_values(array_filter($players, static fn (Player $player): bool => str_contains($player->id()->value(), '-newgen-')));
        $unclubbedActive = count(array_filter($active, static fn (Player $player): bool => !isset($squadPlayerIds[$player->id()->value()])));
        return ['season' => $season, 'matches' => count($matches), 'completed' => count($completed), 'goals' => $goals, 'champion' => $standings[0]['club_id'] ?? 'none', 'bottom' => $standings[count($standings) - 1]['club_id'] ?? 'none', 'spread' => ((int) ($standings[0]['points'] ?? 0)) - ((int) ($standings[count($standings) - 1]['points'] ?? 0)), 'home_wins' => $homeWins, 'draws' => $draws, 'away_wins' => $awayWins, 'squad_avg' => $sizes === [] ? 0.0 : round(array_sum($sizes) / count($sizes), 1), 'squad_min' => $sizes === [] ? 0 : min($sizes), 'squad_max' => $sizes === [] ? 0 : max($sizes), 'clubs_below_25' => count(array_filter($sizes, static fn (int $size): bool => $size < 25)), 'registrations' => count($this->services->competitionModule()->service()->registrationRepository($database)->byCompetition(self::CAREER_COMPETITION, $season->id())), 'players' => count($players), 'active_players' => count($active), 'retired_players' => count($retired), 'newgens' => count($newgens), 'unclubbed_active' => $unclubbedActive, 'ovr_avg' => $ovrs === [] ? 0.0 : round(array_sum($ovrs) / count($ovrs), 1), 'ovr_90_plus' => count(array_filter($ovrs, static fn (int $ovr): bool => $ovr >= 90)), 'age_avg' => $ages === [] ? 0.0 : round(array_sum($ages) / count($ages), 1), 'oldest' => $ages === [] ? 0 : max($ages), 'age_35_plus' => count(array_filter($ages, static fn (int $age): bool => $age >= 35)), 'contracts_active' => count(array_filter($contracts, static fn ($contract): bool => $contract->status() === ContractStatus::Active)), 'save_size' => filesize($directory . '/' . self::SAVE_ID . '.sqlite') ?: 0];
    }

    private function populationMetrics($database, Season $season, array $initialPopulation, string $directory): array
    {
        $players = (new PlayerRepository($database))->all();
        $contracts = $this->services->contractModule()->service()->repository($database)->all();
        $squads = $this->squadsBySeason($database, $season);
        $byPlayer = [];
        foreach ($contracts as $contract) { $byPlayer[$contract->playerId()->value()][] = $contract; }
        $withoutContract = 0;
        foreach ($players as $player) { if (array_filter($byPlayer[$player->id()->value()] ?? [], static fn ($contract): bool => $contract->status() === ContractStatus::Active) === []) { ++$withoutContract; } }
        $clubbed = array_unique(array_map(static fn ($membership): string => $membership->playerId()->value(), $squads));
        $clubbedWithoutContract = count(array_filter($clubbed, static fn (string $playerId): bool => array_filter($byPlayer[$playerId] ?? [], static fn ($contract): bool => $contract->status() === ContractStatus::Active) === []));
        $active = array_values(array_filter($players, static fn (Player $player): bool => $player->careerState() === PlayerCareerState::Active));
        return ['initial' => (int) ($initialPopulation['players_total'] ?? 0), 'final' => count($players), 'active' => count($active), 'retired' => count($players) - count($active), 'created' => count($players) - (int) ($initialPopulation['players_total'] ?? 0), 'unclubbed' => count($players) - count($clubbed), 'unclubbed_active' => count(array_filter($active, static fn (Player $player): bool => !in_array($player->id()->value(), $clubbed, true))), 'without_contract' => $withoutContract, 'clubbed_without_contract' => $clubbedWithoutContract, 'active_contracts' => count(array_filter($contracts, static fn ($contract): bool => $contract->status() === ContractStatus::Active)), 'expired_contracts' => count(array_filter($contracts, static fn ($contract): bool => $contract->status() === ContractStatus::Expired)), 'save_size' => filesize($directory . '/' . self::SAVE_ID . '.sqlite') ?: 0];
    }

    /** @param array<string, Player> $players */
    private function careerMetrics($database, array $players, Season $season): array
    {
        $stats = new PlayerMatchStatRepository($database); $selections = new MatchSelectionRepository($database); $squads = $this->services->clubModule()->service()->squadRepository($database); $repository = new PlayerRepository($database); $career = [];
        foreach ($players as $key => $player) {
            $appeared = array_values(array_filter($stats->byPlayer($player->id()), static fn ($stat): bool => $stat->appeared()));
            $selectionRows = $selections->byPlayer($player->id()); $membership = $squads->byPlayer($player->id(), $season->id())[0] ?? null;
            $career[$key] = ['club' => $membership?->clubId()->value() ?? 'none', 'role' => $membership?->role()->value ?? 'none', 'ovr' => $repository->get($player->id())->overallRating(), 'starts' => count(array_filter($appeared, static fn ($stat): bool => $stat->started())), 'appearances' => count($appeared), 'minutes' => array_sum(array_map(static fn ($stat): int => $stat->minutes(), $appeared)), 'bench' => count(array_filter($selectionRows, static fn ($selection): bool => $selection->status()->value === 'bench'))];
        }
        return $career;
    }

    /** @return list<ClubSquadMembership> */
    private function squadsBySeason($database, Season $season): array
    {
        return array_values(array_filter($this->services->clubModule()->service()->squadRepository($database)->all(), static fn (ClubSquadMembership $membership): bool => $membership->seasonId()->value() === $season->id()->value()));
    }

    /** @param array<string, Player> $players */
    private function movementAudit($database, Season $season, array $players, $movement): array
    {
        $checkpoints = [SimulationDate::fromIsoString('2025-04-01'), SimulationDate::fromIsoString('2025-05-01'), SimulationDate::fromIsoString('2025-05-15')]; $generated = $accepted = 0;
        foreach ($checkpoints as $date) { foreach (['fringe', 'breakout', 'weak'] as $key) { $generated += count($movement->evaluate($database, $players[$key]->id(), $season->id(), $date)); } }
        $open = $movement->openOffers($database, $players['fringe']->id(), SimulationDate::fromIsoString('2025-05-15')); $destination = null;
        if ($open !== []) { $resolved = $movement->accept($database, $open[0]->id(), SimulationDate::fromIsoString('2025-05-15')); $accepted = 1; $destination = $resolved->targetClubId()?->value(); }
        $transfers = (new TransferRepository($database))->byPlayer($players['fringe']->id()); $sourceKeys = $database->connection()->query("SELECT source_key FROM career_opportunities WHERE type = 'transfer_interest'")->fetchAll(\PDO::FETCH_COLUMN);
        return ['checkpoints' => count($checkpoints), 'generated' => $generated, 'accepted' => $accepted, 'completed' => count(array_filter($transfers, static fn ($transfer): bool => $transfer->status()->value === 'completed')), 'destination' => $destination, 'duplicates' => count($sourceKeys) - count(array_unique($sourceKeys))];
    }

    private function writeReport(ConsoleOutputInterface $output, int $requested, int $seed, array $seasons, array $population, array $career, array $movement, array $saveSizes, array $reloadChecks): void
    {
        $first = $seasons[0]['season']; $last = $seasons[count($seasons) - 1]['season']; $completedMatches = array_sum(array_map(static fn (array $row): int => $row['completed'], $seasons)); $endDate = $last->endDate()->addDays(1);
        $output->write(sprintf('MULTI_SEASON seed=%d requested=%d completed=%d scope=%s start=%s end=%s simulation_days=%d matches=%d', $seed, $requested, count($seasons), self::CAREER_COMPETITION, $first->startDate()->toIsoString(), $endDate->toIsoString(), $first->startDate()->daysUntil($endDate), $completedMatches));
        foreach ($seasons as $index => $metrics) { $number = $index + 1; $output->write(sprintf('SEASON_%d status=%s fixtures=%d completed=%d players=%d active=%d retired=%d newgens=%d unclubbed_active=%d squad_avg=%.1f squad_min=%d squad_max=%d below25=%d contracts_active=%d registrations=%d goals=%d goals_per_match=%.2f ovr_avg=%.1f age_avg=%.1f oldest=%d save_size=%d', $number, $metrics['season']->status()->value, $metrics['matches'], $metrics['completed'], $metrics['players'], $metrics['active_players'], $metrics['retired_players'], $metrics['newgens'], $metrics['unclubbed_active'], $metrics['squad_avg'], $metrics['squad_min'], $metrics['squad_max'], $metrics['clubs_below_25'], $metrics['contracts_active'], $metrics['registrations'], $metrics['goals'], $metrics['completed'] === 0 ? 0.0 : $metrics['goals'] / $metrics['completed'], $metrics['ovr_avg'], $metrics['age_avg'], $metrics['oldest'], $metrics['save_size'])); $output->write(sprintf('LEAGUE_%d champion=%s bottom=%s points_spread=%d home_wins=%d draws=%d away_wins=%d', $number, $metrics['champion'], $metrics['bottom'], $metrics['spread'], $metrics['home_wins'], $metrics['draws'], $metrics['away_wins'])); }
        $output->write(sprintf('POPULATION initial=%d final=%d active=%d retired=%d created=%d unclubbed=%d unclubbed_active=%d without_active_contract=%d clubbed_without_active_contract=%d active_contracts=%d expired_contracts=%d', $population['initial'], $population['final'], $population['active'], $population['retired'], $population['created'], $population['unclubbed'], $population['unclubbed_active'], $population['without_contract'], $population['clubbed_without_contract'], $population['active_contracts'], $population['expired_contracts']));
        $output->write(sprintf('MOVEMENT checkpoints=%d offers=%d accepted=%d completed=%d destination=%s duplicates=%d', $movement['checkpoints'], $movement['generated'], $movement['accepted'], $movement['completed'], $movement['destination'] ?? 'none', $movement['duplicates']));
        foreach ($career as $key => $row) { $output->write(sprintf('CAREER profile=%s club=%s ovr=%d role=%s starts=%d appearances=%d minutes=%d bench=%d', $key, $row['club'], $row['ovr'], $row['role'], $row['starts'], $row['appearances'], $row['minutes'], $row['bench'])); }
        $failed = array_keys(array_filter($reloadChecks, static fn (bool $value): bool => !$value)); $output->write(sprintf('PERSISTENCE save_initial=%d save_final=%d reload_checks=%d reload_failures=%s', $saveSizes['initial'], $saveSizes['final'] ?? ($saveSizes['season_' . count($seasons)] ?? 0), count($reloadChecks), $failed === [] ? 'none' : implode(',', $failed)));
        $output->write(sprintf('WORLD_HEALTH classification=%s reason=%s', $population['clubbed_without_contract'] === 0 ? 'HEALTHY' : 'DEGRADING', $population['clubbed_without_contract'] === 0 ? 'Consecutive Seasons completed with viable contracted squads; released historical Players remain preserved without active Club state.' : 'A current-season squad Player lacks an active Contract.'));
    }

    private function argumentInt(array $arguments, string $prefix, int $default): int
    {
        foreach ($arguments as $argument) { if (str_starts_with($argument, $prefix)) { return (int) substr($argument, strlen($prefix)); } }
        return $default;
    }

    private function removeStorage(string $directory): void
    {
        OwnedArtifactCleanup::removeOwnedDirectory($directory);
    }
}
