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
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerStartRequest;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\Player\YouthCareerStartService;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use RuntimeException;

/** CLI Phase-1 entry: preview Youth Camp offers or create one playable save. */
final class CareerNewCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services, private readonly string $projectRoot)
    {
    }

    public function name(): string { return 'career:new'; }

    public function description(): string { return 'Preview Youth Camp offers or create a playable career with --save, --name, --nation, --height, --weight, --position, --archetype, --seed, and --club.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $options = $this->options($arguments);
        $request = new CareerStartRequest(
            $this->required($options, 'save'),
            $this->required($options, 'name'),
            $this->required($options, 'nation'),
            $this->integer($options, 'height'),
            $this->integer($options, 'weight'),
            $this->required($options, 'position'),
            $this->required($options, 'archetype'),
            $this->integer($options, 'seed'),
        );
        $careerId = new CareerId($request->careerId);
        $preview = isset($options['preview']);
        if (!$preview && !isset($options['club'])) {
            throw new RuntimeException('Choose a listed Youth Camp Club with --club=<club-id>, or use --preview first.');
        }
        $previewDirectory = $this->projectRoot . '/game/saves/.career-preview-' . bin2hex(random_bytes(6));
        $destinationStore = $this->services->saveStore();
        if (!$preview && $destinationStore->exists($careerId->value())) {
            throw new RuntimeException(sprintf('Save "%s" already exists; New Career never overwrites a save.', $careerId->value()));
        }
        $store = new SqliteSaveStore($previewDirectory, new JsonSerializer());
        $created = false;
        $database = null;
        try {
            $calendar = $this->services->worldModule()->service()->calendar();
            $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
            $world = new World(new WorldId($careerId->value()), $request->name . ' career', $request->seed, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $this->services->nationModule()->service()->loadSelected()), array_map(static fn ($competition): string => $competition->id()->value(), $this->services->competitionModule()->service()->loadSelected()), $this->services->contentPackages()->selectedIds());
            $store->create(SaveMetadata::create($careerId->value(), $request->name . ' career', $world->currentTime(), new DateTimeImmutable('@0')));
            $database = $store->openDatabase($careerId->value());
            $this->services->worldModule()->service()->initialize($database, $world, $season);
            $this->services->playerModule()->service()->populationService()->populate($database, $season, $request->seed);
            $start = new YouthCareerStartService($this->services->playerModule()->service(), $this->services->clubModule()->service(), $this->services->competitionModule()->service(), $this->services->contractModule()->service());
            $player = $start->createProspect($request);
            $opportunities = $start->opportunities($database, $player, $season);
            if ($opportunities === []) {
                throw new RuntimeException('Youth Camp could not produce a legitimate starting Club opportunity.');
            }
            if ($preview) {
                $output->write(sprintf('Youth Camp preview for %s (%s, %s, OVR %d, potential %d):', $player->preferredName(), $player->primaryPosition()->value, $player->developmentProfile()->value, $player->overallRating(), $player->potential()));
                foreach ($opportunities as $opportunity) {
                    $output->write(sprintf('  %s — %s, %s (tier %d), role: %s, %s. Re-run with --club=%s.', $opportunity['club'], $opportunity['nation_id'], $opportunity['competition'], $opportunity['tier'], $opportunity['role'], $opportunity['context'], $opportunity['club_id']));
                }
                return 0;
            }
            // Materialize the chosen career before population fills its squad.
            // The temporary populated world supplies realistic, repeatable offers.
            $destinationStore->create(SaveMetadata::create($careerId->value(), $request->name . ' career', $world->currentTime(), new DateTimeImmutable('@0')));
            $created = true;
            $database = $destinationStore->openDatabase($careerId->value());
            $this->services->worldModule()->service()->initialize($database, $world, $season);
            $start->accept($database, $player, $careerId, $season, SimulationDate::fromIsoString('2024-07-31'), $opportunities, (string) $options['club']);
            $this->services->playerModule()->service()->populationService()->populate($database, $season, $request->seed);
            foreach ($this->services->competitionModule()->service()->repository($database)->bySeason($season->id()) as $competition) {
                $this->services->matchModule()->service()->generateFixtures($database, $competition->id()->value(), $season->id());
            }
            $summary = (new PlayerCareerProgressionQuery($this->services->clubModule()->service()))->summary($database, $player->id(), SimulationDate::fromIsoString('2024-07-31'), $season->id());
            $output->write(sprintf('Career started: save=%s player=%s club=%s role=%s. Open with: php game/devtools/console.php career:home %s', $careerId->value(), $player->preferredName(), $summary['current_club']['name'] ?? 'unknown', $summary['current_role'] ?? 'unknown', $careerId->value()));

            return 0;
        } catch (\Throwable $exception) {
            if (!$preview && $created) {
                $path = $this->projectRoot . '/game/saves/' . $careerId->value() . '.sqlite';
                if (is_file($path)) { unlink($path); }
            }
            throw $exception;
        } finally {
            unset($database);
            if ($previewDirectory !== null) {
                $this->removePreview($previewDirectory);
            }
        }
    }

    /** @return array<string, string> */
    private function options(array $arguments): array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (!str_starts_with($argument, '--')) { throw new RuntimeException('Career options must use --name=value syntax.'); }
            [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '');
            if ($name === '' || isset($options[$name])) { throw new RuntimeException('Career options must be unique and named.'); }
            $options[$name] = $value;
        }
        return $options;
    }

    private function required(array $options, string $name): string
    {
        $value = trim((string) ($options[$name] ?? ''));
        if ($value === '') { throw new RuntimeException(sprintf('--%s is required.', $name)); }
        return $value;
    }

    private function integer(array $options, string $name): int
    {
        $value = $this->required($options, $name);
        if (filter_var($value, FILTER_VALIDATE_INT) === false) { throw new RuntimeException(sprintf('--%s must be an integer.', $name)); }
        return (int) $value;
    }

    private function removePreview(string $directory): void
    {
        if (!is_dir($directory)) { return; }
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file)) { unlink($file); }
        }
        rmdir($directory);
    }
}
