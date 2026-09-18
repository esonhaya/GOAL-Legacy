<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\Player\CareerEventCatalog;
use Goal\Legacy\Modules\Player\CareerExperienceService;
use Goal\Legacy\Modules\Player\Domain\CareerPriority;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\TrainingFocus;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class Domain042Test extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            foreach (glob($root . '/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
            if (is_dir($root)) { rmdir($root); }
        }
    }

    public function testCareerEventCatalogIsUniqueStructuredAndBounded(): void
    {
        $catalog = CareerEventCatalog::all();
        self::assertGreaterThanOrEqual(30, count($catalog));

        $ids = array_map(static fn (array $event): string => (string) $event['id'], $catalog);
        self::assertCount(count($ids), array_unique($ids));

        $categories = [
            'training', 'recovery', 'team', 'teammates', 'family', 'social', 'friends',
            'lifestyle', 'community', 'media', 'fans', 'adaptation', 'career', 'form',
            'manager', 'role', 'transfer', 'contract', 'season', 'club_culture', 'financial', 'cup', 'europe',
        ];
        $repeatability = ['cooldown', 'once_per_career', 'once_per_club', 'once_per_season'];
        $focuses = array_map(static fn (TrainingFocus $focus): string => $focus->value, TrainingFocus::cases());
        $priorities = array_map(static fn (CareerPriority $priority): string => $priority->value, CareerPriority::cases());

        foreach ($catalog as $event) {
            self::assertNotSame('', trim((string) $event['id']));
            self::assertNotSame('', trim((string) $event['title']));
            self::assertContains($event['category'], $categories);
            self::assertContains($event['repeatability'], $repeatability);
            self::assertGreaterThanOrEqual(2, count($event['choices']));
            $choiceIds = array_map(static fn (array $choice): string => (string) $choice['id'], $event['choices']);
            self::assertCount(count($choiceIds), array_unique($choiceIds));
            foreach ($event['choices'] as $choice) {
                self::assertNotSame('', trim((string) $choice['label']));
                if (isset($choice['focus'])) { self::assertContains($choice['focus'], $focuses); }
                if (isset($choice['priority'])) { self::assertContains($choice['priority'], $priorities); }
            }
            if ($event['chain_id'] !== null) {
                self::assertGreaterThanOrEqual(1, (int) $event['chain_stage']);
            }
        }
    }

    public function testCatalogContainsContextualCareerMomentsAndCallbacks(): void
    {
        $catalog = CareerEventCatalog::all();
        $ids = array_fill_keys(array_map(static fn (array $event): string => (string) $event['id'], $catalog), true);
        foreach (['career-first-appearance', 'career-first-start', 'career-first-goal', 'form-breakout-attention', 'career-transfer-request', 'contract-final-year', 'free-agent-next-step'] as $id) {
            self::assertArrayHasKey($id, $ids);
        }
        $chains = array_values(array_unique(array_filter(array_map(static fn (array $event): ?string => $event['chain_id'], $catalog))));
        self::assertGreaterThanOrEqual(2, count($chains));
        self::assertContains('mentorship', $chains);
        self::assertContains('settling-in', $chains);
    }

    public function testFreeAgentContinueContextReceivesOnlyFreeAgentContent(): void
    {
        [$services, $database, $season] = $this->scenario('domain-042-free-agent');
        $player = $services->playerModule()->service()->create(new PlayerCreationRequest('domain-042-free-agent-player', 'Free', 'Agent', 'Free Agent', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 90, 'regular', 40, new PlayerAttributeSet(50, 50, 50, 50, 50, 50)));
        $services->playerModule()->service()->repository($database)->save($player);
        new MatchRepository($database);
        $experience = new CareerExperienceService($services->playerModule()->service()->developmentService(), $services->playerModule()->service()->trainingService());

        $event = $experience->ensureEvent($database, $player->id(), $season->id(), SimulationDate::fromIsoString('2024-08-01'), [
            'current_club' => null,
            'current_contract' => null,
            'career_stats' => ['appearances' => 0, 'starts' => 0, 'goals' => 0],
            'season_stats' => ['appearances' => 0, 'starts' => 0, 'goals' => 0],
        ]);

        self::assertNotNull($event);
        self::assertSame('free-agent-next-step', $event->definition());
    }

    /** @return array{0: \Goal\Legacy\Core\Bootstrap\CoreServices, 1: \Goal\Legacy\Core\Persistence\DatabaseInterface, 2: Season} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2040, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $season];
    }
}
