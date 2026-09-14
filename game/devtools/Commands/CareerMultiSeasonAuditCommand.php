<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Core\Persistence\SqlProfiler;
use Goal\Legacy\Core\Persistence\SqlProfileReporter;
use Goal\Legacy\Core\Persistence\SqliteQueryPlanExplainer;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
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
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCareerState;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
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
        $requested = $this->argumentInt($arguments, '--seasons=', 3);
        $seed = $this->argumentInt($arguments, '--seed=', 13003);
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
            $players = $lifecycleOnly ? [] : $this->installControlledPlayers($database, $season);
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

                if ($seasonNumber === 1) { $movementMetrics = $this->movementAudit($database, $season, $players, $movement); }

                $database = $this->advanceAndReload($store, $database, $season->endDate()->addDays(1), $reloadChecks, 'season-' . $seasonNumber . '-boundary');
                $season = $this->services->worldModule()->service()->seasonRepository($database)->get($season->id());
                $seasonReports[] = $this->seasonMetrics($database, $competitionIds, $season, $directory);
                $saveSizes['season_' . $seasonNumber] = filesize($directory . '/' . self::SAVE_ID . '.sqlite') ?: 0;

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

            return 0;
        } catch (Throwable $exception) {
            $output->error('Career multi-season audit failed: ' . $exception->getMessage());
            return 1;
        } finally {
            unset($database);
            $this->removeStorage($directory);
        }
    }

    private function executeLifecycleOnly($store, $database, World $world, Season $season, int $requested, int $seed, string $directory, ConsoleOutputInterface $output, ?SqlProfiler $profiler = null): int
    {
            $reports = [];
        $reloadChecks = [];
        for ($number = 1; $number <= $requested; ++$number) {
            $output->write(sprintf('LIFECYCLE_SEASON_START number=%d/%d season=%s phase=complete_and_rollover', $number, $requested, $season->id()->value()));
            $seasonStart = hrtime(true);
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
            $output->write(sprintf('LIFECYCLE_AUDIT seed=%d requested=%d completed=%d start=%s end=%s active_start=%d active_final=%d retired_final=%d newgens_final=%d records_final=%d avg_age_final=%.1f oldest_final=%d squad_min=%d squad_max=%d reload_failures=%s', $seed, $requested, count($reports), $reports[0]['season']->startDate()->toIsoString(), $last['season']->startDate()->toIsoString(), $reports[0]['active'], $last['active'], $last['retired'], $last['newgens'], $last['records'], $last['age_avg'], $last['oldest'], $last['squad_min'], $last['squad_max'], $reloadChecks === [] || count(array_filter($reloadChecks, static fn (bool $value): bool => !$value)) === 0 ? 'none' : 'present'));
        foreach ($reports as $index => $report) {
            $output->write(sprintf('LIFECYCLE_SEASON_%d season=%s promoted=%d relegated=%d active=%d retired=%d newgens=%d renewals=%d releases=%d free_signings=%d npc_transfers=%d movement_budget=%d candidates=%d clubs_active=%d newgens_avoided=%d cross_league=%d upward=%d lateral=%d downward=%d records=%d unclubbed_active=%d avg_age=%.1f oldest=%d squad_avg=%.1f squad_min=%d squad_max=%d save_size=%d', $index + 1, $report['season']->id()->value(), $report['promoted'], $report['relegated'], $report['active'], $report['retired'], $report['newgens'], $report['renewed'], $report['released'], $report['free_agent_signings'], $report['npc_transfers'], $report['movement_budget'], $report['candidates_evaluated'], $report['clubs_with_activity'], $report['newgens_avoided'], $report['cross_league'], $report['upward'], $report['lateral'], $report['downward'], $report['records'], $report['unclubbed_active'], $report['age_avg'], $report['oldest'], $report['squad_avg'], $report['squad_min'], $report['squad_max'], $report['save_size']));
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
        $ages = array_map(static fn (Player $player): int => $player->ageAt($season->startDate()), $active);

        $recruitment = $this->services->worldModule()->service()->seasonRollover()?->lastRecruitment() ?? ['free_agent_signings' => 0, 'npc_transfers' => 0, 'newgens_avoided' => 0, 'movement_budget' => 0, 'candidates_evaluated' => 0, 'clubs_with_activity' => 0];
        $lifecycle = $this->services->worldModule()->service()->seasonRollover()?->lastLifecycle() ?? ['renewed' => 0, 'released' => 0, 'carried' => 0];
        $movement = $this->services->worldModule()->service()->seasonRollover()?->lastMovement() ?? ['promoted' => [], 'relegated' => []];
        $transfers = $this->transferMetrics($database, $season);
        return ['season' => $season, 'active' => count($active), 'retired' => $retired, 'newgens' => $newgens, 'renewed' => $lifecycle['renewed'], 'released' => $lifecycle['released'], 'free_agent_signings' => $recruitment['free_agent_signings'], 'npc_transfers' => $recruitment['npc_transfers'], 'movement_budget' => $recruitment['movement_budget'], 'candidates_evaluated' => $recruitment['candidates_evaluated'], 'clubs_with_activity' => $recruitment['clubs_with_activity'], 'newgens_avoided' => $recruitment['newgens_avoided'], 'promoted' => count($movement['promoted']), 'relegated' => count($movement['relegated']), 'cross_league' => $transfers['cross_league'], 'upward' => $transfers['upward'], 'lateral' => $transfers['lateral'], 'downward' => $transfers['downward'], 'records' => count($players), 'unclubbed_active' => count(array_filter($active, static fn (Player $player): bool => !isset($squadPlayerIds[$player->id()->value()]))), 'age_avg' => $ages === [] ? 0.0 : round(array_sum($ages) / count($ages), 1), 'oldest' => $ages === [] ? 0 : max($ages), 'squad_avg' => $sizes === [] ? 0.0 : round(array_sum($sizes) / count($sizes), 1), 'squad_min' => $sizes === [] ? 0 : min($sizes), 'squad_max' => $sizes === [] ? 0 : max($sizes), 'save_size' => filesize($directory . '/' . self::SAVE_ID . '.sqlite') ?: 0];
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

    /** @param list<string> $competitionIds */
    private function generateSeasonFixtures($database, $matchService, array $competitionIds, Season $season): void
    {
        foreach ($competitionIds as $competitionId) { $matchService->generateFixtures($database, $competitionId, $season->id()); }
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

    /** @param list<object> $matches @param list<string> $reloadChecks */
    private function simulateSeason($store, &$database, $matchService, array $matches, Season $season, array &$reloadChecks): void
    {
        $dates = [];
        foreach ($matches as $match) { $dates[$match->scheduledDate()->toIsoString()] = $match->scheduledDate(); }
        $dates = array_values($dates);
        $midpoint = intdiv(count($dates), 2);
        foreach ($dates as $index => $date) {
            $this->services->worldModule()->service()->advanceToDate($database, self::SAVE_ID, $date);
            $matchService->simulateDue($database, $date);
            if ($index + 1 === $midpoint) { $database = $this->advanceAndReload($store, $database, $date, $reloadChecks, $season->id()->value() . '-midseason'); }
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
        if (!is_dir($directory)) { return; }
        foreach (glob($directory . '/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
        rmdir($directory);
    }
}
