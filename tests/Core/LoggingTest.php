<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Core;

use Goal\Legacy\Core\Logging\FileLogger;
use Goal\Legacy\Core\Logging\LogLevel;
use PHPUnit\Framework\TestCase;

final class LoggingTest extends TestCase
{
    public function testFileLoggerWritesStructuredCoreRecord(): void
    {
        $directory = sys_get_temp_dir() . '/goal-legacy-logs-' . bin2hex(random_bytes(4));
        $path = $directory . '/core.log';
        try {
            $logger = new FileLogger($path);
            $logger->error('core.test', 'A test error.', ['attempt' => 1]);
            $record = file_get_contents($path);
            self::assertIsString($record);
            self::assertStringContainsString('ERROR', $record);
            self::assertStringContainsString('[core.test]', $record);
            self::assertStringContainsString('"attempt":1', $record);
            self::assertMatchesRegularExpression('/^\[[^\]]+\]/', $record);
        } finally {
            if (is_file($path)) { unlink($path); }
            if (is_dir($directory)) { rmdir($directory); }
        }
    }

    public function testMinimumLevelFiltersLowerPriorityRecords(): void
    {
        $path = sys_get_temp_dir() . '/goal-legacy-log-' . bin2hex(random_bytes(4));
        try {
            $logger = new FileLogger($path, LogLevel::Warning);
            $logger->info('core.test', 'Ignored.');
            self::assertFileDoesNotExist($path);
        } finally {
            if (is_file($path)) { unlink($path); }
        }
    }
}
