<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
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
use Goal\Legacy\Modules\Player\Persistence\CareerEvaluationRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerDevelopmentRepository;
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

        try {
            $continuous = (new self($this->freshServices()))->runScenario($seed, 'career-audit-continuous', false);
            $reloaded = (new self($this->freshServices()))->runScenario($seed, 'career-audit-reloaded', true);
            $equivalent = $this->canonical($continuous) === $this->canonical($reloaded);

            $output->write(sprintf('AUDIT seed=%d horizon=full-season competition=%s fixtures=%d big5_fixtures=%d', $seed, self::COMPETITION_ID, $continuous['fixture_count'], $continuous['big5_fixture_count']));
            $output->write(sprintf('SAVE_RELOAD equivalent=%s midpoint=%s', $equivalent ? 'yes' : 'no', $reloaded['reloaded_midpoint'] ? 'yes' : 'no'));
            foreach ($continuous['players'] as $player) {
                $output->write(sprintf(
                    'PLAYER profile=%s role_context=%s start_ovr=%d end_ovr=%d potential=%d starts=%d bench=%d non_selections=%d appearances=%d selection_pct=%.1f longest_start=%d longest_non_start=%d goals=%d avg_evaluation=%.1f best_evaluation=%d worst_evaluation=%d recent_form=%.1f initial_role=%s final_role=%s role_changes=%d training_events=%d match_development_events=%d training_gain=%d match_gain=%d attribute_gain=%d open_opportunities=%d',
                    $player['profile'],
                    $player['role_context'],
                    $player['start_ovr'],
                    $player['end_ovr'],
                    $player['potential'],
                    $player['starts'],
                    $player['bench'],
                    $player['non_selections'],
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
            $output->write(sprintf('CONSISTENCY match_double_processing=%s development_duplication=%s role_idempotency=%s standings_rebuild=%s selection_stats=%s contract_coherence=%s career_reference=%s completed_fixture_count=%d', $continuous['match_double_processing'] ? 'pass' : 'fail', $continuous['development_unique'] ? 'pass' : 'fail', $continuous['role_idempotent'] ? 'pass' : 'fail', $continuous['standings_rebuild'] ? 'pass' : 'fail', $continuous['selection_stat_consistency'] ? 'pass' : 'fail', $continuous['contract_coherent'] ? 'pass' : 'fail', $continuous['career_reference'] ? 'pass' : 'fail', $continuous['completed_matches']));
            $output->write(sprintf('PRESSURE selection_variance=%s role_changes=%d opportunities=%d availability_gap=evident transfer_market_gap=unresolved', $this->selectionVariance($continuous['players']) ? 'present' : 'low', $continuous['role_changes'], $continuous['opportunities']));
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
    private function runScenario(int $seed, string $saveId, bool $reloadMidpoint): array
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
            $database = $store->openDatabase($saveId);
            $this->services->worldModule()->service()->initialize($database, $world, $season);

            $players = $this->installPlayers($database, $season, $seed);
            $matchService = $this->services->matchModule()->service();
            $fixtureCounts = [];
            foreach (['premier-league', 'la-liga', 'bundesliga', 'serie-a', 'ligue-1'] as $competitionId) {
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
                    $database = $store->openDatabase($saveId);
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
            $resultMetrics = $this->leagueMetrics($completed, $standings);
            $consistency = $this->consistency($database, $matchService, $completed, $players, $season, $standingsRebuilt);

            return [
                'fixture_count' => count($matches),
                'big5_fixture_count' => array_sum($fixtureCounts),
                'fixture_counts' => $fixtureCounts,
                'completed_matches' => count($completed),
                'players' => $metrics,
                'role_changes' => array_sum(array_column($metrics, 'role_changes')),
                'opportunities' => array_sum(array_column($metrics, 'open_opportunities')),
                'reloaded_midpoint' => $reloadMidpoint,
                ...$resultMetrics,
                ...$consistency,
            ];
        } finally {
            unset($database);
            $this->removeIsolatedStorage($directory);
        }
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
            $starts = $bench = $notSelected = 0; $statuses = [];
            foreach ($selections as $selection) {
                if ($selection->clubId()->value() !== self::CLUB_ID) { continue; }
                $statuses[] = $selection->status()->value;
                if ($selection->status()->value === 'starter') { ++$starts; }
                elseif ($selection->status()->value === 'bench') { ++$bench; }
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
                'id' => $player->id()->value(), 'profile' => $definition['profile'], 'role_context' => $definition['role_context'], 'start_ovr' => $definition['player']->overallRating(), 'end_ovr' => $player->overallRating(), 'potential' => $player->potential(), 'starts' => $starts, 'bench' => $bench, 'non_selections' => $notSelected, 'appearances' => $appearances, 'eligible_matches' => count($selections), 'selection_percentage' => count($selections) === 0 ? 0.0 : round(($starts + $bench) / count($selections) * 100, 1), 'longest_start_streak' => $streaks['starts'], 'longest_non_start_streak' => $streaks['non_starts'], 'goals' => $goals, 'average_evaluation' => $scores === [] ? 0.0 : round(array_sum($scores) / count($scores), 1), 'best_evaluation' => $scores === [] ? 0 : max($scores), 'worst_evaluation' => $scores === [] ? 0 : min($scores), 'recent_form' => $scores === [] ? 0.0 : round(array_sum(array_slice($scores, 0, 5)) / count(array_slice($scores, 0, 5)), 1), 'initial_role' => $roleHistory[0]['role'] ?? null, 'final_role' => $membership?->role()->value, 'role_changes' => $roleChanges, 'training_events' => $trainingEvents, 'match_development_events' => $matchEvents, 'training_gain' => $trainingGain, 'match_gain' => $matchGain, 'total_attribute_gain' => $gain, 'open_opportunities' => count($opportunityService->openForPlayer($database, $player->id())), 'attributes' => $finalAttributes,
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
        $starterSelections = (int) $database->connection()->query("SELECT COUNT(*) FROM match_player_selections WHERE status = 'starter'")->fetchColumn(); $statRows = (int) $database->connection()->query('SELECT COUNT(*) FROM match_player_stats')->fetchColumn();

        return ['match_double_processing' => $doubleProcessing, 'development_unique' => $developmentUnique, 'role_idempotent' => $roleIdempotent, 'standings_rebuild' => $standingsRebuilt, 'contract_coherent' => $contractCoherent, 'career_reference' => $careerReference, 'selection_stat_consistency' => $starterSelections === $statRows];
    }

    /** @param list<array<string, mixed>> $players */
    private function selectionVariance(array $players): bool
    {
        $rates = array_map(static fn (array $player): float => (float) $player['selection_percentage'], $players);

        return $rates !== [] && (max($rates) - min($rates)) >= 20.0;
    }

    /** @param list<string> $arguments */
    private function seed(array $arguments): int
    {
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--seed=')) { return (int) substr($argument, 7); }
        }

        return 8001;
    }

    private function freshServices(): CoreServices
    {
        return (new Bootstrap())->create(dirname(__DIR__, 3));
    }

    /** @param array<string, mixed> $scenario */
    private function canonical(array $scenario): string
    {
        unset($scenario['reloaded_midpoint']);
        return json_encode($scenario, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function removeIsolatedStorage(string $directory): void
    {
        if (!is_dir($directory)) { return; }
        foreach (glob($directory . '/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
        rmdir($directory);
    }
}
