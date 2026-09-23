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
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
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

final class P3002PulseThreadTest extends TestCase
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

    public function testRepliesQuotesAndCallbacksStayBoundedAndReferenceRealParents(): void
    {
        [$services, $database, $season] = $this->scenario('p3002-threads');
        $player = $this->player($services, $database, $season, 'p3002-thread-player');
        $pulse = $services->playerModule()->service()->pulseService();
        $pulse->recordTransfer($database, $player->id(), 'arsenal', 'chelsea', SimulationDate::fromIsoString('2025-04-14'));
        $pulse->recordAchievement($database, $player->id(), SimulationDate::fromIsoString('2025-04-13'), 'honour|p3002|trophy', 'National Cup winner', 'major', 'arsenal');
        for ($index = 1; $index <= 10; ++$index) {
            $pulse->recordAchievement($database, $player->id(), SimulationDate::fromIsoString('2025-04-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT)), 'milestone|p3002|' . $index, 'Milestone ' . $index, 'major', 'arsenal');
        }
        $feed = $pulse->feed($database, $player->id(), 200);
        $byId = array_column($feed, null, 'id');
        $children = array_values(array_filter($feed, static fn (array $post): bool => (int) ($post['depth'] ?? 0) > 0));
        self::assertNotEmpty($children);
        self::assertLessThanOrEqual(2, max(array_column($children, 'depth')));
        $intents = [];
        $patterns = [];
        $voices = [];
        foreach ($children as $child) {
            $parentId = (string) ($child['parent_id'] ?? $child['quote_id'] ?? '');
            self::assertNotSame('', $parentId);
            self::assertArrayHasKey($parentId, $byId);
            self::assertSame($child['source_key'], $byId[$parentId]['source_key']);
            self::assertNotSame($child['text'], $byId[$parentId]['text']);
            self::assertNotSame('', (string) ($child['intent'] ?? ''), json_encode($child, JSON_THROW_ON_ERROR));
            $intents[(string) $child['intent']] = true;
            $patterns[(string) $child['pattern_family']] = true;
            if (($child['voice'] ?? null) !== null) { $voices[(string) $child['voice']] = true; }
        }
        self::assertGreaterThanOrEqual(2, count($intents));
        foreach (['agree', 'disagree', 'reluctant_agreement', 'rival_banter', 'defend_player', 'callback'] as $intent) {
            self::assertArrayHasKey($intent, $intents);
        }
        self::assertGreaterThanOrEqual(5, count($patterns));
        self::assertGreaterThanOrEqual(2, count($voices));
        self::assertGreaterThan(0, count(array_filter($children, static fn (array $post): bool => $post['quote_id'] !== null)));
        self::assertGreaterThan(0, count(array_filter($children, static fn (array $post): bool => (int) $post['depth'] === 2)));
        self::assertTrue($pulse->integrity($database, $player->id())['valid']);

        fwrite(STDOUT, "\nP3-002 SAMPLE_THREADS\n");
        $printed = 0;
        foreach ($feed as $post) {
            if ((int) ($post['depth'] ?? 0) !== 0 || $printed >= 3) { continue; }
            $thread = array_values(array_filter($feed, static fn (array $candidate): bool => $candidate['thread_root_id'] === $post['id'] && (int) ($candidate['depth'] ?? 0) > 0));
            if ($thread === []) { continue; }
            fwrite(STDOUT, 'ROOT: ' . $post['text'] . "\n");
            foreach ($thread as $child) { fwrite(STDOUT, str_repeat('  ', (int) $child['depth']) . strtoupper((string) $child['intent']) . ': ' . $child['text'] . "\n"); }
            ++$printed;
        }
        self::assertGreaterThanOrEqual(1, $printed);
    }

    public function testThreadStructureWordingReloadAndDuplicateGenerationAreStable(): void
    {
        [$services, $database, $season, $store] = $this->scenario('p3002-reload');
        $player = $this->player($services, $database, $season, 'p3002-reload-player');
        $pulse = $services->playerModule()->service()->pulseService();
        $date = SimulationDate::fromIsoString('2025-05-01');
        $pulse->recordAchievement($database, $player->id(), $date, 'award|p3002|reload', 'Player of the Season', 'major', 'arsenal');
        $first = $pulse->feed($database, $player->id(), 200);
        $sources = (int) $database->connection()->query('SELECT COUNT(*) FROM pulse_feed_sources')->fetchColumn();
        $posts = (int) $database->connection()->query('SELECT COUNT(*) FROM pulse_posts')->fetchColumn();
        $edges = (int) $database->connection()->query('SELECT COUNT(*) FROM pulse_thread_edges')->fetchColumn();
        $pulse->recordAchievement($database, $player->id(), $date, 'award|p3002|reload', 'Player of the Season', 'major', 'arsenal');
        self::assertSame($first, $pulse->feed($database, $player->id(), 200));
        self::assertSame($sources, (int) $database->connection()->query('SELECT COUNT(*) FROM pulse_feed_sources')->fetchColumn());
        self::assertSame($posts, (int) $database->connection()->query('SELECT COUNT(*) FROM pulse_posts')->fetchColumn());
        self::assertSame($edges, (int) $database->connection()->query('SELECT COUNT(*) FROM pulse_thread_edges')->fetchColumn());
        unset($database);
        $reloaded = $store->openDatabase('p3002-reload');
        self::assertSame($first, $pulse->feed($reloaded, $player->id(), 200));
        self::assertTrue($pulse->integrity($reloaded, $player->id())['valid']);
    }

    public function testContextualTransferRedCardLegacyRootAndReadSafety(): void
    {
        [$services, $database, $season] = $this->scenario('p3002-context');
        $player = $this->player($services, $database, $season, 'p3002-context-player');
        $pulse = $services->playerModule()->service()->pulseService();
        $pulse->recordTransfer($database, $player->id(), 'arsenal', 'chelsea', SimulationDate::fromIsoString('2025-05-02'));
        $match = new GameMatch(new MatchId('p3002-red'), new CompetitionId('premier-league'), $season->id(), 1, SimulationDate::fromIsoString('2025-05-03'), new ClubId('arsenal'), new ClubId('chelsea'));
        $match = $match->complete(new MatchResult(0, 1));
        (new MatchRepository($database))->save($match);
        $stat = new PlayerMatchStat($match->id(), $player->id(), new ClubId('arsenal'), true, true, 65, 0, 0, null, null, 0, 0, 0, 0, 0, 0, 0, 2, 0, 1);
        (new PlayerMatchStatRepository($database))->replaceForMatch([$stat]);
        $pulse->recordMatch($database, $match, $stat);
        $feed = $pulse->feed($database, $player->id(), 200);
        $redPosts = array_values(array_filter($feed, static fn (array $post): bool => $post['kind'] === 'match_red_card'));
        self::assertNotEmpty($redPosts);
        foreach (array_filter($redPosts, static fn (array $post): bool => (int) $post['depth'] > 0) as $post) {
            self::assertStringNotContainsString("THAT'S MY", $post['text']);
            fwrite(STDOUT, 'RED_CARD ' . strtoupper((string) $post['intent']) . ': ' . $post['text'] . "\n");
        }
        $before = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();
        $pulse->feed($database, $player->id(), 200);
        $pulse->context($database, $player->id());
        self::assertSame($before, (int) $database->connection()->query('SELECT total_changes()')->fetchColumn());
        $legacy = array_values(array_filter($feed, static fn (array $post): bool => $post['depth'] === 0 && $post['parent_id'] === null && $post['quote_id'] === null));
        self::assertNotEmpty($legacy);
    }

    private function player(object $services, object $database, Season $season, string $id): object
    {
        $players = $services->playerModule()->service();
        $player = $players->create(new PlayerCreationRequest($id, 'Tess', 'Thread', 'Tess Thread', '2004-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 90, 'regular', 40, new PlayerAttributeSet(70, 70, 70, 70, 70, 70)));
        $players->repository($database)->save($player);
        $players->initializeCareer($database, $player, new CareerPlayerReference(new CareerId($id . '-career'), $player->id(), $season->startDate()), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Regular));

        return $player;
    }

    /** @return array{0:object,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season,3:SqliteSaveStore} */
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
