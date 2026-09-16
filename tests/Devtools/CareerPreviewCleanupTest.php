<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Devtools;

use Goal\Legacy\Devtools\Commands\CareerNewCommand;
use PHPUnit\Framework\TestCase;

final class CareerPreviewCleanupTest extends TestCase
{
    public function testCleanupOnlyRemovesThePreviewDirectoryItOwns(): void
    {
        $root = sys_get_temp_dir() . '/career-preview-cleanup-' . bin2hex(random_bytes(4));
        $preview = $root . '/.career-preview-owned';
        $unrelated = $root . '/.career-preview-unrelated';
        mkdir($preview, 0777, true);
        mkdir($unrelated, 0777, true);
        touch($preview . '/save.sqlite');
        touch($unrelated . '/marker');

        try {
            $command = (new \ReflectionClass(CareerNewCommand::class))->newInstanceWithoutConstructor();
            $cleanup = new \ReflectionMethod(CareerNewCommand::class, 'removePreview');
            $cleanup->invoke($command, $preview);

            self::assertDirectoryDoesNotExist($preview);
            self::assertFileExists($unrelated . '/marker');
        } finally {
            if (is_file($unrelated . '/marker')) { unlink($unrelated . '/marker'); }
            if (is_dir($unrelated)) { rmdir($unrelated); }
            if (is_dir($root)) { rmdir($root); }
        }
    }
}
