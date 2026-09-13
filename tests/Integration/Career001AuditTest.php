<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Devtools\Commands\CareerSeasonAuditCommand;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use PHPUnit\Framework\TestCase;

final class Career001AuditTest extends TestCase
{
    public function testFullSeasonAuditIsDeterministicAndConsistent(): void
    {
        $output = new class implements ConsoleOutputInterface {
            /** @var list<string> */
            public array $lines = [];
            public function write(string $message): void { $this->lines[] = $message; }
            public function error(string $message): void { $this->lines[] = 'ERROR ' . $message; }
        };
        $command = new CareerSeasonAuditCommand((new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']));

        self::assertSame(0, $command->execute(['--seed=8001'], $output));
        $text = implode("\n", $output->lines);
        self::assertStringContainsString('big5_fixtures=1752', $text);
        self::assertStringContainsString('SAVE_RELOAD equivalent=yes midpoint=yes', $text);
        self::assertStringContainsString('completed_fixture_count=380', $text);
        self::assertStringContainsString('profile=prodigy', $text);
        self::assertStringContainsString('profile=late_bloomer', $text);
    }
}
