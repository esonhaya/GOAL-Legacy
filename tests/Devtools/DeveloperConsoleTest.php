<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Devtools;

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Devtools\BufferedConsoleOutput;
use Goal\Legacy\Devtools\CommandRegistry;
use Goal\Legacy\Devtools\ConsoleApplication;
use Goal\Legacy\Devtools\Commands\CoreSelfCheckCommand;
use Goal\Legacy\Devtools\Commands\DoctorCommand;
use Goal\Legacy\Devtools\Commands\InspectConfigurationCommand;
use Goal\Legacy\Devtools\Commands\InspectLogsCommand;
use Goal\Legacy\Devtools\Commands\ListModulesCommand;
use PHPUnit\Framework\TestCase;

final class DeveloperConsoleTest extends TestCase
{
    public function testCoreSelfCheckCommandRunsInIsolation(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        $output = new BufferedConsoleOutput();
        $commands = new CommandRegistry();
        $commands->register(new CoreSelfCheckCommand($services));

        $exitCode = (new ConsoleApplication($commands))->run(['console.php', 'core:self-check'], $output);

        self::assertSame(0, $exitCode);
        self::assertSame(['Core self-check passed.'], $output->messages());
        self::assertSame([], $output->errors());
    }

    public function testRequiredDeveloperCommandsAreRegisteredAndExtensible(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        $commands = new CommandRegistry();
        foreach ([
            new DoctorCommand($services, $root),
            new ListModulesCommand($services),
            new CoreSelfCheckCommand($services),
            new InspectConfigurationCommand($services),
            new InspectLogsCommand($services, $root),
        ] as $command) {
            $commands->register($command);
        }

        $output = new BufferedConsoleOutput();
        self::assertSame(0, (new ConsoleApplication($commands))->run(['console.php', 'modules:list'], $output));
        self::assertContains('No modules registered.', $output->messages());
        self::assertSame(0, (new ConsoleApplication($commands))->run(['console.php', 'doctor'], new BufferedConsoleOutput()));
        self::assertNotNull($commands->get('core:self-check'));
    }
}
