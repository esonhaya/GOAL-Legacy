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

final class P3001PulseVoiceTest extends TestCase
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

    public function testVoicesFamiliesAndDeterministicSampleAreContextual(): void
    {
        [$services, $database, $season, $store] = $this->scenario('p3001-voice');
        $player = $this->player($services, $database, $season, 'p3001-voice-player');
        $pulse = $services->playerModule()->service()->pulseService();
        $social = $services->playerModule()->service()->socialService();
        $pulse->initializeSchema($database);
        $database->connection()->prepare('INSERT INTO pulse_feed_sources (source_key, player_id, occurred_date, kind, importance, context_json) VALUES (:source_key, :player_id, :date, :kind, :importance, :context)')->execute(['source_key' => 'legacy:p3001', 'player_id' => $player->id()->value(), 'date' => '2024-01-01', 'kind' => 'milestone', 'importance' => 'notable', 'context' => '{}']);
        $database->connection()->prepare('INSERT INTO pulse_posts (id, player_id, source_key, actor_type, actor_id, actor_name, occurred_date, post_text, engagement) VALUES (:id, :player_id, :source_key, :actor_type, :actor_id, :actor_name, :date, :text, :engagement)')->execute(['id' => 'legacy:p3001:post', 'player_id' => $player->id()->value(), 'source_key' => 'legacy:p3001', 'actor_type' => 'fan', 'actor_id' => 'legacy-fan', 'actor_name' => 'Saved Fan', 'date' => '2024-01-01', 'text' => 'Old saved Pulse wording.', 'engagement' => 1]);

        $events = [
            ['award|p3001|season', 'Player of the Season'],
            ['honour|p3001|cup', 'National Cup winner'],
            ['record|p3001|appearances', 'Most appearances'],
            ['milestone|p3001|career', '100 Career appearances'],
            ['retirement|p3001|closure', 'Playing Career complete'],
        ];
        foreach ($events as $index => [$source, $headline]) {
            $social->recordAchievement($database, $player->id(), SimulationDate::fromIsoString('2025-05-' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)), $source, $headline, 'major', 'arsenal');
        }
        $pulse->recordAvailabilityChange($database, ['id' => 'p3001-injury', 'player_id' => $player->id()->value(), 'category' => 'muscle', 'start_date' => '2025-04-01'], 'player.injured');
        $pulse->recordAvailabilityChange($database, ['id' => 'p3001-injury', 'player_id' => $player->id()->value(), 'category' => 'muscle', 'start_date' => '2025-04-01', 'actual_recovery_date' => '2025-05-10'], 'player.recovered');

        $feed = $pulse->feed($database, $player->id(), 30);
        $voices = [];
        $families = [];
        $texts = [];
        foreach ($feed as $post) {
            $key = $post['actor_type'] . '|' . $this->actorId($post['actor_type'], $post['actor_name'], $post['context']);
            $meta = $post['context']['reaction_meta'][$key] ?? [];
            if (($meta['voice'] ?? '') !== '') { $voices[(string) $meta['voice']] = true; }
            if (($meta['family'] ?? '') !== '') { $families[(string) $meta['family']] = true; }
            $texts[] = $post['text'];
        }
        self::assertGreaterThanOrEqual(4, count($voices));
        self::assertGreaterThanOrEqual(8, count($families));
        $duplicates = array_filter(array_count_values($texts), static fn (int $count): bool => $count > 1);
        self::assertSame([], $duplicates, json_encode($duplicates, JSON_THROW_ON_ERROR));
        self::assertContains('Old saved Pulse wording.', $texts);
        self::assertStringNotContainsString('twitter', strtolower(implode(' ', $texts)));
        self::assertStringNotContainsString('instagram', strtolower(implode(' ', $texts)));

        fwrite(STDOUT, "\nP3-001 SAMPLE_REACTIONS\n");
        foreach (array_slice($feed, 0, 10) as $post) {
            $key = $post['actor_type'] . '|' . $this->actorId($post['actor_type'], $post['actor_name'], $post['context']);
            $meta = $post['context']['reaction_meta'][$key] ?? [];
            fwrite(STDOUT, sprintf("[%s/%s] %s\n", $meta['voice'] ?? 'legacy', $meta['family'] ?? 'legacy', $post['text']));
        }

        unset($database);
        $reloaded = $store->openDatabase('p3001-voice');
        self::assertSame($feed, $pulse->feed($reloaded, $player->id(), 30));
    }

    public function testLossAndRedCardUseNegativeOrMeasuredContext(): void
    {
        [$services, $database, $season] = $this->scenario('p3001-match-context');
        $player = $this->player($services, $database, $season, 'p3001-match-player');
        $matchRepository = new MatchRepository($database);
        $stats = new PlayerMatchStatRepository($database);
        $pulse = $services->playerModule()->service()->pulseService();

        $loss = new GameMatch(new MatchId('p3001-loss'), new CompetitionId('premier-league'), $season->id(), 1, SimulationDate::fromIsoString('2025-04-10'), new ClubId('arsenal'), new ClubId('chelsea'));
        $loss = $loss->complete(new MatchResult(1, 2));
        $matchRepository->save($loss);
        $stats->replaceForMatch([new PlayerMatchStat($loss->id(), $player->id(), new ClubId('arsenal'), true, true, 90, 1)]);
        $pulse->recordMatch($database, $loss, new PlayerMatchStat($loss->id(), $player->id(), new ClubId('arsenal'), true, true, 90, 1));

        $red = new GameMatch(new MatchId('p3001-red'), new CompetitionId('premier-league'), $season->id(), 1, SimulationDate::fromIsoString('2025-04-11'), new ClubId('arsenal'), new ClubId('chelsea'));
        $red = $red->complete(new MatchResult(0, 1));
        $matchRepository->save($red);
        $redStat = new PlayerMatchStat($red->id(), $player->id(), new ClubId('arsenal'), true, true, 65, 0, 0, null, null, 0, 0, 0, 0, 0, 0, 0, 2, 0, 1);
        $stats->replaceForMatch([$redStat]);
        $pulse->recordMatch($database, $red, $redStat);

        $feed = $pulse->feed($database, $player->id(), 30);
        $lossPosts = array_values(array_filter($feed, static fn (array $post): bool => $post['source_key'] === 'match:p3001-loss:player:p3001-match-player'));
        $redPosts = array_values(array_filter($feed, static fn (array $post): bool => $post['kind'] === 'match_red_card'));
        self::assertNotEmpty($lossPosts);
        self::assertNotEmpty($redPosts);
        self::assertStringContainsString('loss', strtolower(json_encode($lossPosts, JSON_THROW_ON_ERROR)));
        self::assertStringNotContainsString("WE'LL TAKE THAT", implode(' ', array_column($lossPosts, 'text')));
        self::assertStringNotContainsString('what a performance', strtolower(implode(' ', array_column($redPosts, 'text'))));
        self::assertStringContainsString('red', strtolower(implode(' ', array_column($redPosts, 'text'))));
    }

    public function testImmediateSemanticFamilyAndOpeningReuseAreAvoided(): void
    {
        [$services, $database, $season] = $this->scenario('p3001-repeat');
        $player = $this->player($services, $database, $season, 'p3001-repeat-player');
        $social = $services->playerModule()->service()->socialService();
        $pulse = $services->playerModule()->service()->pulseService();
        $social->recordAchievement($database, $player->id(), SimulationDate::fromIsoString('2025-05-01'), 'milestone|p3001|one', '50 appearances', 'major', 'arsenal');
        $social->recordAchievement($database, $player->id(), SimulationDate::fromIsoString('2025-05-02'), 'milestone|p3001|two', '75 appearances', 'major', 'arsenal');
        $posts = array_values(array_filter($pulse->feed($database, $player->id(), 30), static fn (array $post): bool => $post['actor_type'] === 'fan'));
        self::assertGreaterThanOrEqual(2, count($posts));
        $first = $posts[0]['context']['reaction_meta']['fan|supporters:achievement'] ?? [];
        $second = $posts[1]['context']['reaction_meta']['fan|supporters:achievement'] ?? [];
        self::assertNotSame($first['family'] ?? null, $second['family'] ?? null);
        self::assertNotSame($first['opening'] ?? null, $second['opening'] ?? null);
        if (($first['slang'] ?? '') !== '' && ($second['slang'] ?? '') !== '') {
            self::assertNotSame($first['slang'], $second['slang']);
        }
        self::assertNotSame($posts[0]['text'], $posts[1]['text']);
    }

    public function testPulseReadsDoNotWriteAndSelectionIsBounded(): void
    {
        [$services, $database, $season] = $this->scenario('p3001-read');
        $player = $this->player($services, $database, $season, 'p3001-read-player');
        $pulse = $services->playerModule()->service()->pulseService();
        $before = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();
        $pulse->feed($database, $player->id(), 30);
        $pulse->context($database, $player->id());
        $after = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();
        self::assertSame($before, $after);
        self::assertSame([], $pulse->feed($database, $player->id()));
    }

    private function player(object $services, object $database, Season $season, string $id): object
    {
        $players = $services->playerModule()->service();
        $player = $players->create(new PlayerCreationRequest($id, 'Pia', 'Pulse', 'Pia Pulse', '2004-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 90, 'regular', 40, new PlayerAttributeSet(70, 70, 70, 70, 70, 70)));
        $players->repository($database)->save($player);
        $players->initializeCareer($database, $player, new CareerPlayerReference(new CareerId($id . '-career'), $player->id(), $season->startDate()), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Regular));

        return $player;
    }

    private function actorId(string $actorType, string $actorName, array $context): string
    {
        $meta = $context['reaction_meta'] ?? [];
        foreach ($meta as $key => $value) {
            if (str_starts_with((string) $key, $actorType . '|')) { return substr((string) $key, strlen($actorType) + 1); }
        }

        return $actorName;
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
