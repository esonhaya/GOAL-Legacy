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
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SeasonStatus;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use Goal\Legacy\Core\Time\SimulationTime;
use RuntimeException;
use Throwable;

final class WorldSelfCheckCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services)
    {
    }

    public function name(): string { return 'world:self-check'; }

    public function description(): string { return 'Create, advance, persist, and reload a World with Nations, Seasons, and Competitions.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $directory = sys_get_temp_dir() . '/goal-legacy-world-' . bin2hex(random_bytes(8));
        $database = null;

        try {
            $calendar = $this->services->worldModule()->service()->calendar();
            $nations = $this->services->nationModule()->service()->loadSelected();
            $competitions = $this->services->competitionModule()->service()->loadSelected();
            if ($nations === [] || $competitions === []) {
                throw new RuntimeException('Selected Nation and Competition content are required.');
            }

            $season = new Season(
                new SeasonId('season-2026-27'),
                '2026/27',
                SimulationDate::fromIsoString('2026-08-01'),
                SimulationDate::fromIsoString('2027-05-31'),
            );
            $world = new World(
                new WorldId('world-self-check'),
                'World self-check',
                2026001,
                new DateTimeImmutable('@0'),
                $calendar->timeAt(SimulationDate::fromIsoString('2026-07-31')),
                $season->id(),
                array_map(static fn ($nation): string => $nation->id()->value(), $nations),
                array_map(static fn ($competition): string => $competition->id()->value(), $competitions),
                $this->services->contentPackages()->selectedIds(),
            );

            $store = new SqliteSaveStore($directory, new JsonSerializer());
            $store->create(SaveMetadata::create('world-self-check', 'World self-check', $world->currentTime(), new DateTimeImmutable('@0')));
            $database = $store->openDatabase('world-self-check');
            $worldService = $this->services->worldModule()->service();
            $worldService->initialize($database, $world, $season);
            $active = $worldService->advanceByDays($database, 'world-self-check', 1);
            $reloaded = $worldService->load($database, 'world-self-check');
            if ($active->toArray() !== $reloaded->toArray() || $reloaded->currentDate($calendar)->toIsoString() !== '2026-08-01') {
                throw new RuntimeException('World active-state reload was not deterministic.');
            }
            $activeCompetitions = $this->services->competitionModule()->service()->repository($database)->all();
            if ($reloaded->currentSeasonId()?->value() !== $season->id()->value()
                || $season->status() !== SeasonStatus::Upcoming
                || count($activeCompetitions) !== count($competitions)
                || count(array_filter($activeCompetitions, static fn ($competition): bool => $competition->status()->value === 'active')) !== count($competitions)) {
                throw new RuntimeException('World active lifecycle state is incomplete.');
            }

            $completed = $worldService->advanceToDate($database, 'world-self-check', SimulationDate::fromIsoString('2027-06-01'));
            $completedSeason = $worldService->seasonRepository($database)->get($season->id());
            $completedCompetitions = $this->services->competitionModule()->service()->repository($database)->all();
            if ($completed->currentDate($calendar)->toIsoString() !== '2027-06-01'
                || $completedSeason->status() !== SeasonStatus::Completed
                || count(array_filter($completedCompetitions, static fn ($competition): bool => $competition->status()->value === 'completed')) !== count($competitions)) {
                throw new RuntimeException('World completion lifecycle state is incomplete.');
            }

            unset($database);
            $output->write(sprintf(
                'World self-check passed with %d Nations, %d Competitions, and deterministic Season lifecycle.',
                count($nations),
                count($competitions),
            ));

            return 0;
        } catch (Throwable $exception) {
            $output->error('World self-check failed: ' . $exception->getMessage());

            return 1;
        } finally {
            unset($database);
            $this->removeIsolatedStorage($directory);
        }
    }

    private function removeIsolatedStorage(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file) && !unlink($file)) {
                throw new RuntimeException('Unable to clean isolated World database.');
            }
        }
        if (!rmdir($directory)) {
            throw new RuntimeException('Unable to clean isolated World directory.');
        }
    }
}
