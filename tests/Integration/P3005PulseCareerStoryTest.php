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

final class P3005PulseCareerStoryTest extends TestCase
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

    public function testCareerMomentsTransferPerspectivesAndMemoryRemainEvidenceBounded(): void
    {
        [$services, $database, $season, $store] = $this->scenario('p3005-career');
        $player = $this->player($services, $database, $season, 'p3005-career-player', 'spain', 'arsenal');
        $social = $services->playerModule()->service()->socialService();
        $pulse = $services->playerModule()->service()->pulseService();
        $events = [
            ['2025-04-01', 'breakthrough|p3005', 'First-team breakthrough'],
            ['2025-04-02', 'form|p3005|strong-run', 'Strong run of form'],
            ['2025-04-03', 'form|p3005|poor-run', 'Poor run'],
            ['2025-04-04', 'form|p3005|return', 'Return to form'],
            ['2025-04-05', 'honour|p3005|trophy', 'League trophy winner'],
            ['2025-04-06', 'retirement|p3005', 'Playing Career complete'],
        ];
        foreach ($events as [$date, $source, $headline]) {
            $social->recordAchievement($database, $player->id(), SimulationDate::fromIsoString($date), $source, $headline, 'major', 'arsenal');
        }
        $social->recordTransfer($database, $player->id(), 'arsenal', 'real-madrid', SimulationDate::fromIsoString('2025-04-07'));

        // More actual evidence gives the bounded identity-memory path a small,
        // deterministic opportunity to pay off without a Career-history scan.
        for ($index = 8; $index <= 18; ++$index) {
            $social->recordAchievement($database, $player->id(), SimulationDate::fromIsoString('2025-04-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT)), 'milestone|p3005|' . $index, 'Career marker ' . $index, 'major', $index % 2 === 0 ? 'arsenal' : 'real-madrid');
        }

        $feed = $pulse->feed($database, $player->id(), 200);
        $families = array_values(array_filter(array_map(fn (array $post): string => (string) ($this->reactionMeta($post)['family'] ?? ''), $feed)));
        self::assertNotEmpty(array_filter($families, static fn (string $family): bool => $family === 'career|breakthrough_belief' || $family === 'career|breakthrough_record'));
        self::assertNotEmpty(array_filter($families, static fn (string $family): bool => $family === 'career|form_run' || $family === 'career|form_run_evidence'));
        self::assertNotEmpty(array_filter($families, static fn (string $family): bool => $family === 'career|form_slump' || $family === 'career|form_slump_context'));
        self::assertNotEmpty(array_filter($families, static fn (string $family): bool => $family === 'career|form_return'));
        self::assertNotEmpty(array_filter($families, static fn (string $family): bool => $family === 'career|trophy_chapter' || $family === 'career|trophy_record'));
        self::assertNotEmpty(array_filter($families, static fn (string $family): bool => str_starts_with($family, 'memory|')));

        $transferPosts = array_values(array_filter($feed, static fn (array $post): bool => str_starts_with((string) $post['source_key'], 'transfer:')));
        self::assertContains('Arsenal', array_column($transferPosts, 'actor_name'));
        self::assertContains('Real Madrid', array_column($transferPosts, 'actor_name'));
        $transferMeta = $transferPosts === [] ? [] : (array) ($transferPosts[0]['context']['reaction_meta'] ?? []);
        self::assertNotEmpty(array_filter($transferMeta, static fn (mixed $meta): bool => is_array($meta) && ($meta['family'] ?? '') === 'career|former_club_departure'));

        foreach ($feed as $post) {
            $text = (string) $post['text'];
            self::assertDoesNotMatchRegularExpression('/I said|memory_excerpt|\.\.\.|…|"/', $text);
            self::assertDoesNotMatchRegularExpression('/\b(always believed|greatest ever|Club legend)\b/i', $text);
        }
        $recentTexts = array_column(array_slice(array_values(array_filter($feed, static fn (array $post): bool => (int) ($post['depth'] ?? 0) === 0)), 0, 24), 'text');
        self::assertNotEmpty($recentTexts);

        fwrite(STDOUT, "\nP3-005 SAMPLE_CAREER_STORY\n");
        $sampleKeys = [
            'achievement:breakthrough|p3005',
            'achievement:form|p3005|strong-run',
            'achievement:form|p3005|poor-run',
            'achievement:form|p3005|return',
            'achievement:honour|p3005|trophy',
            'transfer:transfer:p3005-career-player:2025-04-07',
            'achievement:retirement|p3005',
        ];
        foreach ($sampleKeys as $sampleKey) {
            $post = array_values(array_filter($feed, static fn (array $candidate): bool => $candidate['source_key'] === $sampleKey))[0] ?? null;
            if (!is_array($post)) { continue; }
            fwrite(STDOUT, sprintf("[%s :: %s/%s] %s\n", $sampleKey, $post['identity_name'] ?? 'Legacy account', $post['identity_culture'] ?? 'global', $post['text']));
        }
        $memorySample = array_values(array_filter($feed, fn (array $candidate): bool => str_starts_with((string) ($this->reactionMeta($candidate)['family'] ?? ''), 'memory|')))[0] ?? null;
        if (is_array($memorySample)) {
            fwrite(STDOUT, sprintf("[memory :: %s/%s] %s\n", $memorySample['identity_name'] ?? 'Legacy account', $memorySample['identity_culture'] ?? 'global', $memorySample['text']));
        }

        $before = $pulse->feed($database, $player->id(), 200);
        unset($database);
        $reloaded = $store->openDatabase('p3005-career');
        self::assertSame($before, $pulse->feed($reloaded, $player->id(), 200));
    }

    public function testCareerStoryReadPathsRemainWriteFree(): void
    {
        [$services, $database, $season] = $this->scenario('p3005-read');
        $player = $this->player($services, $database, $season, 'p3005-read-player', 'england', 'arsenal');
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
        $player = $players->create(new PlayerCreationRequest($id, 'Pia', 'Story', 'Pia Story', '2004-01-01', $nation, [], $nation, [$nation], 180, 75, 'CM', 90, 'regular', 5005, new PlayerAttributeSet(70, 70, 70, 70, 70, 70)));
        $players->repository($database)->save($player);
        $players->initializeCareer($database, $player, new CareerPlayerReference(new CareerId($id . '-career'), $player->id(), $season->startDate()), new ClubSquadMembership(new ClubId($club), $player->id(), $season->id(), SquadRole::Regular));

        return $player;
    }

    /** @return array<string, mixed> */
    private function reactionMeta(array $post): array
    {
        foreach ((array) ($post['context']['reaction_meta'] ?? []) as $key => $meta) {
            $identity = ($post['context']['identity_meta'] ?? [])[$key] ?? null;
            if (is_array($identity) && ($identity['name'] ?? null) === ($post['identity_name'] ?? null) && is_array($meta)) { return $meta; }
            if (str_starts_with((string) $key, (string) ($post['actor_type'] ?? 'fan') . '|') && is_array($meta)) { return $meta; }
        }

        return [];
    }
}
