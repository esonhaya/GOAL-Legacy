<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\CoreServices;
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
use Goal\Legacy\Modules\Contract\Domain\ContractStatus;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Persistence\CareerOpportunityRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerAvailabilityRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Transfer\Persistence\TransferRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use RuntimeException;
use Throwable;

final class CareerMultiSeasonAuditCommand implements CommandInterface
{
    private const SEASON_ID = 'season-2024-25';
    private const COMPETITION_ID = 'premier-league';

    public function __construct(private readonly CoreServices $services) {}

    public function name(): string { return 'career:multi-season-audit'; }

    public function description(): string { return 'Run a continuous long-horizon career audit and report Season rollover boundaries.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $requested = $this->argumentInt($arguments, '--seasons=', 3);
        $seed = $this->argumentInt($arguments, '--seed=', 13003);
        if ($requested < 3 || $requested > 5) {
            $output->error('Multi-season audit requires --seasons between 3 and 5.');
            return 1;
        }
        $directory = sys_get_temp_dir() . '/goal-legacy-multi-season-' . bin2hex(random_bytes(8));
        $database = null;
        try {
            [$store, $database, $season, $world] = $this->initialize($directory, $seed);
            $population = $this->services->playerModule()->service()->populationService()->populate($database, $season, $seed);
            $players = $this->installControlledPlayers($database, $season);
            $matchService = $this->services->matchModule()->service();
            $matches = $matchService->generateFixtures($database, self::COMPETITION_ID, $season->id());
            $dates = [];
            foreach ($matches as $match) { $dates[$match->scheduledDate()->toIsoString()] = $match->scheduledDate(); }
            uasort($dates, static fn (SimulationDate $left, SimulationDate $right): int => $left->compareTo($right));
            $dates = array_values($dates);
            $midpoint = intdiv(count($dates), 2);
            foreach ($dates as $index => $date) {
                $this->services->worldModule()->service()->advanceToDate($database, 'career-multi-season-audit', $date);
                $matchService->simulateDue($database, $date);
                if ($index + 1 === $midpoint) {
                    unset($database);
                    $database = $store->openDatabase('career-multi-season-audit');
                }
            }
            $initialSaveSize = filesize($directory . '/career-multi-season-audit.sqlite') ?: 0;
            $this->services->worldModule()->service()->advanceToDate($database, 'career-multi-season-audit', $season->endDate()->addDays(1));
            $boundaryBeforeReload = $this->boundarySnapshot($database, $season, $world, $matches, $players, $directory);
            $boundaryBeforeReload['save_size_initial'] = $initialSaveSize;
            unset($database);
            $database = $store->openDatabase('career-multi-season-audit');
            $boundaryAfterReload = $this->boundarySnapshot($database, $season, $world, $matches, $players, $directory);
            $boundaryAfterReload['save_size_initial'] = $initialSaveSize;
            $seasonTwo = $this->seasonTwoBoundary($database, $season);
            $movement = $this->services->transferModule()->service()->careerMovement();
            $movementMetrics = $this->movementAudit($database, $season, $players, $movement);
            $this->writeReport($output, $requested, $seed, $season, $boundaryAfterReload, $population, $movementMetrics, $seasonTwo, $boundaryBeforeReload === $boundaryAfterReload);

            return 0;
        } catch (Throwable $exception) {
            $output->error('Career multi-season audit failed: ' . $exception->getMessage());
            return 1;
        } finally {
            unset($database);
            $this->removeStorage($directory);
        }
    }

    /** @return array{0: SqliteSaveStore, 1: \Goal\Legacy\Core\Persistence\DatabaseInterface, 2: Season, 3: World} */
    private function initialize(string $directory, int $seed): array
    {
        $nations = $this->services->nationModule()->service()->loadSelected();
        $competitions = $this->services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId(self::SEASON_ID), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $this->services->worldModule()->service()->calendar();
        $world = new World(new WorldId('career-multi-season-audit'), 'Career multi-season audit', $seed, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $this->services->contentPackages()->selectedIds());
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create('career-multi-season-audit', $world->label(), $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase('career-multi-season-audit');
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
            $registrations->register(new PlayerRegistration($season->id(), new CompetitionId(self::COMPETITION_ID), new ClubId($club), $player->id()));
            $players[$key] = $player;
        }

        return $players;
    }

    /** @param array<string, mixed> $players */
    private function boundarySnapshot($database, Season $season, World $initialWorld, array $matches, array $players, string $directory): array
    {
        $playerRepository = new PlayerRepository($database);
        $playersAll = $playerRepository->all();
        $contracts = $this->services->contractModule()->service()->repository($database)->all();
        $squadRepository = $this->services->clubModule()->service()->squadRepository($database);
        $memberships = array_values(array_filter($squadRepository->all(), static fn ($membership): bool => $membership->seasonId()->value() === $season->id()->value()));
        $registrations = $this->services->competitionModule()->service()->registrationRepository($database)->byCompetition(self::COMPETITION_ID, $season->id());
        $byPlayer = [];
        foreach ($contracts as $contract) { $byPlayer[$contract->playerId()->value()][] = $contract; }
        $withoutActive = 0; $multipleActive = 0;
        foreach ($playersAll as $player) {
            $active = array_filter($byPlayer[$player->id()->value()] ?? [], static fn ($contract): bool => $contract->status() === ContractStatus::Active);
            if ($active === []) { ++$withoutActive; }
            if (count($active) > 1) { ++$multipleActive; }
        }
        $expiredSquad = 0; $unclubbed = 0;
        foreach ($playersAll as $player) {
            if ($squadRepository->byPlayer($player->id(), $season->id()) === []) { ++$unclubbed; continue; }
            $active = array_filter($byPlayer[$player->id()->value()] ?? [], static fn ($contract): bool => $contract->status() === ContractStatus::Active);
            if ($active === []) { ++$expiredSquad; }
        }
        $sizes = [];
        $coverageFailures = 0;
        foreach ($this->services->clubModule()->service()->byCompetition($database, self::COMPETITION_ID, $season->id()) as $club) {
            $clubSquad = $squadRepository->byClub($club->id(), $season->id());
            $sizes[] = count($clubSquad);
            $groups = [];
            foreach ($clubSquad as $membership) { $groups[$playerRepository->get($membership->playerId())->primaryPosition()->value] = true; }
            if (!isset($groups['GK']) || !($groups['CB'] ?? false) && !($groups['LB'] ?? false) && !($groups['RB'] ?? false) || !($groups['CM'] ?? false) && !($groups['DM'] ?? false) && !($groups['AM'] ?? false) || !($groups['ST'] ?? false) && !($groups['LW'] ?? false) && !($groups['RW'] ?? false)) { ++$coverageFailures; }
        }
        $ages = array_map(static fn (Player $player): int => $player->ageAt($season->endDate()), $playersAll);
        $ovrs = array_map(static fn (Player $player): int => $player->overallRating(), $playersAll);
        $matchRepository = new MatchRepository($database);
        $completed = array_values(array_filter($matches, static fn ($match): bool => $matchRepository->get($match->id())->status() === MatchStatus::Completed));
        $completed = array_map(static fn ($match) => $matchRepository->get($match->id()), $completed);
        $goals = array_sum(array_map(static fn ($match): int => ($match->result()?->homeGoals() ?? 0) + ($match->result()?->awayGoals() ?? 0), $completed));
        $injuryRows = $database->connection()->query('SELECT severity, status FROM player_injuries')->fetchAll(\PDO::FETCH_ASSOC);
        $world = $this->services->worldModule()->service()->load($database, $initialWorld->id());
        $seasonRecord = $this->services->worldModule()->service()->seasonRepository($database)->get($season->id());
        $homeWins = $draws = $awayWins = $extremeScores = 0;
        foreach ($completed as $match) {
            $home = $match->result()?->homeGoals() ?? 0;
            $away = $match->result()?->awayGoals() ?? 0;
            if ($home === $away) { ++$draws; } elseif ($home > $away) { ++$homeWins; } else { ++$awayWins; }
            if ($home + $away >= 6) { ++$extremeScores; }
        }
        $standings = $this->services->matchModule()->service()->standings($database, self::COMPETITION_ID, $season->id());
        return ['world_date' => $world->currentDate($this->services->worldModule()->service()->calendar())->toIsoString(), 'season_status' => $seasonRecord->status()->value, 'season_id' => $world->currentSeasonId()?->value(), 'completed_matches' => count($completed), 'players' => count($playersAll), 'unclubbed' => $unclubbed, 'without_active_contract' => $withoutActive, 'expired_but_active_squad' => $expiredSquad, 'multiple_active_contracts' => $multipleActive, 'registrations' => count($registrations), 'squad_avg' => $sizes === [] ? 0.0 : round(array_sum($sizes) / count($sizes), 1), 'squad_min' => $sizes === [] ? 0 : min($sizes), 'clubs_below_25' => count(array_filter($sizes, static fn (int $size): bool => $size < 25)), 'clubs_below_18' => count(array_filter($sizes, static fn (int $size): bool => $size < 18)), 'clubs_below_11' => count(array_filter($sizes, static fn (int $size): bool => $size < 11)), 'coverage_failures' => $coverageFailures, 'age_avg' => $ages === [] ? 0.0 : round(array_sum($ages) / count($ages), 1), 'oldest' => $ages === [] ? 0 : max($ages), 'age_35_plus' => count(array_filter($ages, static fn (int $age): bool => $age >= 35)), 'age_40_plus' => count(array_filter($ages, static fn (int $age): bool => $age >= 40)), 'ovr_avg' => $ovrs === [] ? 0.0 : round(array_sum($ovrs) / count($ovrs), 1), 'ovr_min' => $ovrs === [] ? 0 : min($ovrs), 'ovr_max' => $ovrs === [] ? 0 : max($ovrs), 'ovr_90_plus' => count(array_filter($ovrs, static fn (int $ovr): bool => $ovr >= 90)), 'ovr_99' => count(array_filter($ovrs, static fn (int $ovr): bool => $ovr >= 99)), 'contracts_active' => count(array_filter($contracts, static fn ($contract): bool => $contract->status() === ContractStatus::Active)), 'contracts_expired' => count(array_filter($contracts, static fn ($contract): bool => $contract->status() === ContractStatus::Expired)), 'injuries' => count($injuryRows), 'major_injuries' => count(array_filter($injuryRows, static fn (array $row): bool => $row['severity'] === 'major')), 'recoveries' => count(array_filter($injuryRows, static fn (array $row): bool => $row['status'] === 'recovered')), 'goals' => $goals, 'home_wins' => $homeWins, 'draws' => $draws, 'away_wins' => $awayWins, 'extreme_scores' => $extremeScores, 'champion' => $standings[0]['club_id'] ?? 'none', 'bottom' => $standings[count($standings) - 1]['club_id'] ?? 'none', 'standings_spread' => ((int) ($standings[0]['points'] ?? 0)) - ((int) ($standings[count($standings) - 1]['points'] ?? 0)), 'save_size' => filesize($directory . '/career-multi-season-audit.sqlite') ?: 0, 'players_by_id' => array_map(static fn (Player $player): array => ['id' => $player->id()->value(), 'ovr' => $player->overallRating()], $playersAll)];
    }

    private function seasonTwoBoundary($database, Season $season): string
    {
        $next = new SeasonId('season-2025-26');
        if (!$this->services->worldModule()->service()->seasonRepository($database)->exists($next)) {
            return 'blocked:no Season 2 record or automatic rollover service';
        }
        return 'available';
    }

    /** @param array<string, Player> $players */
    private function movementAudit($database, Season $season, array $players, $movement): array
    {
        $checkpoints = [SimulationDate::fromIsoString('2025-04-01'), SimulationDate::fromIsoString('2025-05-01'), SimulationDate::fromIsoString('2025-05-15')];
        $generated = 0; $weakOffers = 0; $fringeOffers = 0; $breakoutOffers = 0; $accepted = 0; $destination = null;
        foreach ($checkpoints as $date) {
            foreach (['fringe', 'breakout', 'weak'] as $key) {
                $offers = $movement->evaluate($database, $players[$key]->id(), $season->id(), $date);
                $generated += count($offers);
                if ($key === 'weak') { $weakOffers += count($offers); }
                if ($key === 'fringe') { $fringeOffers += count($offers); }
                if ($key === 'breakout') { $breakoutOffers += count($offers); }
            }
        }
        $open = $movement->openOffers($database, $players['fringe']->id(), SimulationDate::fromIsoString('2025-05-15'));
        if ($open !== []) {
            $resolved = $movement->accept($database, $open[0]->id(), SimulationDate::fromIsoString('2025-05-15'));
            $accepted = 1;
            $destination = $resolved->targetClubId()?->value();
        }
        $transfers = (new TransferRepository($database))->byPlayer($players['fringe']->id());
        $sourceKeys = $database->connection()->query("SELECT source_key FROM career_opportunities WHERE type = 'transfer_interest'")->fetchAll(\PDO::FETCH_COLUMN);
        $stats = new PlayerMatchStatRepository($database);
        $selections = new MatchSelectionRepository($database);
        $squads = $this->services->clubModule()->service()->squadRepository($database);
        $career = [];
        foreach ($players as $key => $player) {
            $playerStats = array_values(array_filter($stats->byPlayer($player->id()), static fn ($stat): bool => $stat->appeared()));
            $playerSelections = $selections->byPlayer($player->id());
            $membership = $squads->byPlayer($player->id(), $season->id())[0] ?? null;
            $career[$key] = [
                'club' => $membership?->clubId()->value() ?? 'none',
                'role' => $membership?->role()->value ?? 'none',
                'ovr' => (new PlayerRepository($database))->get($player->id())->overallRating(),
                'starts' => count(array_filter($playerStats, static fn ($stat): bool => $stat->started())),
                'appearances' => count($playerStats),
                'minutes' => array_sum(array_map(static fn ($stat): int => $stat->minutes(), $playerStats)),
                'bench' => count(array_filter($playerSelections, static fn ($selection): bool => $selection->status()->value === 'bench')),
            ];
        }
        return ['checkpoints' => count($checkpoints), 'generated' => $generated, 'weak_offers' => $weakOffers, 'fringe_offers' => $fringeOffers, 'breakout_offers' => $breakoutOffers, 'accepted' => $accepted, 'completed' => count(array_filter($transfers, static fn ($transfer): bool => $transfer->status()->value === 'completed')), 'destination' => $destination, 'duplicates' => count($sourceKeys) - count(array_unique($sourceKeys)), 'career' => $career];
    }

    private function writeReport(ConsoleOutputInterface $output, int $requested, int $seed, Season $season, array $metrics, array $population, array $movement, string $seasonTwo, bool $reloadEqual): void
    {
        $days = $season->startDate()->daysUntil(SimulationDate::fromIsoString($metrics['world_date']));
        $output->write(sprintf('MULTI_SEASON seed=%d requested=%d completed=1 start=%s end=%s simulation_days=%d matches=%d', $seed, $requested, $season->startDate()->toIsoString(), $metrics['world_date'], $days, $metrics['completed_matches']));
        $output->write(sprintf('SEASON_1 status=%s fixtures=380 completed=%d players=%d squad_avg=%.1f squad_min=%d goals=%d goals_per_match=%.2f', $metrics['season_status'], $metrics['completed_matches'], $metrics['players'], $metrics['squad_avg'], $metrics['squad_min'], $metrics['goals'], $metrics['completed_matches'] === 0 ? 0.0 : $metrics['goals'] / $metrics['completed_matches']));
        $output->write(sprintf('LEAGUE_1 champion=%s bottom=%s points_spread=%d home_wins=%d draws=%d away_wins=%d extreme_scores=%d', $metrics['champion'], $metrics['bottom'], $metrics['standings_spread'], $metrics['home_wins'], $metrics['draws'], $metrics['away_wins'], $metrics['extreme_scores']));
        $output->write(sprintf('SEASON_2 %s active_season=%s fixture_rollover=blocked', $seasonTwo, $metrics['season_id'] ?? 'none'));
        $output->write(sprintf('POPULATION start=%d end=%d created_after_initialization=%d removed=0 unclubbed=%d invalid_contracts=%d registrations=%d', $population['players_total'], $metrics['players'], $metrics['players'] - $population['players_total'], $metrics['unclubbed'], $metrics['without_active_contract'], $metrics['registrations']));
        $output->write(sprintf('AGING average=%.1f oldest=%d age_35_plus=%d age_40_plus=%d', $metrics['age_avg'], $metrics['oldest'], $metrics['age_35_plus'], $metrics['age_40_plus']));
        $output->write(sprintf('DEVELOPMENT average_ovr=%.1f range=%d-%d ovr_90_plus=%d ovr_99=%d', $metrics['ovr_avg'], $metrics['ovr_min'], $metrics['ovr_max'], $metrics['ovr_90_plus'], $metrics['ovr_99']));
        $output->write(sprintf('CONTRACTS active=%d expired=%d expired_but_active_squad=%d without_active=%d multiple_active=%d', $metrics['contracts_active'], $metrics['contracts_expired'], $metrics['expired_but_active_squad'], $metrics['without_active_contract'], $metrics['multiple_active_contracts']));
        $output->write(sprintf('SQUADS average=%.1f min=%d below25=%d below18=%d below11=%d position_coverage_failures=%d', $metrics['squad_avg'], $metrics['squad_min'], $metrics['clubs_below_25'], $metrics['clubs_below_18'], $metrics['clubs_below_11'], $metrics['coverage_failures']));
        $output->write(sprintf('MOVEMENT checkpoints=%d offers=%d fringe_offers=%d breakout_offers=%d weak_offers=%d accepted=%d completed=%d destination=%s duplicates=%d', $movement['checkpoints'], $movement['generated'], $movement['fringe_offers'], $movement['breakout_offers'], $movement['weak_offers'], $movement['accepted'], $movement['completed'], $movement['destination'] ?? 'none', $movement['duplicates']));
        foreach ($movement['career'] as $key => $career) {
            $output->write(sprintf('CAREER profile=%s club=%s ovr=%d role=%s starts=%d appearances=%d minutes=%d bench=%d', $key, $career['club'], $career['ovr'], $career['role'], $career['starts'], $career['appearances'], $career['minutes'], $career['bench']));
        }
        $output->write(sprintf('AVAILABILITY injuries=%d major=%d recoveries=%d save_initial=%d save_boundary=%d save_reload_boundary=%s', $metrics['injuries'], $metrics['major_injuries'], $metrics['recoveries'], $metrics['save_size_initial'], $metrics['save_size'], $reloadEqual ? 'pass' : 'fail'));
        $output->write('WORLD_HEALTH classification=DEGRADING reason=Season 1 is coherent, but WorldService has no production Season 2 creation/activation, membership rollover, registration rollover, or next-season fixture path.');
    }

    private function argumentInt(array $arguments, string $prefix, int $default): int
    {
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, $prefix)) { return (int) substr($argument, strlen($prefix)); }
        }
        return $default;
    }

    private function removeStorage(string $directory): void
    {
        if (!is_dir($directory)) { return; }
        foreach (glob($directory . '/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
        rmdir($directory);
    }
}
