<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Player\CareerEventCatalog;
use Goal\Legacy\Modules\Player\CareerExperienceService;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class P3013CareerVarietyTest extends TestCase
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

    public function testCatalogIntegrityAndLateCareerEligibilityAreBounded(): void
    {
        self::assertSame([], CareerEventCatalog::validate());
        [$services, $database, $season, $player, $date] = $this->scenario('p3013-audit', 36);
        $fixture = $services->matchModule()->service()->generateFixtures($database, 'premier-league', $season->id())[0];
        (new MatchRepository($database))->save($fixture->complete(new MatchResult(1, 0)));
        $summary = $this->summary($services, $database, $player->id()->value(), $season->id(), $date, $fixture->homeClubId()->value());
        $experience = new CareerExperienceService($services->playerModule()->service()->developmentService(), $services->playerModule()->service()->trainingService());
        $before = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();
        $audit = $experience->auditEligibility($database, $player->id(), $season->id(), $date, $summary);
        $repeat = $experience->auditEligibility($database, $player->id(), $season->id(), $date, $summary);
        $after = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();

        self::assertSame('ready', $audit['status']);
        self::assertContains('late-career-next-phase', array_column($audit['eligible'], 'id'));
        self::assertSame($audit['eligible'], $repeat['eligible']);
        self::assertSame($audit['selected'], $repeat['selected']);
        self::assertSame($before, $after, 'eligibility inspection must not perform gameplay DML');
    }

    public function testAuditSharesSelectionAndReportsResolvedSuppression(): void
    {
        [$services, $database, $season, $player, $date] = $this->scenario('p3013-suppression', 36);
        $fixture = $services->matchModule()->service()->generateFixtures($database, 'premier-league', $season->id())[0];
        (new MatchRepository($database))->save($fixture->complete(new MatchResult(1, 0)));
        $summary = $this->summary($services, $database, $player->id()->value(), $season->id(), $date, $fixture->homeClubId()->value());
        $experience = new CareerExperienceService($services->playerModule()->service()->developmentService(), $services->playerModule()->service()->trainingService());
        $audit = $experience->auditEligibility($database, $player->id(), $season->id(), $date, $summary);
        $event = $experience->ensureEvent($database, $player->id(), $season->id(), $date, $summary);

        self::assertNotNull($event);
        self::assertSame($audit['selected'], $event->definition());
        $experience->resolve($database, $event->id(), 1, $date);
        $after = $experience->auditEligibility($database, $player->id(), $season->id(), $date, $summary);
        $suppressed = array_values(array_filter($after['suppressed'], static fn (array $row): bool => $row['id'] === $event->definition()));

        self::assertCount(1, $suppressed);
        self::assertNotSame('', $suppressed[0]['reason']);
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season,3:\Goal\Legacy\Modules\Player\Domain\Player,4:SimulationDate} */
    private function scenario(string $id, int $age): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 3013, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $services->nationModule()->service()->loadSelected()), array_map(static fn ($competition): string => $competition->id()->value(), $services->competitionModule()->service()->loadSelected()), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);
        $player = $services->playerModule()->service()->create(new PlayerCreationRequest('p3013-player', 'Career', 'Variety', 'Variety Player', sprintf('%04d-08-01', 2024 - $age), 'england', [], 'england', ['england'], 180, 75, 'CM', 74, 'regular', 3013, new PlayerAttributeSet(74, 74, 74, 74, 74, 74)));
        $services->playerModule()->service()->repository($database)->save($player);
        return [$services, $database, $season, $player, SimulationDate::fromIsoString('2024-08-01')];
    }

    /** @return array<string,mixed> */
    private function summary($services, $database, string $playerId, SeasonId $seasonId, SimulationDate $date, string $clubId): array
    {
        $summary = (new PlayerCareerProgressionQuery($services->clubModule()->service()))->summary($database, $playerId, $date, $seasonId);
        $summary['current_club'] = ['id' => $clubId, 'name' => $clubId];
        $summary['current_contract'] = ['end_date' => '2025-05-31'];
        $summary['current_season_id'] = $seasonId->value();
        $summary['current_role'] = 'regular';
        $summary['career_phase'] = 'decline';
        $summary['current_competition'] = ['id' => 'premier-league', 'tier' => 1];
        $summary['transfer_request'] = ['status' => 'none'];
        $summary['manager_context'] = ['playing_time_status' => 'insufficient_evidence', 'trust_label' => 'developing'];
        $summary['club_season'] = null;
        $summary['next_scheduled_match'] = null;
        return $summary;
    }
}
