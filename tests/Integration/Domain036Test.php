<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerStartRequest;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\Player\YouthCareerStartService;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class Domain036Test extends TestCase
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

    public function testYouthCampCreatesCanonicalPlayersAndReloadsPlacedCareers(): void
    {
        [$services, $database, $store, $season] = $this->scenario('domain-036-start');
        $start = new YouthCareerStartService($services->playerModule()->service(), $services->clubModule()->service(), $services->competitionModule()->service(), $services->contractModule()->service());
        $requests = [
            new CareerStartRequest('regular-st', 'Alex Rivera', 'england', 180, 75, 'ST', 'regular', 36001),
            new CareerStartRequest('prodigy-mid', 'Mika Silva', 'spain', 176, 70, 'CM', 'prodigy', 36002),
            new CareerStartRequest('late-def', 'Noah Fischer', 'germany', 188, 82, 'CB', 'late_bloomer', 36003),
            new CareerStartRequest('keeper-start', 'Luca Rossi', 'italy', 193, 84, 'GK', 'regular', 36004),
        ];
        $players = [];
        foreach ($requests as $request) {
            $player = $start->createProspect($request);
            $opportunities = $start->opportunities($database, $player, $season);
            self::assertCount(3, $opportunities);
            self::assertSame(count(array_unique(array_column($opportunities, 'club_id'))), count($opportunities));
            self::assertContains($opportunities[0]['tier'], [1, 2]);
            $start->accept($database, $player, new CareerId($request->careerId), $season, SimulationDate::fromIsoString('2024-07-31'), $opportunities, $opportunities[0]['club_id']);
            $start->accept($database, $player, new CareerId($request->careerId), $season, SimulationDate::fromIsoString('2024-07-31'), $opportunities, $opportunities[0]['club_id']);
            self::assertSame($player->id()->value(), $services->playerModule()->service()->careerRepository($database)->get($request->careerId)->playerId()->value());
            self::assertCount(1, $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $season->id()));
            self::assertCount(1, $services->competitionModule()->service()->registrationRepository($database)->byPlayer($player->id()));
            self::assertNotNull($services->contractModule()->service()->activeForPlayer($database, $player->id()->value()));
            $players[$request->careerId] = $player;
        }
        self::assertGreaterThan($players['regular-st']->attributes()->passing(), $players['prodigy-mid']->attributes()->passing());
        self::assertGreaterThan($players['regular-st']->attributes()->defending(), $players['late-def']->attributes()->defending());
        self::assertGreaterThanOrEqual($players['late-def']->overallRating(), $players['regular-st']->overallRating());
        self::assertGreaterThan($players['regular-st']->overallRating(), $players['prodigy-mid']->overallRating());
        foreach ($players as $player) { self::assertLessThanOrEqual($player->potential(), $player->overallRating()); self::assertLessThanOrEqual(99, $player->potential()); }

        unset($database);
        $database = $store->openDatabase('domain-036-start');
        $query = new PlayerCareerProgressionQuery($services->clubModule()->service());
        foreach ($requests as $request) {
            $player = $players[$request->careerId];
            $summary = $query->summary($database, $player->id(), SimulationDate::fromIsoString('2024-07-31'), $season->id());
            self::assertSame(trim($request->name), $summary['player']['preferred_name']);
            self::assertSame($request->nationId, $summary['player']['primary_nation_id']);
            self::assertSame($request->heightCm, $summary['player']['height_cm']);
            self::assertSame($request->weightKg, $summary['player']['weight_kg']);
            self::assertSame($request->position, $summary['player']['primary_position']);
            self::assertSame($request->archetype, $summary['development_profile']);
            self::assertSame($player->overallRating(), $summary['current_ovr']);
            self::assertSame($player->potential(), $summary['potential']);
            self::assertNotNull($summary['current_club']);
            self::assertSame('rotation', $summary['current_role']);
            self::assertSame('active', $summary['current_contract']['status']);
        }
    }

    public function testInvalidCreationAndOpportunitySelectionAreAtomic(): void
    {
        [$services, $database, , $season] = $this->scenario('domain-036-invalid');
        $start = new YouthCareerStartService($services->playerModule()->service(), $services->clubModule()->service(), $services->competitionModule()->service(), $services->contractModule()->service());
        try { new CareerStartRequest('invalid-name', ' ', 'england', 180, 75, 'ST', 'regular', 1); self::fail('Invalid name must fail.'); } catch (\InvalidArgumentException | \Goal\Legacy\Modules\Nation\Domain\NationException | \Goal\Legacy\Modules\Player\Domain\PlayerException) { self::addToAssertionCount(1); }
        foreach ([
            new CareerStartRequest('invalid-country', 'Alex Test', 'missing', 180, 75, 'ST', 'regular', 1),
            new CareerStartRequest('invalid-height', 'Alex Test', 'england', 119, 75, 'ST', 'regular', 1),
            new CareerStartRequest('invalid-height-high', 'Alex Test', 'england', 251, 75, 'ST', 'regular', 1),
            new CareerStartRequest('invalid-weight-low', 'Alex Test', 'england', 180, 29, 'ST', 'regular', 1),
            new CareerStartRequest('invalid-weight', 'Alex Test', 'england', 180, 201, 'ST', 'regular', 1),
            new CareerStartRequest('invalid-position', 'Alex Test', 'england', 180, 75, 'LIBERO', 'regular', 1),
            new CareerStartRequest('invalid-archetype', 'Alex Test', 'england', 180, 75, 'ST', 'legend', 1),
        ] as $request) {
            try { $start->createProspect($request); self::fail('Invalid creation must fail.'); } catch (\InvalidArgumentException | \Goal\Legacy\Modules\Nation\Domain\NationException | \Goal\Legacy\Modules\Player\Domain\PlayerException) { self::addToAssertionCount(1); }
        }
        $request = new CareerStartRequest('invalid-choice', 'Alex Test', 'england', 180, 75, 'ST', 'regular', 1);
        $player = $start->createProspect($request);
        $opportunities = $start->opportunities($database, $player, $season);
        try { $start->accept($database, $player, new CareerId($request->careerId), $season, SimulationDate::fromIsoString('2024-07-31'), $opportunities, 'not-an-offer'); self::fail('Invalid opportunity must fail.'); } catch (\InvalidArgumentException | \Goal\Legacy\Modules\Nation\Domain\NationException | \Goal\Legacy\Modules\Player\Domain\PlayerException) { self::addToAssertionCount(1); }
        self::assertFalse($services->playerModule()->service()->repository($database)->exists($player->id()));
    }

    /** @return array{0:object,1:object,2:SqliteSaveStore,3:Season} */
    private function scenario(string $id): array
    {
        $root = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(4));
        $this->roots[] = $root;
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 36000, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $services->nationModule()->service()->loadSelected()), array_map(static fn ($competition): string => $competition->id()->value(), $services->competitionModule()->service()->loadSelected()), $services->contentPackages()->selectedIds());
        $store = new SqliteSaveStore($root, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);
        return [$services, $database, $store, $season];
    }
}
