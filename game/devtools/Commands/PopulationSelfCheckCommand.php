<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use RuntimeException;
use Throwable;

final class PopulationSelfCheckCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services)
    {
    }

    public function name(): string
    {
        return 'population:self-check';
    }

    public function description(): string
    {
        return 'Populate an isolated Big-5 world and verify deterministic squad state.';
    }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $directory = sys_get_temp_dir() . '/goal-legacy-population-' . bin2hex(random_bytes(8));
        try {
            $nations = $this->services->nationModule()->service()->loadSelected();
            $competitions = $this->services->competitionModule()->service()->loadSelected();
            $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
            $calendar = $this->services->worldModule()->service()->calendar();
            $world = new World(new WorldId('population-self-check'), 'Population self-check', 1010, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $this->services->contentPackages()->selectedIds());
            $store = new SqliteSaveStore($directory, new JsonSerializer());
            $store->create(SaveMetadata::create('population-self-check', $world->label(), $world->currentTime(), new DateTimeImmutable('@0')));
            $database = $store->openDatabase('population-self-check');
            $this->services->worldModule()->service()->initialize($database, $world, $season);
            $population = $this->services->playerModule()->service()->populationService();
            $first = $population->populate($database, $season, $world->universeSeed());
            $second = $population->populate($database, $season, $world->universeSeed());
            $this->assertPopulation($database, $season, $first);
            $firstTotal = (new PlayerRepository($database))->all();
            unset($database);
            $database = $store->openDatabase('population-self-check');
            $reloadedTotal = (new PlayerRepository($database))->all();
            if (count($firstTotal) !== count($reloadedTotal)) {
                throw new RuntimeException('Population save/reload changed Player count.');
            }
            if ((int) $second['players_generated'] !== 0 || $first['players_total'] !== $second['players_total']) {
                throw new RuntimeException('Population rerun was not idempotent.');
            }
            $output->write(sprintf('Population self-check passed: clubs=%d players=%d squads=%d-%d positions=%s roles=%s ovr=%d-%d/%.1f age=%d-%d/%.1f profiles=%s reload=pass', $first['clubs_populated'], $first['players_total'], $first['min_squad_size'], $first['max_squad_size'], $this->formatCounts($first['position_counts']), $this->formatCounts($first['role_counts']), $first['ovr_min'], $first['ovr_max'], $first['ovr_avg'], $first['age_min'], $first['age_max'], $first['age_avg'], $this->formatCounts($first['profile_counts'])));

            return 0;
        } catch (Throwable $exception) {
            $output->error('Population self-check failed: ' . $exception->getMessage());

            return 1;
        } finally {
            $this->removeStorage($directory);
        }
    }

    /** @param array<string, mixed> $population */
    private function assertPopulation(DatabaseInterface $database, Season $season, array $population): void
    {
        $clubs = $this->services->clubModule()->service()->repository($database)->all();
        $expectedPlayers = count($clubs) * 25;
        if ($population['clubs_populated'] !== count($clubs) || $population['players_total'] !== $expectedPlayers || $population['min_squad_size'] !== 25 || $population['max_squad_size'] !== 25) {
            throw new RuntimeException(sprintf('Unexpected population counts: clubs=%d players=%d squads=%d-%d.', $population['clubs_populated'], $population['players_total'], $population['min_squad_size'], $population['max_squad_size']));
        }
        $squadRepository = $this->services->clubModule()->service()->squadRepository($database);
        $contractRepository = $this->services->contractModule()->service()->repository($database);
        $registrationRepository = $this->services->competitionModule()->service()->registrationRepository($database);
        foreach ($clubs as $club) {
            $squad = $squadRepository->byClub($club->id(), $season->id());
            $positions = [];
            foreach ($squad as $membership) {
                $player = (new PlayerRepository($database))->get($membership->playerId());
                $positions[$player->primaryPosition()->value] = true;
                if ($contractRepository->activeForPlayer($player->id()) === null || $registrationRepository->byPlayer($player->id()) === []) {
                    throw new RuntimeException('Population relationship validation failed for ' . $player->id()->value());
                }
            }
            $hasGoalkeeper = isset($positions['GK']);
            $hasDefender = isset($positions['CB']) || isset($positions['LB']) || isset($positions['RB']);
            $hasMidfielder = isset($positions['DM']) || isset($positions['CM']) || isset($positions['AM']);
            $hasAttacker = isset($positions['LW']) || isset($positions['RW']) || isset($positions['ST']);
            if (!$hasGoalkeeper || !$hasDefender || !$hasMidfielder || !$hasAttacker) {
                throw new RuntimeException('Population position coverage failed for ' . $club->id()->value());
            }
        }
    }

    /** @param array<string, int> $counts */
    private function formatCounts(array $counts): string
    {
        ksort($counts, SORT_STRING);

        return implode(',', array_map(static fn (string $key, int $value): string => $key . '=' . $value, array_keys($counts), array_values($counts)));
    }

    private function removeStorage(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($directory);
    }
}
