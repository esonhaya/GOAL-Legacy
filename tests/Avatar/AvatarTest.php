<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Avatar;

use Goal\Legacy\Core\Persistence\SqliteDatabase;
use Goal\Legacy\Modules\Nation\Domain\NationId;
use Goal\Legacy\Modules\Player\Avatar\AvatarCatalog;
use Goal\Legacy\Modules\Player\Avatar\AvatarCatalogValidator;
use Goal\Legacy\Modules\Player\Avatar\PlayerAppearanceGenerator;
use Goal\Legacy\Modules\Player\Avatar\PortraitRenderer;
use Goal\Legacy\Modules\Player\Domain\DevelopmentProfile;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerAppearance;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Persistence\PlayerAppearanceRepository;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PHPUnit\Framework\TestCase;

final class AvatarTest extends TestCase
{
    public function testCatalogHasCompleteV1CoverageAndValidSvgComponents(): void
    {
        $catalog = new AvatarCatalog();
        self::assertSame([], (new AvatarCatalogValidator())->validate($catalog));
        self::assertCount(12, $catalog->assets('face'));
        self::assertCount(48, $catalog->assets('hair'));
        self::assertCount(20, $catalog->assets('facial_hair'));
        self::assertCount(12, $catalog->palettes('skin'));
        self::assertCount(8, $catalog->palettes('eye'));
        self::assertCount(12, $catalog->palettes('hair'));
        self::assertCount(16, $catalog->presets());
    }

    public function testNpcAppearanceIsDeterministicAndPersistenceIsTiny(): void
    {
        $player = $this->player();
        $date = SimulationDate::fromIsoString('2024-08-01');
        $generator = new PlayerAppearanceGenerator();
        $first = $generator->generate($player, $date);
        self::assertSame($first->toArray(), $generator->generate($player, $date)->toArray());

        $database = new SqliteDatabase(':memory:');
        $repository = new PlayerAppearanceRepository($database);
        $repository->save($player->id()->value(), $first);
        self::assertSame($first->toArray(), $repository->get($player->id()->value())?->toArray());
        self::assertLessThan(1000, (int) $database->connection()->query('SELECT length(appearance_json) FROM player_appearances')->fetchColumn());
    }

    public function testRendererSupportsSizesCacheAndMissingAssetFallback(): void
    {
        $player = $this->player();
        $appearance = (new PlayerAppearanceGenerator())->generate($player, SimulationDate::fromIsoString('2024-08-01'));
        $root = sys_get_temp_dir() . '/goal-avatar-' . bin2hex(random_bytes(5));
        $renderer = new PortraitRenderer(cacheRoot: $root);
        foreach ([32, 64, 128, 256, 512] as $size) {
            $path = $renderer->render($appearance, ['background' => 'career', 'expression' => 'happy'], $size);
            self::assertFileExists($path);
            self::assertStringContainsString('viewBox="0 0 512 512"', (string) file_get_contents($path));
            self::assertSame($path, $renderer->render($appearance, ['background' => 'career', 'expression' => 'happy'], $size));
        }
        $fallback = $appearance->withChanges(['hair' => 'avatar.hair.removed.99']);
        self::assertStringContainsString('<svg', $renderer->renderSvg($fallback, [], 64));
        foreach (glob($root . '/*/*') ?: [] as $file) { unlink($file); }
        foreach (glob($root . '/*') ?: [] as $directory) { rmdir($directory); }
        rmdir($root);
    }

    private function player(): Player
    {
        return new Player(new PlayerId('avatar-test-player'), 'Ava', 'Tester', 'Ava', SimulationDate::fromIsoString('2005-01-01'), new NationId('england'), [], new NationId('england'), [new NationId('england')], 180, 75, PlayerPosition::CentralMidfielder, new PlayerAttributeSet(50, 50, 50, 50, 50, 50), 85, DevelopmentProfile::Regular, 4042);
    }
}
