<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Devtools;

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Devtools\BufferedConsoleOutput;
use Goal\Legacy\Devtools\Commands\CareerMultiSeasonAuditCommand;
use PHPUnit\Framework\TestCase;

final class CareerPacingObservatoryTest extends TestCase
{
    public function testOneSeasonObservatoryUsesCanonicalLifecycleAndReloadEquivalence(): void
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $output = new BufferedConsoleOutput();

        self::assertSame(0, (new CareerMultiSeasonAuditCommand($services))->execute([
            '--observatory',
            '--archetype=prodigy',
            '--seasons=1',
            '--seed=13007',
            '--equivalence',
        ], $output));

        $messages = implode(PHP_EOL, $output->messages());
        self::assertStringContainsString('OBSERVATORY profile=prodigy', $messages);
        self::assertStringContainsString('CHECKPOINT label=SEASON_1', $messages);
        self::assertStringContainsString('equivalence=pass', $messages);
        self::assertStringContainsString('final_season_status=completed', $messages);
        self::assertSame([], $output->errors());
    }

    public function testObservatoryRejectsAnUnboundedHorizon(): void
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $output = new BufferedConsoleOutput();

        self::assertSame(1, (new CareerMultiSeasonAuditCommand($services))->execute([
            '--observatory',
            '--archetype=regular',
            '--seasons=6',
        ], $output));
        self::assertSame(['Career observatory requires --seasons between 1 and 5.'], $output->errors());
    }
}
