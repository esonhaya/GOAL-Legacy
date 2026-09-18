<?php

declare(strict_types=1);

namespace Goal\Legacy\Web;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\Player\Avatar\PlayerAppearanceService;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerStartRequest;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\YouthCareerStartService;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use RuntimeException;

/**
 * Web adapter for the canonical career-start flow. Youth Camp and Club
 * eligibility remain owned by YouthCareerStartService.
 */
final class WebCareerStartWorkflow
{
    public function __construct(
        private readonly CoreServices $services,
        private readonly string $projectRoot,
    ) {
    }

    /** @return array{player:Player,opportunities:list<array<string,mixed>>} */
    public function preview(CareerStartRequest $request): array
    {
        $directory = sys_get_temp_dir() . '/goal-legacy-web-preview-' . bin2hex(random_bytes(6));
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $careerId = new CareerId($request->careerId);
        $season = $this->season();
        try {
            $world = $this->world($request, $season);
            $store->create(SaveMetadata::create($careerId->value(), $request->name . ' career', $world->currentTime(), new DateTimeImmutable('@0')));
            $database = $store->openDatabase($careerId->value());
            $this->services->worldModule()->service()->initialize($database, $world, $season);
            $this->services->playerModule()->service()->populationService()->populate($database, $season, $request->seed);
            $start = $this->startService();
            $player = $start->createProspect($request);
            $opportunities = $start->opportunities($database, $player, $season);
            if ($opportunities === []) {
                throw new RuntimeException('Youth Camp could not produce a legitimate Club opportunity.');
            }

            return ['player' => $player, 'opportunities' => $opportunities];
        } finally {
            $this->removeDirectory($directory);
        }
    }

    /** @param list<array<string,mixed>>|null $opportunities */
    public function create(CareerStartRequest $request, string $clubId, \Goal\Legacy\Modules\Player\Domain\PlayerAppearance $appearance, ?array $opportunities = null): void
    {
        $store = $this->services->saveStore();
        if ($store->exists($request->careerId)) {
            throw new RuntimeException(sprintf('Save "%s" already exists.', $request->careerId));
        }
        $preview = $opportunities === null
            ? $this->preview($request)
            : ['player' => $this->startService()->createProspect($request), 'opportunities' => $opportunities];
        $season = $this->season();
        $world = $this->world($request, $season);
        $careerId = new CareerId($request->careerId);
        $created = false;
        try {
            $store->create(SaveMetadata::create($careerId->value(), $request->name . ' career', $world->currentTime(), new DateTimeImmutable('@0')));
            $created = true;
            $database = $store->openDatabase($careerId->value());
            $this->services->worldModule()->service()->initialize($database, $world, $season);
            $start = $this->startService();
            $start->accept(
                $database,
                $preview['player'],
                $careerId,
                $season,
                SimulationDate::fromIsoString('2024-07-31'),
                $preview['opportunities'],
                $clubId,
            );
            (new PlayerAppearanceService())->save($database, $preview['player'], $appearance);
            $this->services->playerModule()->service()->populationService()->populate($database, $season, $request->seed);
            $this->services->matchModule()->service()->generateSeasonFixtures(
                $database,
                array_map(static fn ($competition): string => $competition->id()->value(), $this->services->competitionModule()->service()->repository($database)->bySeason($season->id())),
                $season->id(),
            );
        } catch (\Throwable $exception) {
            if ($created) {
                $path = $this->projectRoot . '/game/saves/' . $request->careerId . '.sqlite';
                if (is_file($path)) { unlink($path); }
            }
            throw $exception;
        }
    }

    private function startService(): YouthCareerStartService
    {
        return new YouthCareerStartService(
            $this->services->playerModule()->service(),
            $this->services->clubModule()->service(),
            $this->services->competitionModule()->service(),
            $this->services->contractModule()->service(),
            $this->services->playerFinanceService(),
        );
    }

    private function season(): Season
    {
        return new Season(
            new SeasonId('season-2024-25'),
            '2024/25',
            SimulationDate::fromIsoString('2024-08-01'),
            SimulationDate::fromIsoString('2025-05-31'),
        );
    }

    private function world(CareerStartRequest $request, Season $season): World
    {
        $calendar = $this->services->worldModule()->service()->calendar();

        return new World(
            new WorldId($request->careerId),
            $request->name . ' career',
            $request->seed,
            new DateTimeImmutable('@0'),
            $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')),
            $season->id(),
            array_map(static fn ($nation): string => $nation->id()->value(), $this->services->nationModule()->service()->loadSelected()),
            array_map(static fn ($competition): string => $competition->id()->value(), $this->services->competitionModule()->service()->loadSelected()),
            $this->services->contentPackages()->selectedIds(),
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) { return; }
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file)) { unlink($file); }
        }
        @rmdir($directory);
    }
}
