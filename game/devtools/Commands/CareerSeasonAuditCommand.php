<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqlProfiler;
use Goal\Legacy\Core\Persistence\SqlProfileReporter;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
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
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Match\MatchService;
use Goal\Legacy\Modules\Player\CareerOpportunityService;
use Goal\Legacy\Modules\Player\ClubExpectationService;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\DevelopmentProfile;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\TrainingRequest;
use Goal\Legacy\Modules\Player\PlayerAvailabilityService;
use Goal\Legacy\Modules\Player\Persistence\CareerEvaluationRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerDevelopmentRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerAvailabilityRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use RuntimeException;
use Throwable;

final class CareerSeasonAuditCommand implements CommandInterface
{
    private const SEASON_ID = 'season-2024-25';
    private const CLUB_ID = 'arsenal';
    private const COMPETITION_ID = 'premier-league';
    private const CAREER_PLAYER_ID = 'audit-prodigy';

    public function __construct(private readonly CoreServices $services) {}

    public function name(): string { return 'career:season-audit'; }

    public function description(): string { return 'Run a deterministic full-season career playability and realism audit in isolated saves.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $seed = $this->seed($arguments);
        $profileEnabled = in_array('--profile-sql', $arguments, true) || $this->profileJsonPath($arguments) !== null;
        $profileTop = $this->profileTop($arguments);
        $profiler = $profileEnabled ? new SqlProfiler() : null;

        try {
            $continuous = (new self($this->freshServices()))->runScenario($seed, 'career-audit-continuous', false, $profiler, $profileTop);
            $reloaded = (new self($this->freshServices()))->runScenario($seed, 'career-audit-reloaded', true);
            $equivalent = $this->canonical($continuous) === $this->canonical($reloaded);

            $output->write(sprintf('AUDIT seed=%d horizon=full-season competition=%s fixtures=%d big5_fixtures=%d', $seed, self::COMPETITION_ID, $continuous['fixture_count'], $continuous['big5_fixture_count']));
            $output->write(sprintf('SAVE_RELOAD equivalent=%s midpoint=%s', $equivalent ? 'yes' : 'no', $reloaded['reloaded_midpoint'] ? 'yes' : 'no'));
            if ($profileEnabled && is_string($continuous['sql_profile_text'] ?? null)) {
                foreach (explode("\n", $continuous['sql_profile_text']) as $line) { $output->write($line); }
                $output->write(sprintf('SQL_RUNTIME total_ms=%.1f database_start=%d database_end=%d', $continuous['runtime_ms'], $continuous['database_size_start'], $continuous['database_size_end']));
                $jsonPath = $this->profileJsonPath($arguments);
                if ($jsonPath !== null) {
                    $directory = dirname($jsonPath);
                    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) { throw new RuntimeException('Unable to create SQL profile directory: ' . $directory); }
                    if (file_put_contents($jsonPath, json_encode($continuous['sql_profile'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === false) { throw new RuntimeException('Unable to write SQL profile: ' . $jsonPath); }
                    $output->write('SQL_JSON path=' . $jsonPath);
                }
            }
            $output->write(sprintf('POPULATION clubs=%d players=%d avg_squad=%.1f min_squad=%d max_squad=%d ovr_avg=%.1f age_avg=%.1f', $continuous['population']['clubs_populated'], $continuous['population']['players_total'], $continuous['population']['avg_squad_size'], $continuous['population']['min_squad_size'], $continuous['population']['max_squad_size'], $continuous['population']['ovr_avg'], $continuous['population']['age_avg']));
            $squad = $continuous['squad'];
            $squadSummary = $squad['summary'];
            $output->write(sprintf('SQUAD club=%s size=%d matches=%d ovr=%d-%d/%.1f median=%.1f age=%d-%d/%.1f positions=%s roles=%s', self::CLUB_ID, $squadSummary['squad_size'], $squadSummary['matches'], $squadSummary['ovr_min'], $squadSummary['ovr_max'], $squadSummary['ovr_avg'], $squadSummary['ovr_median'], $squadSummary['age_min'], $squadSummary['age_max'], $squadSummary['age_avg'], $this->formatCounts($squadSummary['position_counts']), $this->formatCounts($squadSummary['role_counts'])));
            foreach ($squad['players'] as $player) {
                $output->write(sprintf('SQUAD_PLAYER id=%s position=%s age=%d ovr=%d->%d potential=%d profile=%s role=%s->%s eligible=%d starts=%d bench=%d substitute_appearances=%d appearances=%d minutes=%d non_selections=%d limited=%d unavailable=%d goals=%d avg_evaluation=%.1f fatigue_absences=%d injury_missed=%d', $player['player_id'], $player['position'], $player['age'], $player['initial_ovr'], $player['end_ovr'], $player['potential'], $player['profile'], $player['initial_role'], $player['final_role'], $player['eligible_matches'], $player['starts'], $player['bench_selections'], $player['substitute_appearances'], $player['appearances'], $player['minutes'], $player['non_selections'], $player['limited'], $player['unavailable'], $player['goals'], $player['average_evaluation'], $player['fatigue_absences'], $player['injury_matches_missed']));
            }
            $starts = $squadSummary['starts_distribution'];
            $output->write(sprintf('PARTICIPATION expected_starter_slots=%d actual_starter_slots=%d consistent=%s unique_starters=%d players_with_starts=%d players_with_appearances=%d never_selected=%d never_appeared=%d distinct_bench_players=%d bench_selections=%d bench_appearances=%d substitute_appearances=%d total_minutes=%d top11_start_share=%.1f rotation_events=%d fatigue_rotation_players=%d injury_replacement_players=%d', $squadSummary['expected_starter_slots'], $squadSummary['actual_starter_slots'], $squadSummary['expected_starter_slots'] === $squadSummary['actual_starter_slots'] ? 'yes' : 'no', $squadSummary['unique_starters'], $squadSummary['players_with_starts'], $squadSummary['players_with_appearances'], $squadSummary['players_never_selected'], $squadSummary['players_never_appeared'], $squadSummary['distinct_bench_players'], $squadSummary['bench_selections'], $squadSummary['bench_appearances'], $squadSummary['substitute_appearances'], $squadSummary['total_player_minutes'], $squadSummary['top11_start_share'], $squadSummary['rotation_events'], count($squadSummary['fatigue_rotation_players']), count($squadSummary['injury_replacement_players'])));
            $rankValues = [1, 11, 12, 13, 15, 20, 25];
            $rankOutput = [];
            foreach ($rankValues as $rank) {
                $rankOutput[] = 'rank' . $rank . '=' . ($starts[$rank - 1] ?? 0);
            }
            $output->write('START_DISTRIBUTION ' . implode(' ', $rankOutput));
            foreach ($squadSummary['role_groups'] as $role => $group) {
                $output->write(sprintf('ROLE role=%s players=%d avg_ovr=%.1f avg_starts=%.1f avg_bench=%.1f avg_minutes=%.1f', $role, $group['players'], $group['avg_ovr'], $group['avg_starts'], $group['avg_bench_selections'], $group['avg_minutes']));
            }
            $output->write(sprintf('DEVELOPMENT squad_ovr_gain=%d-%d/%.2f starter_gain_avg=%.2f fringe_gain_avg=%.2f profile_gains=%s', $squadSummary['ovr_gain_min'], $squadSummary['ovr_gain_max'], $squadSummary['ovr_gain_avg'], $squadSummary['starter_gain_avg'], $squadSummary['fringe_gain_avg'], $this->formatCounts($squadSummary['profile_gain_avg'])));
            $output->write(sprintf('QUALITY ovr_stddev=%.2f top5=%s bottom5=%s', $squadSummary['ovr_stddev'], $this->formatQuality($squadSummary['quality_top5']), $this->formatQuality($squadSummary['quality_bottom5'])));
            $career = $squadSummary['career_player'];
            $careerRootCause = $career['bench_selections'] > 0 ? 'bench_without_substitutions' : 'depth_role_ability_selection';
            $output->write(sprintf('CAREER_PLAYER id=%s ovr=%d position=%s role=%s overall_depth_rank=%d position_depth_rank=%d selection_score=%d starter_cutoff=%d bench_cutoff=%d score_range=%d-%d eligible=%d limited=%d starts=%d bench=%d substitute_appearances=%d appearances=%d unavailable=%d non_selections=%d root_cause=%s', self::CAREER_PLAYER_ID, $career['initial_ovr'], $career['position'], $career['initial_role'], $squadSummary['career_depth_rank_overall'], $squadSummary['career_depth_rank_position'], $squadSummary['career_selection_score'], $squadSummary['starter_cutoff'], $squadSummary['bench_cutoff'], $squadSummary['selection_score_range'][0], $squadSummary['selection_score_range'][1], $career['eligible_matches'], $career['limited'], $career['starts'], $career['bench_selections'], $career['substitute_appearances'], $career['appearances'], $career['unavailable'], $career['non_selections'], $careerRootCause));
            foreach ($continuous['sample_clubs'] as $clubId => $sample) {
                $output->write(sprintf('CROSS_CLUB club=%s squad=%d avg_ovr=%.1f unique_starters=%d appearances=%d', $clubId, $sample['squad_size'], $sample['ovr_avg'], $sample['unique_starters'], $sample['players_with_appearances']));
            }
            $league = $continuous['league_population'];
            $output->write(sprintf('LEAGUE_POPULATION competition=%s players=%d players_with_starts=%d players_with_appearances=%d ovr=%d-%d/%.1f median=%.1f age=%d-%d/%.1f positions=%s roles=%s', self::COMPETITION_ID, $league['players'], $league['players_with_starts'], $league['players_with_appearances'], $league['ovr_min'], $league['ovr_max'], $league['ovr_avg'], $league['ovr_median'], $league['age_min'], $league['age_max'], $league['age_avg'], $this->formatCounts($league['position_distribution']), $this->formatCounts($league['role_distribution'])));
            $output->write(sprintf('SELECTION position_aware=yes aggregate_fallback_used=%s real_player_match_path=%s', $squadSummary['aggregate_fallback_used'] ? 'yes' : 'no', $squadSummary['real_player_match_path'] ? 'yes' : 'no'));
            foreach ($continuous['players'] as $player) {
                $output->write(sprintf(
                    'PLAYER profile=%s role_context=%s start_ovr=%d end_ovr=%d potential=%d starts=%d bench=%d non_selections=%d unavailable=%d appearances=%d selection_pct=%.1f longest_start=%d longest_non_start=%d goals=%d avg_evaluation=%.1f best_evaluation=%d worst_evaluation=%d recent_form=%.1f initial_role=%s final_role=%s role_changes=%d training_events=%d match_development_events=%d training_gain=%d match_gain=%d attribute_gain=%d open_opportunities=%d',
                    $player['profile'],
                    $player['role_context'],
                    $player['start_ovr'],
                    $player['end_ovr'],
                    $player['potential'],
                    $player['starts'],
                    $player['bench'],
                    $player['non_selections'],
                    $player['unavailable'],
                    $player['appearances'],
                    $player['selection_percentage'],
                    $player['longest_start_streak'],
                    $player['longest_non_start_streak'],
                    $player['goals'],
                    $player['average_evaluation'],
                    $player['best_evaluation'],
                    $player['worst_evaluation'],
                    $player['recent_form'],
                    $player['initial_role'],
                    $player['final_role'],
                    $player['role_changes'],
                    $player['training_events'],
                    $player['match_development_events'],
                    $player['training_gain'],
                    $player['match_gain'],
                    $player['total_attribute_gain'],
                    $player['open_opportunities'],
                ));
            }
            $output->write(sprintf('LEAGUE completed=%d goals=%d goals_per_match=%.2f home_wins=%d draws=%d away_wins=%d extreme_scores=%d standings_spread=%d', $continuous['completed_matches'], $continuous['goals'], $continuous['goals_per_match'], $continuous['home_wins'], $continuous['draws'], $continuous['away_wins'], $continuous['extreme_scores'], $continuous['standings_spread']));
            $output->write(sprintf('AVAILABILITY unique_starters=%d rotation_events=%d fatigue_rotation_players=%d injury_replacement_players=%d injuries=%d minor=%d moderate=%d major=%d injury_matches_missed=%d recoveries=%d', $squadSummary['unique_starters'], $squadSummary['rotation_events'], count($squadSummary['fatigue_rotation_players']), count($squadSummary['injury_replacement_players']), $squadSummary['injuries_total'], $squadSummary['minor_injuries'], $squadSummary['moderate_injuries'], $squadSummary['major_injuries'], $squadSummary['injury_matches_missed'], $squadSummary['recoveries']));
            $output->write(sprintf('CONSISTENCY match_double_processing=%s development_duplication=%s role_idempotency=%s standings_rebuild=%s selection_stats=%s contract_coherence=%s career_reference=%s completed_fixture_count=%d', $continuous['match_double_processing'] ? 'pass' : 'fail', $continuous['development_unique'] ? 'pass' : 'fail', $continuous['role_idempotent'] ? 'pass' : 'fail', $continuous['standings_rebuild'] ? 'pass' : 'fail', $continuous['selection_stat_consistency'] ? 'pass' : 'fail', $continuous['contract_coherent'] ? 'pass' : 'fail', $continuous['career_reference'] ? 'pass' : 'fail', $continuous['completed_matches']));
            $output->write(sprintf('PRESSURE selection_variance=%s full_squad_start_distribution=%s role_changes=%d opportunities=%d availability_gap=evident transfer_market_gap=unresolved', $squadSummary['unique_starters'] > 11 ? 'present' : 'low', $squadSummary['unique_starters'] > 11 ? 'present' : 'low', $continuous['role_changes'], $continuous['opportunities']));
            if (!$equivalent) {
                throw new RuntimeException('Continuous and midpoint-reload audit results diverged.');
            }

            return 0;
        } catch (Throwable $exception) {
            $output->error('Career season audit failed: ' . $exception->getMessage());

            return 1;
        }
    }

    /** @return array<string, mixed> */
    private function runScenario(int $seed, string $saveId, bool $reloadMidpoint, ?SqlProfiler $profiler = null, int $profileTop = 10): array
    {
        $directory = sys_get_temp_dir() . '/goal-legacy-season-audit-' . bin2hex(random_bytes(8));
        $database = null;
        try {
            $nations = $this->services->nationModule()->service()->loadSelected();
            $competitions = $this->services->competitionModule()->service()->loadSelected();
            $season = new Season(new SeasonId(self::SEASON_ID), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
            $calendar = $this->services->worldModule()->service()->calendar();
            $world = new World(new WorldId($saveId), 'Career season audit', $seed, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $this->services->contentPackages()->selectedIds());
            $store = new SqliteSaveStore($directory, new JsonSerializer());
            $store->create(SaveMetadata::create($saveId, 'Career season audit', $world->currentTime(), new DateTimeImmutable('@0')));
            $database = $store->openDatabase($saveId, $profiler);
            $this->services->worldModule()->service()->initialize($database, $world, $season);

            $players = $this->installPlayers($database, $season, $seed);
            $population = $this->services->playerModule()->service()->populationService()->populate($database, $season, $seed);
            $squadSnapshot = $this->captureSquad($database, self::CLUB_ID, $season);
            $sampleClubs = $this->samplePremierLeagueClubs($database, $season);
            $sampleSnapshots = [];
            foreach ($sampleClubs as $sampleClubId) {
                $sampleSnapshots[$sampleClubId] = $this->captureSquad($database, $sampleClubId, $season);
            }
            $leagueSnapshots = [];
            foreach ($this->services->clubModule()->service()->byCompetition($database, self::COMPETITION_ID, $season->id()) as $club) {
                $leagueSnapshots[$club->id()->value()] = $this->captureSquad($database, $club->id()->value(), $season);
            }
            $profiler?->reset();
            $workloadStarted = hrtime(true);
            $databaseSizeStart = filesize($directory . '/' . $saveId . '.sqlite') ?: 0;
            $matchService = $this->services->matchModule()->service();
            $fixtureCounts = [];
            foreach (array_filter($competitions, static fn ($competition): bool => $competition->tier() === 1) as $competition) {
                $competitionId = $competition->id()->value();
                $fixtureCounts[$competitionId] = count($matchService->generateFixtures($database, $competitionId, $season->id()));
            }
            $matches = $matchService->repository($database)->byCompetition(self::COMPETITION_ID, $season->id());
            if (count($matches) !== 380) {
                throw new RuntimeException('The full-season audit did not generate exactly 380 primary league fixtures.');
            }
            $dates = [];
            foreach ($matches as $match) { $dates[$match->scheduledDate()->toIsoString()] = $match->scheduledDate(); }
            uasort($dates, static fn (SimulationDate $a, SimulationDate $b): int => $a->compareTo($b));
            $trainingBlocks = $this->trainingBlocks($season->startDate());
            $trainingIndex = 0;
            $dates = array_values($dates);
            foreach ($dates as $index => $date) {
                while (isset($trainingBlocks[$trainingIndex]) && !$trainingBlocks[$trainingIndex]['end']->isAfter($date)) {
                    foreach ($players as $definition) {
                        $this->services->playerModule()->service()->trainingService()->complete($database, new TrainingRequest($definition['player']->id(), $trainingBlocks[$trainingIndex]['id'] . ':' . $definition['player']->id()->value(), 'balanced', $trainingBlocks[$trainingIndex]['start'], $trainingBlocks[$trainingIndex]['end']));
                    }
                    ++$trainingIndex;
                }
                $this->services->worldModule()->service()->advanceToDate($database, $saveId, $date);
                foreach ($matches as $match) {
                    if ($match->scheduledDate()->toIsoString() === $date->toIsoString()) {
                        $matchService->simulate($database, $match->id());
                    }
                }
                if ($reloadMidpoint && $index === intdiv(count($dates), 2) - 1) {
                    unset($database);
                    $database = $store->openDatabase($saveId, $profiler);
                }
            }

            $matches = $matchService->repository($database)->byCompetition(self::COMPETITION_ID, $season->id());
            $completed = array_values(array_filter($matches, static fn ($match): bool => $match->status() === MatchStatus::Completed));
            if (count($completed) !== 380) {
                throw new RuntimeException(sprintf('Expected 380 completed primary fixtures, found %d.', count($completed)));
            }
            $standings = $matchService->standings($database, self::COMPETITION_ID, $season->id());
            $standingsRebuilt = $standings === $matchService->standings($database, self::COMPETITION_ID, $season->id());
            $metrics = $this->playerMetrics($database, $players, $season, $matches);
            $squadMetrics = $this->fullSquadMetrics($database, self::CLUB_ID, $season, $matches, $squadSnapshot);
            $sampleMetrics = [];
            foreach ($sampleSnapshots as $sampleClubId => $sampleSnapshot) {
                $sampleMetrics[$sampleClubId] = $this->squadSummary($this->fullSquadMetrics($database, $sampleClubId, $season, $matches, $sampleSnapshot));
            }
            $resultMetrics = $this->leagueMetrics($completed, $standings);
            $availabilityMetrics = $this->availabilityMetrics($database, $completed, $players);
            $consistency = $this->consistency($database, $matchService, $completed, $players, $season, $standingsRebuilt);
            $runtimeMs = (hrtime(true) - $workloadStarted) / 1_000_000;
            $sqlProfile = $profiler?->snapshot();
            $sqlProfileText = null;
            if ($profiler !== null) {
                (new SqliteQueryPlanExplainer())->explain($database->connection(), $profiler, $profileTop);
                $reporter = new SqlProfileReporter();
                $sqlProfile = $reporter->json($profiler, $profileTop);
                $sqlProfileText = $reporter->text($profiler, $profileTop);
            }

            return [
                'fixture_count' => count($matches),
                'big5_fixture_count' => array_sum($fixtureCounts),
                'fixture_counts' => $fixtureCounts,
                'completed_matches' => count($completed),
                'players' => $metrics,
                'role_changes' => array_sum(array_column($metrics, 'role_changes')),
                'opportunities' => array_sum(array_column($metrics, 'open_opportunities')),
                'reloaded_midpoint' => $reloadMidpoint,
                'population' => $population,
                'squad' => $squadMetrics,
                'sample_clubs' => $sampleMetrics,
                'league_population' => $this->leaguePopulationSummary($leagueSnapshots, $database, $season),
                ...$resultMetrics,
                ...$availabilityMetrics,
                ...$consistency,
                'runtime_ms' => round($runtimeMs, 3),
                'database_size_start' => $databaseSizeStart,
                'database_size_end' => filesize($directory . '/' . $saveId . '.sqlite') ?: 0,
                'sql_profile' => $sqlProfile,
                'sql_profile_text' => $sqlProfileText,
            ];
        } finally {
            unset($database);
            $this->removeIsolatedStorage($directory);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function captureSquad(DatabaseInterface $database, string $clubId, Season $season): array
    {
        $players = new PlayerRepository($database);
        $squad = $this->services->clubModule()->service()->squadRepository($database);
        $result = [];
        foreach ($squad->byClub($clubId, $season->id()) as $membership) {
            $player = $players->get($membership->playerId());
            $history = $squad->roleHistory($membership->playerId(), $season->id());
            $result[$player->id()->value()] = [
                'player_id' => $player->id()->value(),
                'position' => $player->primaryPosition()->value,
                'age' => $player->ageAt($season->startDate()),
                'initial_ovr' => $player->overallRating(),
                'potential' => $player->potential(),
                'profile' => $player->developmentProfile()->value,
                'initial_role' => $history[0]['role'] ?? $membership->role()->value,
                'initial_attributes' => $player->attributes()->toArray(),
            ];
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @return list<string> */
    private function samplePremierLeagueClubs(DatabaseInterface $database, Season $season): array
    {
        $clubs = $this->services->clubModule()->service()->byCompetition($database, self::COMPETITION_ID, $season->id());
        usort($clubs, static fn ($left, $right): int => ($right->reputation() <=> $left->reputation()) ?: strcmp($left->id()->value(), $right->id()->value()));
        $ids = [$clubs[0]->id()->value(), $clubs[intdiv(count($clubs) - 1, 2)]->id()->value(), $clubs[count($clubs) - 1]->id()->value()];

        return array_values(array_unique($ids));
    }

    /** @param list<GameMatch> $matches @param array<string, array<string, mixed>> $snapshot @return array<string, mixed> */
    private function fullSquadMetrics(DatabaseInterface $database, string $clubId, Season $season, array $matches, array $snapshot): array
    {
        $clubMatches = array_values(array_filter($matches, static fn (GameMatch $match): bool => $match->homeClubId()->value() === $clubId || $match->awayClubId()->value() === $clubId));
        usort($clubMatches, static fn (GameMatch $left, GameMatch $right): int => ($left->scheduledDate()->compareTo($right->scheduledDate())) ?: strcmp($left->id()->value(), $right->id()->value()));
        $selectionRepository = new MatchSelectionRepository($database);
        $statsRepository = new PlayerMatchStatRepository($database);
        $availabilityRepository = new PlayerAvailabilityRepository($database);
        $availability = new PlayerAvailabilityService();
        $developmentRepository = new PlayerDevelopmentRepository($database);
        $evaluationRepository = new CareerEvaluationRepository($database);
        $squadRepository = $this->services->clubModule()->service()->squadRepository($database);
        $playerRepository = new PlayerRepository($database);
        $metrics = [];
        foreach ($snapshot as $playerId => $initial) {
            $metrics[$playerId] = $initial + [
                'eligible_matches' => 0, 'starts' => 0, 'bench_selections' => 0, 'appearances' => 0, 'limited' => 0,
                'minutes' => 0, 'non_selections' => 0, 'goals' => 0, 'unavailable' => 0,
                'fatigue_absences' => 0, 'injury_matches_missed' => 0, 'bench_appearances' => 0, 'substitute_appearances' => 0,
            ];
        }
        $previousStarters = [];
        $rotationEvents = 0;
        $injuryReplacementPlayers = [];
        $fatigueRotationPlayers = [];
        $actualStarterSlots = 0;
        $benchSelections = 0;
        $benchAppearances = 0;
        $allStatsUsePersistedPlayers = true;
        foreach ($clubMatches as $match) {
            $selections = array_values(array_filter($selectionRepository->byMatch($match->id()), static fn ($selection): bool => $selection->clubId()->value() === $clubId));
            $starters = [];
            foreach ($selections as $selection) {
                $playerId = $selection->playerId()->value();
                if (!isset($metrics[$playerId])) {
                    continue;
                }
                ++$metrics[$playerId]['eligible_matches'];
                if ($selection->status()->value === 'starter') {
                    ++$metrics[$playerId]['starts'];
                    ++$actualStarterSlots;
                    $starters[$playerId] = true;
                } elseif ($selection->status()->value === 'bench') {
                    ++$metrics[$playerId]['bench_selections'];
                    ++$benchSelections;
                } elseif ($selection->status()->value === 'unavailable') {
                    ++$metrics[$playerId]['unavailable'];
                } else {
                    ++$metrics[$playerId]['non_selections'];
                }
                $assessment = $availability->assess($database, $selection->playerId(), $match->scheduledDate());
                $injury = $assessment->injury();
                if ($assessment->status()->value === 'limited') {
                    ++$metrics[$playerId]['limited'];
                }
                if ($selection->status()->value !== 'starter' && $injury === null && $assessment->fatigue() >= 50) {
                    ++$metrics[$playerId]['fatigue_absences'];
                    $fatigueRotationPlayers[$playerId] = true;
                }
                if ($selection->status()->value === 'unavailable' && $this->hasActiveInjury($availabilityRepository->byPlayer($selection->playerId()), $match->scheduledDate())) {
                    ++$metrics[$playerId]['injury_matches_missed'];
                }
            }
            $currentStarterIds = array_keys($starters);
            sort($currentStarterIds, SORT_STRING);
            if ($previousStarters !== [] && $previousStarters !== $currentStarterIds) {
                ++$rotationEvents;
                foreach (array_diff($previousStarters, $currentStarterIds) as $previousPlayerId) {
                    foreach ($availabilityRepository->byPlayer(new PlayerId($previousPlayerId)) as $injury) {
                        if ($injury->isActiveAt($match->scheduledDate())) {
                            foreach (array_diff($currentStarterIds, $previousStarters) as $replacementPlayerId) {
                                $injuryReplacementPlayers[$replacementPlayerId] = true;
                            }
                            break;
                        }
                    }
                }
            }
            $previousStarters = $currentStarterIds;
            foreach ($statsRepository->byMatch($match->id()) as $stat) {
                if ($stat->clubId()->value() !== $clubId) {
                    continue;
                }
                if (!isset($metrics[$stat->playerId()->value()])) {
                    $allStatsUsePersistedPlayers = false;
                    continue;
                }
                if ($stat->appeared()) {
                    ++$metrics[$stat->playerId()->value()]['appearances'];
                    $metrics[$stat->playerId()->value()]['minutes'] += $stat->minutes();
                    $metrics[$stat->playerId()->value()]['goals'] += $stat->goals();
                    if (isset($metrics[$stat->playerId()->value()]) && $metrics[$stat->playerId()->value()]['bench_selections'] > 0 && !$stat->started()) {
                        ++$metrics[$stat->playerId()->value()]['bench_appearances'];
                        ++$metrics[$stat->playerId()->value()]['substitute_appearances'];
                        ++$benchAppearances;
                    }
                }
            }
        }
        $injuryCounts = ['minor' => 0, 'moderate' => 0, 'major' => 0];
        $injuriesTotal = 0;
        $recoveries = 0;
        $injuryMatchesMissed = 0;
        foreach ($metrics as $playerId => &$metric) {
            $player = $playerRepository->get($playerId);
            $metric['end_ovr'] = $player->overallRating();
            $metric['role_changes'] = count(array_filter($squadRepository->roleHistory($player->id(), $season->id()), static fn (array $row): bool => $row['source'] === 'evaluation'));
            $membership = $squadRepository->byPlayer($player->id(), $season->id())[0] ?? null;
            $metric['final_role'] = $membership?->role()->value;
            $scores = array_values(array_filter(array_map(static fn (array $row): int => (int) $row['evaluation_score'], $evaluationRepository->byPlayer($player->id(), new ClubId($clubId))), static fn (int $score): bool => $score > 0));
            $metric['average_evaluation'] = $scores === [] ? 0.0 : round(array_sum($scores) / count($scores), 1);
            $metric['injuries'] = count($availabilityRepository->byPlayer($player->id()));
            $injuriesTotal += $metric['injuries'];
            foreach ($availabilityRepository->byPlayer($player->id()) as $injury) {
                ++$injuryCounts[$injury->severity()->value];
                if ($injury->status()->value === 'recovered') {
                    ++$recoveries;
                }
            }
            $injuryMatchesMissed += $metric['injury_matches_missed'];
            $metric['fatigue'] = $availability->assess($database, $player->id(), $season->endDate())->fatigue();
            $metric['training_events'] = count(array_filter($developmentRepository->byPlayer($player->id()), static fn ($entry): bool => $entry->source() === 'training'));
            $metric['match_development_events'] = count(array_filter($developmentRepository->byPlayer($player->id()), static fn ($entry): bool => $entry->source() === 'match'));
            $metric['total_attribute_gain'] = array_sum($player->attributes()->toArray()) - array_sum($metric['initial_attributes']);
        }
        unset($metric);
        uasort($metrics, static fn (array $left, array $right): int => ($right['starts'] <=> $left['starts']) ?: (($right['minutes'] <=> $left['minutes']) ?: strcmp($left['player_id'], $right['player_id'])));
        $initialScores = $this->initialSelectionScores($snapshot);
        $career = $metrics[self::CAREER_PLAYER_ID] ?? null;
        $ovrValues = array_map(static fn (array $metric): int => $metric['end_ovr'], array_values($metrics));
        sort($ovrValues, SORT_NUMERIC);
        $gains = array_map(static fn (array $metric): int => $metric['end_ovr'] - $metric['initial_ovr'], array_values($metrics));
        sort($gains, SORT_NUMERIC);
        $starterMetrics = array_values(array_filter($metrics, static fn (array $metric): bool => $metric['starts'] > 0));
        $fringeMetrics = array_values(array_filter($metrics, static fn (array $metric): bool => $metric['starts'] === 0));
        $profileGains = [];
        foreach ($metrics as $metric) {
            $profile = $metric['profile'];
            $profileGains[$profile][] = $metric['end_ovr'] - $metric['initial_ovr'];
        }
        foreach ($profileGains as $profile => $profileValues) {
            $profileGains[$profile] = round(array_sum($profileValues) / count($profileValues), 2);
        }
        $meanOvr = $ovrValues === [] ? 0.0 : array_sum($ovrValues) / count($ovrValues);
        $variance = $ovrValues === [] ? 0.0 : array_sum(array_map(static fn (int $ovr): float => ($ovr - $meanOvr) ** 2, $ovrValues)) / count($ovrValues);
        $quality = array_values($metrics);
        usort($quality, static fn (array $left, array $right): int => ($right['end_ovr'] <=> $left['end_ovr']) ?: strcmp($left['player_id'], $right['player_id']));
        $topQuality = array_slice($quality, 0, 5);
        $bottomQuality = array_slice(array_reverse($quality), 0, 5);
        $summary = [
            'club_id' => $clubId,
            'matches' => count($clubMatches),
            'squad_size' => count($metrics),
            'expected_starter_slots' => count($clubMatches) * 11,
            'actual_starter_slots' => $actualStarterSlots,
            'unique_starters' => count(array_filter($metrics, static fn (array $metric): bool => $metric['starts'] > 0)),
            'players_with_starts' => count(array_filter($metrics, static fn (array $metric): bool => $metric['starts'] > 0)),
            'players_with_appearances' => count(array_filter($metrics, static fn (array $metric): bool => $metric['appearances'] > 0)),
            'players_never_selected' => count(array_filter($metrics, static fn (array $metric): bool => $metric['starts'] === 0 && $metric['bench_selections'] === 0)),
            'players_never_appeared' => count(array_filter($metrics, static fn (array $metric): bool => $metric['appearances'] === 0)),
            'distinct_bench_players' => count(array_filter($metrics, static fn (array $metric): bool => $metric['bench_selections'] > 0)),
            'bench_selections' => $benchSelections,
            'bench_appearances' => $benchAppearances,
            'substitute_appearances' => $benchAppearances,
            'total_player_minutes' => array_sum(array_column($metrics, 'minutes')),
            'rotation_events' => $rotationEvents,
            'fatigue_rotation_players' => array_keys($fatigueRotationPlayers),
            'injury_replacement_players' => array_keys($injuryReplacementPlayers),
            'injuries_total' => $injuriesTotal,
            'minor_injuries' => $injuryCounts['minor'],
            'moderate_injuries' => $injuryCounts['moderate'],
            'major_injuries' => $injuryCounts['major'],
            'injury_matches_missed' => $injuryMatchesMissed,
            'recoveries' => $recoveries,
            'top11_start_share' => $actualStarterSlots === 0 ? 0.0 : round(array_sum(array_column(array_slice(array_values($metrics), 0, 11), 'starts')) / $actualStarterSlots * 100, 1),
            'ovr_min' => $ovrValues[0] ?? 0,
            'ovr_max' => $ovrValues === [] ? 0 : $ovrValues[count($ovrValues) - 1],
            'ovr_avg' => $ovrValues === [] ? 0.0 : round(array_sum($ovrValues) / count($ovrValues), 1),
            'ovr_median' => $this->median($ovrValues),
            'ovr_stddev' => round(sqrt($variance), 2),
            'ovr_gain_min' => $gains[0] ?? 0,
            'ovr_gain_max' => $gains === [] ? 0 : $gains[count($gains) - 1],
            'ovr_gain_avg' => $gains === [] ? 0.0 : round(array_sum($gains) / count($gains), 2),
            'starter_gain_avg' => $starterMetrics === [] ? 0.0 : round(array_sum(array_map(static fn (array $metric): int => $metric['end_ovr'] - $metric['initial_ovr'], $starterMetrics)) / count($starterMetrics), 2),
            'fringe_gain_avg' => $fringeMetrics === [] ? 0.0 : round(array_sum(array_map(static fn (array $metric): int => $metric['end_ovr'] - $metric['initial_ovr'], $fringeMetrics)) / count($fringeMetrics), 2),
            'profile_gain_avg' => $profileGains,
            'quality_top5' => array_values(array_map(static fn (array $metric): array => ['id' => $metric['player_id'], 'ovr' => $metric['end_ovr']], $topQuality)),
            'quality_bottom5' => array_values(array_map(static fn (array $metric): array => ['id' => $metric['player_id'], 'ovr' => $metric['end_ovr']], $bottomQuality)),
            'real_player_match_path' => $allStatsUsePersistedPlayers && $actualStarterSlots === count($clubMatches) * 11,
            'aggregate_fallback_used' => !$allStatsUsePersistedPlayers || $actualStarterSlots !== count($clubMatches) * 11,
            'age_min' => min(array_column($snapshot, 'age')),
            'age_max' => max(array_column($snapshot, 'age')),
            'age_avg' => round(array_sum(array_column($snapshot, 'age')) / count($snapshot), 1),
            'position_counts' => $this->countField($snapshot, 'position'),
            'role_counts' => $this->countField($snapshot, 'initial_role'),
            'role_groups' => $this->roleGroups($metrics),
            'starts_distribution' => array_values(array_map(static fn (array $metric): int => $metric['starts'], array_values($metrics))),
            'selection_score_range' => [$initialScores['min'], $initialScores['max']],
            'career_selection_score' => $initialScores['career'],
            'starter_cutoff' => $initialScores['starter_cutoff'],
            'bench_cutoff' => $initialScores['bench_cutoff'],
            'career_depth_rank_overall' => $this->depthRank($snapshot, self::CAREER_PLAYER_ID, null),
            'career_depth_rank_position' => $this->depthRank($snapshot, self::CAREER_PLAYER_ID, $snapshot[self::CAREER_PLAYER_ID]['position'] ?? null),
            'career_player' => $career,
        ];

        return ['summary' => $summary, 'players' => array_values($metrics)];
    }

    /** @param array<string, array<string, mixed>> $snapshot @return array{min:int,max:int,career:int,starter_cutoff:int,bench_cutoff:int} */
    private function initialSelectionScores(array $snapshot): array
    {
        $scores = [];
        foreach ($snapshot as $playerId => $player) {
            $scores[$playerId] = SquadRole::from($player['initial_role'])->weight() + ($player['initial_ovr'] * 10);
        }
        arsort($scores, SORT_NUMERIC);
        $ordered = array_values($scores);

        return ['min' => min($ordered), 'max' => max($ordered), 'career' => $scores[self::CAREER_PLAYER_ID] ?? 0, 'starter_cutoff' => $ordered[10] ?? 0, 'bench_cutoff' => $ordered[17] ?? 0];
    }

    /** @param array<string, array<string, mixed>> $snapshot */
    private function depthRank(array $snapshot, string $playerId, ?string $position): int
    {
        $players = array_filter($snapshot, static fn (array $player): bool => $position === null || $player['position'] === $position);
        uasort($players, static fn (array $left, array $right): int => ($right['initial_ovr'] <=> $left['initial_ovr']) ?: strcmp($left['player_id'], $right['player_id']));
        $rank = 1;
        foreach ($players as $id => $_) {
            if ($id === $playerId) {
                return $rank;
            }
            ++$rank;
        }

        return 0;
    }

    /** @param array<string, array<string, mixed>> $metrics @return array<string, array<string, float|int>> */
    private function roleGroups(array $metrics): array
    {
        $groups = [];
        foreach ($metrics as $metric) {
            $role = $metric['initial_role'];
            $groups[$role]['players'] = ($groups[$role]['players'] ?? 0) + 1;
            $groups[$role]['ovr'] = ($groups[$role]['ovr'] ?? 0) + $metric['end_ovr'];
            $groups[$role]['starts'] = ($groups[$role]['starts'] ?? 0) + $metric['starts'];
            $groups[$role]['bench_selections'] = ($groups[$role]['bench_selections'] ?? 0) + $metric['bench_selections'];
            $groups[$role]['minutes'] = ($groups[$role]['minutes'] ?? 0) + $metric['minutes'];
        }
        foreach ($groups as $role => $group) {
            $groups[$role]['avg_ovr'] = round($group['ovr'] / $group['players'], 1);
            $groups[$role]['avg_starts'] = round($group['starts'] / $group['players'], 1);
            $groups[$role]['avg_bench_selections'] = round($group['bench_selections'] / $group['players'], 1);
            $groups[$role]['avg_minutes'] = round($group['minutes'] / $group['players'], 1);
            unset($groups[$role]['ovr'], $groups[$role]['starts'], $groups[$role]['bench_selections'], $groups[$role]['minutes']);
        }

        ksort($groups, SORT_STRING);

        return $groups;
    }

    /** @param array<string, array<string, mixed>> $snapshot @return array<string, int> */
    private function countField(array $snapshot, string $field): array
    {
        $counts = [];
        foreach ($snapshot as $player) {
            $value = (string) $player[$field];
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }

    /** @param list<int> $values */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 0 ? ($values[$middle - 1] + $values[$middle]) / 2 : (float) $values[$middle];
    }

    /** @param list<\Goal\Legacy\Modules\Player\Domain\Injury> $injuries */
    private function hasActiveInjury(array $injuries, SimulationDate $date): bool
    {
        foreach ($injuries as $injury) {
            if ($injury->isActiveAt($date)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, array<string, mixed>> $metrics @return array<string, mixed> */
    private function squadSummary(array $metrics): array
    {
        $summary = $metrics['summary'];
        unset($summary['career_player']);

        return $summary;
    }

    /** @param array<string, array<string, mixed>> $snapshots @return array<string, mixed> */
    private function leaguePopulationSummary(array $snapshots, DatabaseInterface $database, Season $season): array
    {
        $players = [];
        $roles = [];
        $positions = [];
        $ovrs = [];
        $ages = [];
        foreach ($snapshots as $snapshot) {
            foreach ($snapshot as $player) {
                $players[$player['player_id']] = true;
                $roles[$player['initial_role']] = ($roles[$player['initial_role']] ?? 0) + 1;
                $positions[$player['position']] = ($positions[$player['position']] ?? 0) + 1;
                $ovrs[] = $player['initial_ovr'];
                $ages[] = $player['age'];
            }
        }
        $starts = (int) $database->connection()->query("SELECT COUNT(DISTINCT player_id) FROM match_player_stats WHERE started = 1")->fetchColumn();
        $appearances = (int) $database->connection()->query("SELECT COUNT(DISTINCT player_id) FROM match_player_stats WHERE appeared = 1")->fetchColumn();
        sort($ovrs, SORT_NUMERIC);
        ksort($roles, SORT_STRING);
        ksort($positions, SORT_STRING);

        return ['players' => count($players), 'players_with_starts' => $starts, 'players_with_appearances' => $appearances, 'role_distribution' => $roles, 'position_distribution' => $positions, 'ovr_min' => $ovrs[0] ?? 0, 'ovr_max' => $ovrs === [] ? 0 : $ovrs[count($ovrs) - 1], 'ovr_avg' => $ovrs === [] ? 0.0 : round(array_sum($ovrs) / count($ovrs), 1), 'ovr_median' => $this->median($ovrs), 'age_min' => $ages === [] ? 0 : min($ages), 'age_max' => $ages === [] ? 0 : max($ages), 'age_avg' => $ages === [] ? 0.0 : round(array_sum($ages) / count($ages), 1)];
    }

    /** @return list<array<string, mixed>> */
    private function installPlayers(DatabaseInterface $database, Season $season, int $seed): array
    {
        $definitions = [
            ['id' => 'audit-prodigy', 'profile' => 'prodigy', 'role' => SquadRole::Prospect, 'ovr' => 52, 'potential' => 95, 'role_context' => 'prospect'],
            ['id' => 'audit-regular', 'profile' => 'regular', 'role' => SquadRole::Rotation, 'ovr' => 52, 'potential' => 90, 'role_context' => 'rotation'],
            ['id' => 'audit-late-bloomer', 'profile' => 'late_bloomer', 'role' => SquadRole::Regular, 'ovr' => 52, 'potential' => 95, 'role_context' => 'regular'],
            ['id' => 'audit-key-context', 'profile' => 'regular', 'role' => SquadRole::KeyPlayer, 'ovr' => 68, 'potential' => 92, 'role_context' => 'key_player'],
        ];
        $fillerRoles = [SquadRole::KeyPlayer, SquadRole::KeyPlayer, SquadRole::Regular, SquadRole::Regular, SquadRole::Regular, SquadRole::Regular, SquadRole::Rotation, SquadRole::Rotation, SquadRole::Rotation, SquadRole::Rotation, SquadRole::Prospect, SquadRole::Prospect, SquadRole::Prospect, SquadRole::Prospect, SquadRole::Prospect, SquadRole::Prospect];
        foreach ($fillerRoles as $index => $role) {
            $ovr = $role === SquadRole::KeyPlayer ? 65 : ($role === SquadRole::Regular ? 62 : ($role === SquadRole::Rotation ? 60 : 56));
            $definitions[] = ['id' => 'audit-filler-' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT), 'profile' => 'regular', 'role' => $role, 'ovr' => $ovr, 'potential' => 90, 'role_context' => $role->value];
        }

        $playerService = $this->services->playerModule()->service();
        $clubService = $this->services->clubModule()->service();
        $contractService = $this->services->contractModule()->service();
        $registration = $this->services->competitionModule()->service()->registrationRepository($database);
        $result = [];
        foreach ($definitions as $index => $definition) {
            $attributes = new PlayerAttributeSet($definition['ovr'], $definition['ovr'], $definition['ovr'], $definition['ovr'], $definition['ovr'], $definition['ovr']);
            $player = $playerService->create(new PlayerCreationRequest($definition['id'], 'Audit', 'Player', $definition['id'], '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', $definition['potential'], $definition['profile'], $seed + $index, $attributes));
            $membership = new ClubSquadMembership(new ClubId(self::CLUB_ID), $player->id(), $season->id(), $definition['role']);
            if ($definition['id'] === self::CAREER_PLAYER_ID) {
                $playerService->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('career-season-audit'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), $membership);
            } else {
                $playerService->repository($database)->save($player);
                $clubService->squadRepository($database)->save($membership);
            }
            $contractService->save($database, $contractService->create(new ContractCreationRequest(new ContractId('audit-contract-' . $definition['id']), $player->id(), new ClubId(self::CLUB_ID), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2025-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
            $registration->register(new PlayerRegistration($season->id(), new CompetitionId(self::COMPETITION_ID), new ClubId(self::CLUB_ID), $player->id()));
            $definition['player'] = $player;
            $definition['start_attributes'] = $attributes->toArray();
            $result[] = $definition;
        }

        return $result;
    }

    /** @return list<array{id:string,start:SimulationDate,end:SimulationDate}> */
    private function trainingBlocks(SimulationDate $seasonStart): array
    {
        $blocks = [];
        for ($index = 0; $index < 10; ++$index) {
            $start = $seasonStart->addDays($index * 28);
            $blocks[] = ['id' => 'audit-training-' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT), 'start' => $start, 'end' => $start->addDays(27)];
        }

        return $blocks;
    }

    /** @param list<array<string, mixed>> $definitions @param list<GameMatch> $matches @return list<array<string, mixed>> */
    private function playerMetrics(DatabaseInterface $database, array $definitions, Season $season, array $matches): array
    {
        $playerRepository = $this->services->playerModule()->service()->repository($database);
        $squadRepository = $this->services->clubModule()->service()->squadRepository($database);
        $selectionRepository = new MatchSelectionRepository($database);
        $statsRepository = new PlayerMatchStatRepository($database);
        $evaluationRepository = new CareerEvaluationRepository($database);
        $developmentRepository = new PlayerDevelopmentRepository($database);
        $opportunityService = new CareerOpportunityService();
        $result = [];
        foreach ($definitions as $definition) {
            $player = $playerRepository->get($definition['player']->id());
            $selections = $selectionRepository->byPlayer($player->id());
            $starts = $bench = $notSelected = $unavailable = 0; $statuses = [];
            foreach ($selections as $selection) {
                if ($selection->clubId()->value() !== self::CLUB_ID) { continue; }
                $statuses[] = $selection->status()->value;
                if ($selection->status()->value === 'starter') { ++$starts; }
                elseif ($selection->status()->value === 'bench') { ++$bench; }
                elseif ($selection->status()->value === 'unavailable') { ++$unavailable; }
                else { ++$notSelected; }
            }
            $stats = $statsRepository->byPlayer($player->id());
            $appearances = 0; $goals = 0;
            foreach ($stats as $stat) { if ($stat->appeared()) { ++$appearances; $goals += $stat->goals(); } }
            $evaluations = $evaluationRepository->byPlayer($player->id(), new ClubId(self::CLUB_ID));
            $scores = array_values(array_filter(array_map(static fn (array $row): int => (int) $row['evaluation_score'], $evaluations), static fn (int $score): bool => $score > 0));
            $history = $developmentRepository->byPlayer($player->id()); $trainingEvents = $matchEvents = 0;
            $trainingGain = $matchGain = 0;
            foreach ($history as $entry) {
                $gain = array_sum($entry->attributeDeltas());
                if ($entry->source() === 'training') { ++$trainingEvents; $trainingGain += $gain; }
                if ($entry->source() === 'match') { ++$matchEvents; $matchGain += $gain; }
            }
            $roleHistory = $squadRepository->roleHistory($player->id(), $season->id());
            $roleChanges = count(array_filter($roleHistory, static fn (array $row): bool => $row['source'] === 'evaluation'));
            $membership = $squadRepository->byPlayer($player->id(), $season->id())[0] ?? null;
            $streaks = $this->streaks($statuses);
            $finalAttributes = $player->attributes()->toArray(); $gain = 0;
            foreach ($finalAttributes as $name => $value) { $gain += $value - $definition['start_attributes'][$name]; }
            $result[] = [
                'id' => $player->id()->value(), 'profile' => $definition['profile'], 'role_context' => $definition['role_context'], 'start_ovr' => $definition['player']->overallRating(), 'end_ovr' => $player->overallRating(), 'potential' => $player->potential(), 'starts' => $starts, 'bench' => $bench, 'non_selections' => $notSelected, 'unavailable' => $unavailable, 'appearances' => $appearances, 'eligible_matches' => count($selections), 'selection_percentage' => count($selections) === 0 ? 0.0 : round(($starts + $bench) / count($selections) * 100, 1), 'longest_start_streak' => $streaks['starts'], 'longest_non_start_streak' => $streaks['non_starts'], 'goals' => $goals, 'average_evaluation' => $scores === [] ? 0.0 : round(array_sum($scores) / count($scores), 1), 'best_evaluation' => $scores === [] ? 0 : max($scores), 'worst_evaluation' => $scores === [] ? 0 : min($scores), 'recent_form' => $scores === [] ? 0.0 : round(array_sum(array_slice($scores, 0, 5)) / count(array_slice($scores, 0, 5)), 1), 'initial_role' => $roleHistory[0]['role'] ?? null, 'final_role' => $membership?->role()->value, 'role_changes' => $roleChanges, 'training_events' => $trainingEvents, 'match_development_events' => $matchEvents, 'training_gain' => $trainingGain, 'match_gain' => $matchGain, 'total_attribute_gain' => $gain, 'open_opportunities' => count($opportunityService->openForPlayer($database, $player->id())), 'attributes' => $finalAttributes,
            ];
        }

        return $result;
    }

    /** @param list<string> $statuses @return array{starts:int,non_starts:int} */
    private function streaks(array $statuses): array
    {
        $bestStarts = $bestNonStarts = $starts = $nonStarts = 0;
        foreach ($statuses as $status) {
            if ($status === 'starter') { ++$starts; $nonStarts = 0; } else { ++$nonStarts; $starts = 0; }
            $bestStarts = max($bestStarts, $starts); $bestNonStarts = max($bestNonStarts, $nonStarts);
        }

        return ['starts' => $bestStarts, 'non_starts' => $bestNonStarts];
    }

    /** @param list<GameMatch> $matches @param list<array<string, mixed>> $definitions @return array<string, int> */
    private function availabilityMetrics(DatabaseInterface $database, array $matches, array $definitions): array
    {
        $selectionRepository = new MatchSelectionRepository($database);
        $availabilityRepository = new PlayerAvailabilityRepository($database);
        $playerIds = array_map(static fn (array $definition): string => $definition['player']->id()->value(), $definitions);
        $injuries = [];
        foreach ($playerIds as $playerId) {
            foreach ($availabilityRepository->byPlayer(new PlayerId($playerId)) as $injury) {
                $injuries[$injury->id()] = $injury;
            }
        }
        $severityCounts = ['minor' => 0, 'moderate' => 0, 'major' => 0]; $missed = 0; $previousStarters = null; $rotationEvents = 0; $uniqueStarters = [];
        usort($matches, static fn (GameMatch $left, GameMatch $right): int => $left->scheduledDate()->compareTo($right->scheduledDate()));
        foreach ($matches as $match) {
            $selections = array_values(array_filter($selectionRepository->byMatch($match->id()), static fn ($selection): bool => $selection->clubId()->value() === self::CLUB_ID));
            $starters = array_values(array_map(static fn ($selection): string => $selection->playerId()->value(), array_filter($selections, static fn ($selection): bool => $selection->status()->value === 'starter')));
            sort($starters, SORT_STRING);
            foreach ($starters as $playerId) { $uniqueStarters[$playerId] = true; }
            if ($previousStarters !== null && $previousStarters !== $starters) { ++$rotationEvents; }
            $previousStarters = $starters;
            foreach ($selections as $selection) {
                if ($selection->status()->value !== 'unavailable') { continue; }
                foreach ($injuries as $injury) {
                    if ($injury->playerId()->value() === $selection->playerId()->value() && $injury->isActiveAt($match->scheduledDate())) { ++$missed; break; }
                }
            }
        }
        foreach ($injuries as $injury) { ++$severityCounts[$injury->severity()->value]; }

        return ['unique_starters' => count($uniqueStarters), 'rotation_events' => $rotationEvents, 'injuries_total' => count($injuries), 'minor_injuries' => $severityCounts['minor'], 'moderate_injuries' => $severityCounts['moderate'], 'major_injuries' => $severityCounts['major'], 'injury_matches_missed' => $missed, 'recoveries' => count(array_filter($injuries, static fn ($injury): bool => $injury->status()->value === 'recovered'))];
    }

    /** @param list<GameMatch> $matches @param list<array<string, int|string>> $standings @return array<string, mixed> */
    private function leagueMetrics(array $matches, array $standings): array
    {
        $goals = $homeWins = $draws = $awayWins = $extreme = 0;
        foreach ($matches as $match) {
            $home = $match->result()?->homeGoals() ?? 0; $away = $match->result()?->awayGoals() ?? 0; $goals += $home + $away;
            if ($home === $away) { ++$draws; } elseif ($home > $away) { ++$homeWins; } else { ++$awayWins; }
            if ($home + $away >= 6) { ++$extreme; }
        }

        return ['goals' => $goals, 'goals_per_match' => $matches === [] ? 0.0 : round($goals / count($matches), 2), 'home_wins' => $homeWins, 'draws' => $draws, 'away_wins' => $awayWins, 'extreme_scores' => $extreme, 'standings_spread' => ((int) ($standings[0]['points'] ?? 0)) - ((int) ($standings[count($standings) - 1]['points'] ?? 0))];
    }

    /** @param list<GameMatch> $matches @param list<array<string, int|string>> $standings @param list<array<string, mixed>> $definitions @return array<string, bool> */
    private function consistency(DatabaseInterface $database, MatchService $matchService, array $matches, array $definitions, Season $season, bool $standingsRebuilt): array
    {
        $sample = $matches[0] ?? null; $doubleProcessing = false;
        if ($sample !== null) {
            try { $matchService->simulate($database, $sample->id()); } catch (\Throwable) { $doubleProcessing = true; }
        }
        $developmentUnique = true; $historyRepository = new PlayerDevelopmentRepository($database);
        foreach ($definitions as $definition) {
            $keys = []; foreach ($historyRepository->byPlayer($definition['player']->id()) as $entry) { $key = $entry->source() . ':' . $entry->sourceId(); if (isset($keys[$key])) { $developmentUnique = false; } $keys[$key] = true; }
        }
        $squadRepository = $this->services->clubModule()->service()->squadRepository($database); $roleIdempotent = true; $expectations = new ClubExpectationService($this->services->clubModule()->service());
        $player = $definitions[0]['player']; $historyBefore = count($squadRepository->roleHistory($player->id(), $season->id())); $evaluationBefore = count((new CareerEvaluationRepository($database))->byPlayer($player->id(), new ClubId(self::CLUB_ID))); if ($sample !== null) { $expectations->evaluateMatch($database, $sample); } $roleIdempotent = $historyBefore === count($squadRepository->roleHistory($player->id(), $season->id())) && $evaluationBefore === count((new CareerEvaluationRepository($database))->byPlayer($player->id(), new ClubId(self::CLUB_ID)));
        $contractCoherent = true; $contractRepository = $this->services->contractModule()->service()->repository($database); foreach ($definitions as $definition) { $contractCoherent = $contractCoherent && $contractRepository->activeForPlayer($definition['player']->id()->value()) !== null; }
        $careerReference = $this->services->playerModule()->service()->careerRepository($database)->get('career-season-audit')->playerId()->value() === self::CAREER_PLAYER_ID;
        $starterSelections = (int) $database->connection()->query("SELECT COUNT(*) FROM match_player_selections WHERE status = 'starter'")->fetchColumn(); $statRows = (int) $database->connection()->query('SELECT COUNT(*) FROM match_player_stats')->fetchColumn(); $substitutionRows = (int) $database->connection()->query('SELECT COUNT(*) FROM match_substitutions')->fetchColumn();

        return ['match_double_processing' => $doubleProcessing, 'development_unique' => $developmentUnique, 'role_idempotent' => $roleIdempotent, 'standings_rebuild' => $standingsRebuilt, 'contract_coherent' => $contractCoherent, 'career_reference' => $careerReference, 'selection_stat_consistency' => $starterSelections + $substitutionRows === $statRows];
    }

    /** @param list<array<string, mixed>> $players */
    private function selectionVariance(array $players): bool
    {
        $rates = array_map(static fn (array $player): float => (float) $player['selection_percentage'], $players);

        return $rates !== [] && (max($rates) - min($rates)) >= 20.0;
    }

    /** @param array<string, int|float> $counts */
    private function formatCounts(array $counts): string
    {
        $parts = [];
        foreach ($counts as $key => $count) {
            $parts[] = $key . ':' . $count;
        }

        return implode(',', $parts);
    }

    /** @param list<array{id:string,ovr:int}> $players */
    private function formatQuality(array $players): string
    {
        return implode(',', array_map(static fn (array $player): string => $player['id'] . ':' . $player['ovr'], $players));
    }

    /** @param list<string> $arguments */
    private function seed(array $arguments): int
    {
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--seed=')) { return (int) substr($argument, 7); }
        }

        return 8001;
    }

    /** @param list<string> $arguments */
    private function profileJsonPath(array $arguments): ?string
    {
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--profile-sql-json=')) {
                $path = trim(substr($argument, 19));
                return $path === '' ? null : $path;
            }
        }

        return null;
    }

    /** @param list<string> $arguments */
    private function profileTop(array $arguments): int
    {
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--profile-sql-top=')) {
                return max(1, min(100, (int) substr($argument, 18)));
            }
        }

        return 10;
    }

    private function freshServices(): CoreServices
    {
        return (new Bootstrap())->create(dirname(__DIR__, 3));
    }

    /** @param array<string, mixed> $scenario */
    private function canonical(array $scenario): string
    {
        unset($scenario['reloaded_midpoint'], $scenario['runtime_ms'], $scenario['database_size_start'], $scenario['database_size_end'], $scenario['sql_profile'], $scenario['sql_profile_text']);
        return json_encode($scenario, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function removeIsolatedStorage(string $directory): void
    {
        if (!is_dir($directory)) { return; }
        foreach (glob($directory . '/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
        rmdir($directory);
    }
}
