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
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use RuntimeException;
use Throwable;

final class PlayerCreateDemoCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services)
    {
    }

    public function name(): string { return 'player:create-demo'; }

    public function description(): string { return 'Create, persist, assign, reload, and inspect a demo career Player in isolated storage.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $directory = sys_get_temp_dir() . '/goal-legacy-player-' . bin2hex(random_bytes(8));
        $database = null;

        try {
            $calendar = $this->services->worldModule()->service()->calendar();
            $nations = $this->services->nationModule()->service()->loadSelected();
            $competitions = $this->services->competitionModule()->service()->loadSelected();
            $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
            $world = new World(
                new WorldId('player-demo-world'),
                'Player demo world',
                2024004,
                new DateTimeImmutable('@0'),
                $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')),
                $season->id(),
                array_map(static fn ($nation): string => $nation->id()->value(), $nations),
                array_map(static fn ($competition): string => $competition->id()->value(), $competitions),
                $this->services->contentPackages()->selectedIds(),
            );
            $store = new SqliteSaveStore($directory, new JsonSerializer());
            $store->create(SaveMetadata::create('player-demo-world', 'Player demo', $world->currentTime(), new DateTimeImmutable('@0')));
            $database = $store->openDatabase('player-demo-world');
            $this->services->worldModule()->service()->initialize($database, $world, $season);

            $playerService = $this->services->playerModule()->service();
            $player = $playerService->create(new PlayerCreationRequest(
                'career-demo-player',
                'Alex',
                'Demo',
                'Alex Demo',
                '2005-01-01',
                'england',
                [],
                'england',
                ['england'],
                180,
                75,
                'CM',
                88,
                'regular',
                42,
            ));
            $playerService->initializeCareer(
                $database,
                $player,
                new CareerPlayerReference(new CareerId('career-demo'), $player->id(), SimulationDate::fromIsoString('2024-07-31')),
                new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id()),
            );
            $reloaded = $playerService->repository($database)->get($player->id());
            $career = $playerService->careerRepository($database)->get('career-demo');
            $squad = $this->services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $season->id());
            if ($career->playerId()->value() !== $reloaded->id()->value() || count($squad) !== 1 || $squad[0]->clubId()->value() !== 'arsenal') {
                throw new RuntimeException('Demo career Player reload path is incomplete.');
            }
            $output->write(sprintf(
                'Player demo passed: %s id=%s club=%s position=%s ovr=%d potential=%d profile=%s.',
                $reloaded->preferredName(),
                $reloaded->id()->value(),
                $squad[0]->clubId()->value(),
                $reloaded->primaryPosition()->value,
                $reloaded->overallRating(),
                $reloaded->potential(),
                $reloaded->developmentProfile()->value,
            ));

            return 0;
        } catch (Throwable $exception) {
            $output->error('Player demo failed: ' . $exception->getMessage());

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
                throw new RuntimeException('Unable to clean isolated Player database.');
            }
        }
        if (!rmdir($directory)) {
            throw new RuntimeException('Unable to clean isolated Player directory.');
        }
    }
}
