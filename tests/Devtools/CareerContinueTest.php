<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Devtools;

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Devtools\BufferedConsoleOutput;
use Goal\Legacy\Devtools\Commands\CareerContinueCommand;
use Goal\Legacy\Devtools\Commands\CareerNewCommand;
use PHPUnit\Framework\TestCase;

final class CareerContinueTest extends TestCase
{
    public function testContinueAdvancesToControlledMatchAndPersistsResult(): void
    {
        $root = sys_get_temp_dir() . '/domain037-continue-' . bin2hex(random_bytes(4));
        mkdir($root, 0775, true);
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $store = new SqliteSaveStore($root, new JsonSerializer());
        $new = new CareerNewCommand($services, dirname(__DIR__, 2), $store);
        $args = ['--preview', '--save=domain037-continue', '--name=Alex Rivera', '--nation=england', '--height=180', '--weight=75', '--position=CM', '--archetype=regular', '--seed=37001'];
        $preview = new BufferedConsoleOutput();
        self::assertSame(0, $new->execute($args, $preview));
        preg_match('/--club=([A-Za-z0-9_-]+)/', implode(PHP_EOL, $preview->messages()), $match);
        self::assertNotEmpty($match[1] ?? null);
        $created = new BufferedConsoleOutput();
        self::assertSame(0, $new->execute(array_merge(array_values(array_filter($args, static fn (string $arg): bool => $arg !== '--preview')), ['--club=' . $match[1]]), $created));

        $continued = new BufferedConsoleOutput();
        self::assertSame(0, (new CareerContinueCommand($services, $store))->execute(['domain037-continue'], $continued));
        $text = implode(PHP_EOL, $continued->messages());
        self::assertStringContainsString('MATCH RESULT', $text);
        self::assertStringContainsString('CAREER HOME', $text);
        self::assertNotEmpty($store->openDatabase('domain037-continue'));

        foreach (glob($root . '/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
        rmdir($root);
    }
}
