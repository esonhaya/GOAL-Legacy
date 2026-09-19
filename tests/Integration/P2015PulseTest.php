<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class P2015PulseTest extends TestCase
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

    public function testEchoRoutesCanonicalAchievementAndPulseIsDeterministicAndIdempotent(): void
    {
        [$services, $database, $season, $store] = $this->scenario('p2015-pulse');
        $players = $services->playerModule()->service();
        $player = $players->create(new PlayerCreationRequest('p2015-player', 'Ari', 'Pulse', 'Ari Pulse', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 90, 'regular', 40, new PlayerAttributeSet(68, 68, 68, 68, 68, 68)));
        $players->repository($database)->save($player);
        $players->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('p2015-career'), $player->id(), $season->startDate()), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Regular));

        $pulse = $players->pulseService();
        self::assertSame([], $pulse->feed($database, $player->id()));
        $social = $players->socialService();
        $date = SimulationDate::fromIsoString('2025-05-31');
        $social->recordAchievement($database, $player->id(), $date, 'award|season-2024-25|player-of-season', 'Player of the Season', 'major', 'arsenal');
        $first = $pulse->feed($database, $player->id());
        self::assertNotEmpty($first);
        self::assertContains('media', array_column($first, 'actor_type'));
        self::assertContains('club', array_column($first, 'actor_type'));
        self::assertSame('award', $first[0]['kind']);
        self::assertStringContainsString('Player of the Season', implode(' ', array_column($first, 'text')));
        self::assertSame('Growing Audience', $pulse->context($database, $player->id())['audience_band']);
        $pending = $pulse->pendingResponse($database, $player->id());
        self::assertNotNull($pending);
        self::assertContains('silence', array_column($pending['choices'], 'id'));

        $sourceRows = (int) $database->connection()->query('SELECT COUNT(*) FROM pulse_feed_sources')->fetchColumn();
        $postRows = (int) $database->connection()->query('SELECT COUNT(*) FROM pulse_posts')->fetchColumn();
        $before = $pulse->feed($database, $player->id());
        self::assertTrue($pulse->respond($database, $player->id(), (string) $pending['source_key'], 'team_first', $date));
        self::assertFalse($pulse->respond($database, $player->id(), (string) $pending['source_key'], 'team_first', $date));
        $afterResponse = $pulse->feed($database, $player->id());
        self::assertCount(count($before) + 1, $afterResponse);
        self::assertNull($pulse->pendingResponse($database, $player->id()));
        $social->recordAchievement($database, $player->id(), $date, 'award|season-2024-25|player-of-season', 'Player of the Season', 'major', 'arsenal');
        self::assertSame($sourceRows, (int) $database->connection()->query('SELECT COUNT(*) FROM pulse_feed_sources')->fetchColumn());
        self::assertGreaterThan($postRows, (int) $database->connection()->query('SELECT COUNT(*) FROM pulse_posts')->fetchColumn());
        self::assertTrue($pulse->integrity($database, $player->id())['valid']);

        unset($database);
        $reloaded = $store->openDatabase('p2015-pulse');
        self::assertSame($afterResponse, $pulse->feed($reloaded, $player->id()));
        self::assertTrue($pulse->integrity($reloaded, $player->id())['valid']);
    }

    public function testTransferReactionUsesCanonicalContextAndWorldPlayersReceiveNoPulseState(): void
    {
        [$services, $database, $season] = $this->scenario('p2015-transfer');
        $players = $services->playerModule()->service();
        $player = $players->create(new PlayerCreationRequest('p2015-transfer-player', 'Tia', 'Market', 'Tia Market', '2004-01-01', 'england', [], 'england', ['england'], 180, 75, 'ST', 90, 'regular', 40, new PlayerAttributeSet(70, 70, 70, 70, 70, 70)));
        $players->repository($database)->save($player);
        $players->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('p2015-transfer-career'), $player->id(), $season->startDate()), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Regular));
        $pulse = $players->pulseService();
        $date = SimulationDate::fromIsoString('2024-08-15');
        $players->socialService()->recordTransferRequest($database, $player->id(), $date);
        $feed = $pulse->feed($database, $player->id());
        self::assertNotEmpty($feed);
        self::assertSame('transfer_request', $feed[0]['kind']);
        self::assertStringContainsString('market', strtolower(implode(' ', array_column($feed, 'text'))));
        self::assertSame(1, (int) $database->connection()->query('SELECT COUNT(*) FROM pulse_player_states')->fetchColumn());
        self::assertSame(0, (int) $database->connection()->query("SELECT COUNT(*) FROM pulse_player_states WHERE player_id <> 'p2015-transfer-player'")->fetchColumn());
    }

    public function testInjuryInternationalContextAndPulsePageRemainTruthfulAndReadSafe(): void
    {
        [$services, $database, $season] = $this->scenario('p2015-context');
        $players = $services->playerModule()->service();
        $player = $players->create(new PlayerCreationRequest('p2015-context-player', 'Nia', 'World', 'Nia World', '2004-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 90, 'regular', 40, new PlayerAttributeSet(70, 70, 70, 70, 70, 70)));
        $players->repository($database)->save($player);
        $players->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('p2015-context-career'), $player->id(), $season->startDate()), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Regular));
        $pulse = $players->pulseService();
        $injury = ['id' => 'injury-p2015', 'player_id' => $player->id()->value(), 'category' => 'muscle', 'start_date' => '2024-10-01', 'actual_recovery_date' => null];
        $pulse->recordAvailabilityChange($database, $injury, 'player.injured');
        $pulse->recordAvailabilityChange($database, ['id' => 'injury-p2015', 'player_id' => $player->id()->value(), 'category' => 'muscle', 'start_date' => '2024-10-01', 'actual_recovery_date' => '2024-10-20'], 'player.recovered');
        $social = $players->socialService();
        $social->recordAchievement($database, $player->id(), SimulationDate::fromIsoString('2024-10-20'), 'international|' . $player->id()->value() . '|first_cap', 'International debut', 'major');
        $feedBefore = $pulse->feed($database, $player->id());
        self::assertContains('injury', array_column($feedBefore, 'kind'));
        self::assertContains('return', array_column($feedBefore, 'kind'));
        self::assertContains('national', array_column($feedBefore, 'actor_type'));
        $beforeSources = (int) $database->connection()->query('SELECT COUNT(*) FROM pulse_feed_sources')->fetchColumn();
        $beforePosts = (int) $database->connection()->query('SELECT COUNT(*) FROM pulse_posts')->fetchColumn();
        self::assertSame($beforeSources, (int) $database->connection()->query('SELECT COUNT(*) FROM pulse_feed_sources')->fetchColumn());
        self::assertSame($beforePosts, (int) $database->connection()->query('SELECT COUNT(*) FROM pulse_posts')->fetchColumn());
        self::assertTrue($pulse->integrity($database, $player->id())['valid']);
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season,3:SqliteSaveStore} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2040, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true); $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $season, $store];
    }
}
