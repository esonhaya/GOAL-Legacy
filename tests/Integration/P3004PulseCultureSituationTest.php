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
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
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

final class P3004PulseCultureSituationTest extends TestCase
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

    public function testCultureFallbackAndNaturalMemoryAreBounded(): void
    {
        [$services, $database, $season, $store] = $this->scenario('p3004-culture');
        $player = $this->player($services, $database, $season, 'p3004-culture-player', 'spain', 'arsenal');
        $social = $services->playerModule()->service()->socialService();
        $pulse = $services->playerModule()->service()->pulseService();

        for ($index = 1; $index <= 22; ++$index) {
            $social->recordAchievement($database, $player->id(), SimulationDate::fromIsoString('2025-04-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT)), 'milestone|p3004|' . $index, 'Career marker ' . $index, 'major', $index % 2 === 0 ? 'real-madrid' : 'arsenal');
        }

        $feed = $pulse->feed($database, $player->id(), 200);
        $rules = [
            'calcio' => ['italy'], 'jogo bonito' => ['brazil'], 'futebol' => ['brazil'],
            'fútbol' => ['spain', 'argentina', 'mexico'], 'pinoy' => ['philippines'],
            'grabe' => ['philippines'], 'abeg' => ['nigeria'], 'sugoi' => ['japan'],
            'away end' => ['england'],
        ];
        $native = 0;
        $memory = [];
        foreach ($feed as $post) {
            $culture = (string) ($post['identity_culture'] ?? 'global');
            $text = strtolower((string) $post['text']);
            foreach ($rules as $token => $allowed) {
                if (str_contains($text, $token)) { self::assertContains($culture, $allowed, $token . ' leaked into ' . $culture); }
            }
            if (preg_match('/qué|grabe|abeg|sugoi|meu amigo|hermano|golazo/u', (string) $post['text']) === 1) { ++$native; }
            $meta = $this->reactionMeta($post);
            if (is_array($meta) && str_starts_with((string) ($meta['family'] ?? ''), 'memory|')) { $memory[] = $post; }
        }
        self::assertLessThan(count($feed) / 2 + 1, $native, 'Native expressions remain optional seasoning.');
        self::assertNotEmpty($memory);
        foreach ($memory as $post) {
            $text = (string) $post['text'];
            self::assertDoesNotMatchRegularExpression('/I said|I remember|memory_excerpt|\.\.\.|…|"/', $text);
            self::assertDoesNotMatchRegularExpression('/\b[A-Z][^.!?]{0,38}…/', $text);
        }

        $before = $pulse->feed($database, $player->id(), 200);
        unset($database);
        $reloaded = $store->openDatabase('p3004-culture');
        self::assertSame($before, $pulse->feed($reloaded, $player->id(), 200));
    }

    public function testCanonicalSituationFlagsDriveBoundedBanter(): void
    {
        [$services, $database, $season] = $this->scenario('p3004-situation');
        $player = $this->player($services, $database, $season, 'p3004-situation-player', 'spain', 'arsenal');
        $pulse = $services->playerModule()->service()->pulseService();
        $match = new GameMatch(new MatchId('p3004-derby'), new CompetitionId('premier-league'), $season->id(), 1, SimulationDate::fromIsoString('2025-04-10'), new ClubId('arsenal'), new ClubId('tottenham-hotspur'));
        $match = $match->complete(new MatchResult(2, 1));
        (new MatchRepository($database))->save($match);
        $stat = new PlayerMatchStat($match->id(), $player->id(), new ClubId('arsenal'), true, true, 90, 1);
        (new PlayerMatchStatRepository($database))->replaceForMatch([$stat]);
        $pulse->recordMatch($database, $match, $stat);
        $pulse->recordTransfer($database, $player->id(), 'arsenal', 'real-madrid', SimulationDate::fromIsoString('2025-04-11'));

        $feed = $pulse->feed($database, $player->id(), 100);
        $matchPosts = array_values(array_filter($feed, static fn (array $post): bool => str_starts_with((string) $post['source_key'], 'match:p3004-derby')));
        $transferPosts = array_values(array_filter($feed, static fn (array $post): bool => str_starts_with((string) $post['source_key'], 'transfer:')));
        self::assertNotEmpty($matchPosts);
        self::assertNotEmpty($transferPosts);
        self::assertTrue((bool) ($matchPosts[0]['context']['rivalry'] ?? false) || (bool) ($matchPosts[0]['context']['derby'] ?? false));
        self::assertNotEmpty(array_filter($matchPosts, fn (array $post): bool => str_contains((string) ($this->reactionMeta($post)['family'] ?? ''), 'situation|rivalry_')));
        self::assertNotEmpty(array_filter($transferPosts, fn (array $post): bool => str_contains((string) ($this->reactionMeta($post)['family'] ?? ''), 'situation|cross_border_chapter')));
        self::assertStringContainsString('New football country', implode(' ', array_column($transferPosts, 'text')));
    }

    public function testReadPathsRemainWriteFree(): void
    {
        [$services, $database, $season] = $this->scenario('p3004-read');
        $player = $this->player($services, $database, $season, 'p3004-read-player', 'england', 'arsenal');
        $pulse = $services->playerModule()->service()->pulseService();
        $before = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();
        $pulse->feed($database, $player->id(), 100);
        $pulse->context($database, $player->id());
        self::assertSame($before, (int) $database->connection()->query('SELECT total_changes()')->fetchColumn());
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
        $player = $players->create(new PlayerCreationRequest($id, 'Pia', 'Culture', 'Pia Culture', '2004-01-01', $nation, [], $nation, [$nation], 180, 75, 'CM', 90, 'regular', 40, new PlayerAttributeSet(70, 70, 70, 70, 70, 70)));
        $players->repository($database)->save($player);
        $players->initializeCareer($database, $player, new CareerPlayerReference(new CareerId($id . '-career'), $player->id(), $season->startDate()), new ClubSquadMembership(new ClubId($club), $player->id(), $season->id(), SquadRole::Regular));

        return $player;
    }

    /** @return array<string, mixed> */
    private function reactionMeta(array $post): array
    {
        foreach ((array) ($post['context']['reaction_meta'] ?? []) as $key => $meta) {
            if (str_starts_with((string) $key, (string) ($post['actor_type'] ?? 'fan') . '|') && is_array($meta)) { return $meta; }
        }

        return [];
    }
}
