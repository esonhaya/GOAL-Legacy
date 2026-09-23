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

final class P3003PulseIdentityCultureTest extends TestCase
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

    public function testIdentitiesCulturesWeightingAndMemoryAreStable(): void
    {
        [$services, $database, $season, $store] = $this->scenario('p3003-global');
        $player = $this->player($services, $database, $season, 'p3003-global-player', 'spain', 'arsenal');
        $pulse = $services->playerModule()->service()->pulseService();
        $social = $services->playerModule()->service()->socialService();

        $social->recordAchievement($database, $player->id(), SimulationDate::fromIsoString('2025-04-01'), 'milestone|p3003|premier-league', 'Strong Premier League performance', 'major', 'arsenal');
        $social->recordAchievement($database, $player->id(), SimulationDate::fromIsoString('2025-04-02'), 'award|p3003|international', 'International award', 'major');
        for ($index = 3; $index <= 22; ++$index) {
            $social->recordAchievement($database, $player->id(), SimulationDate::fromIsoString('2025-04-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT)), 'milestone|p3003|' . $index, 'Career marker ' . $index, 'major', $index % 2 === 0 ? 'real-madrid' : 'arsenal');
        }

        $feed = $pulse->feed($database, $player->id(), 200);
        $identities = array_values(array_unique(array_filter(array_column($feed, 'identity_id'))));
        $cultures = array_values(array_unique(array_column($feed, 'identity_culture')));
        self::assertGreaterThanOrEqual(4, count($identities));
        self::assertContains('england', $cultures);
        self::assertContains('spain', $cultures);
        self::assertNotEmpty(array_filter($feed, static fn (array $post): bool => $post['identity_name'] !== null && $post['identity_handle'] !== null));
        self::assertNotEmpty(array_filter($feed, static fn (array $post): bool => str_contains(strtolower((string) $post['text']), 'fútbol') || str_contains(strtolower((string) $post['text']), 'away end') || str_contains(strtolower((string) $post['text']), 'supporters')));
        $native = array_filter($feed, static fn (array $post): bool => preg_match('/Qué|Meu amigo|Hermano|Grabe|Abeg|Sugoi|golazo/u', (string) $post['text']) === 1);
        self::assertNotEmpty($native);
        self::assertLessThan(count($feed) / 2 + 1, count($native));
        self::assertNotEmpty(array_filter($feed, static fn (array $post): bool => (int) ($post['depth'] ?? 0) > 0 && ($post['identity_culture'] ?? 'global') !== 'global'));
        $memory = array_values(array_filter($feed, static function (array $post): bool {
            foreach ((array) ($post['context']['reaction_meta'] ?? []) as $meta) {
                if (is_array($meta) && str_starts_with((string) ($meta['family'] ?? ''), 'memory|')) { return true; }
            }

            return false;
        }));
        self::assertNotEmpty($memory, 'A recurring identity should eventually use an evidence-backed bounded memory callback.');
        foreach ($memory as $post) { self::assertNotSame('', (string) ($post['identity_id'] ?? '')); }

        $premierLeagueIdentities = array_column(array_values(array_filter($feed, static fn (array $post): bool => $post['source_key'] === 'achievement:milestone|p3003|premier-league')), 'identity_id');
        $pulse->recordTransfer($database, $player->id(), 'arsenal', 'real-madrid', SimulationDate::fromIsoString('2025-04-23'));
        $afterTransfer = $pulse->feed($database, $player->id(), 200);
        self::assertSame($premierLeagueIdentities, array_column(array_values(array_filter($afterTransfer, static fn (array $post): bool => $post['source_key'] === 'achievement:milestone|p3003|premier-league')), 'identity_id'));

        fwrite(STDOUT, "\nP3-003 SAMPLE_GLOBAL\n");
        foreach (array_slice($afterTransfer, 0, 14) as $post) {
            fwrite(STDOUT, sprintf("[%s · @%s · %s] %s\n", $post['identity_name'] ?? 'Legacy account', $post['identity_handle'] ?? 'legacy', $post['identity_culture'] ?? 'global', $post['text']));
        }

        $before = $pulse->feed($database, $player->id(), 200);
        unset($database);
        $reloaded = $store->openDatabase('p3003-global');
        self::assertSame($before, $pulse->feed($reloaded, $player->id(), 200));
        self::assertTrue($pulse->integrity($reloaded, $player->id())['valid']);
    }

    public function testLegacyRootFallbackAndReadSafetyRemainBounded(): void
    {
        [$services, $database, $season] = $this->scenario('p3003-legacy');
        $player = $this->player($services, $database, $season, 'p3003-legacy-player', 'england', 'arsenal');
        $pulse = $services->playerModule()->service()->pulseService();
        $pulse->initializeSchema($database);
        $database->connection()->prepare('INSERT INTO pulse_feed_sources (source_key, player_id, occurred_date, kind, importance, context_json) VALUES (:source_key, :player_id, :date, :kind, :importance, :context)')->execute(['source_key' => 'legacy:p3003', 'player_id' => $player->id()->value(), 'date' => '2024-01-01', 'kind' => 'milestone', 'importance' => 'notable', 'context' => '{}']);
        $database->connection()->prepare('INSERT INTO pulse_posts (id, player_id, source_key, actor_type, actor_id, actor_name, occurred_date, post_text, engagement) VALUES (:id, :player_id, :source_key, :actor_type, :actor_id, :actor_name, :date, :text, :engagement)')->execute(['id' => 'legacy:p3003:post', 'player_id' => $player->id()->value(), 'source_key' => 'legacy:p3003', 'actor_type' => 'fan', 'actor_id' => 'legacy-fan', 'actor_name' => 'Saved Fan', 'date' => '2024-01-01', 'text' => 'Old saved wording stays unchanged.', 'engagement' => 1]);
        $before = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();
        $feed = $pulse->feed($database, $player->id(), 30);
        $pulse->context($database, $player->id());
        self::assertSame($before, (int) $database->connection()->query('SELECT total_changes()')->fetchColumn());
        self::assertSame('Old saved wording stays unchanged.', $feed[0]['text']);
        self::assertNull($feed[0]['identity_id']);
        self::assertSame('global', $feed[0]['identity_culture']);
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

    private function player(object $services, object $database, Season $season, string $id, string $nation, string $club): object
    {
        $players = $services->playerModule()->service();
        $player = $players->create(new PlayerCreationRequest($id, 'Global', 'Pulse', 'Global Pulse', '2002-01-01', $nation, [], $nation, [$nation], 180, 75, 'CM', 90, 'regular', 3003, new PlayerAttributeSet(70, 70, 70, 70, 70, 70)));
        $players->repository($database)->save($player);
        $players->initializeCareer($database, $player, new CareerPlayerReference(new CareerId($id . '-career'), $player->id(), $season->startDate()), new ClubSquadMembership(new ClubId($club), $player->id(), $season->id(), SquadRole::Regular));

        return $player;
    }
}
