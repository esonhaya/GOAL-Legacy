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
use Goal\Legacy\Devtools\Commands\PersistenceSelfCheckCommand;
use Goal\Legacy\Devtools\Commands\TimeSelfCheckCommand;
use PHPUnit\Framework\TestCase;
use Tools\Doctor\Contracts\CheckIdentityInterface;
use Tools\Doctor\Contracts\CheckInterface;
use Tools\Doctor\DTO\CheckResult;
use Tools\Doctor\DTO\CheckStatus;

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
            new TimeSelfCheckCommand($services),
            new PersistenceSelfCheckCommand($services),
        ] as $command) {
            $commands->register($command);
        }

        $output = new BufferedConsoleOutput();
        self::assertSame(0, (new ConsoleApplication($commands))->run(['console.php', 'modules:list'], $output));
        self::assertContains('No modules registered.', $output->messages());
        $doctorOutput = new BufferedConsoleOutput();
        self::assertSame(0, (new ConsoleApplication($commands))->run(['console.php', 'doctor'], $doctorOutput));
        self::assertStringContainsString('Haya Doctor', implode(PHP_EOL, $doctorOutput->messages()));
        self::assertNotNull($commands->get('core:self-check'));
        self::assertNotNull($commands->get('time:self-check'));
        self::assertNotNull($commands->get('persistence:self-check'));
    }

    public function testTimingSelfCheckUsesBootstrappedClockAndScheduler(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        $output = new BufferedConsoleOutput();
        $command = new TimeSelfCheckCommand($services);

        self::assertSame(0, $command->execute([], $output));
        self::assertSame(['Deterministic timing self-check passed at tick 2.'], $output->messages());
    }

    public function testPersistenceSelfCheckUsesIsolatedStorage(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        $output = new BufferedConsoleOutput();

        self::assertSame(0, (new PersistenceSelfCheckCommand($services))->execute([], $output));
        self::assertSame(['Persistence self-check passed with isolated SQLite storage.'], $output->messages());
    }

    public function testDoctorUsesHayaExitCodeOneForCheckFailure(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        $check = new class implements CheckInterface, CheckIdentityInterface {
            public function id(): string { return 'fixture.failure'; }
            public function run(): CheckResult { return new CheckResult('Fixture failure', CheckStatus::FAIL, scope: 'DOCTOR'); }
            public function category(): string { return 'fixture'; }
            public function priority(): int { return 1; }
        };

        $output = new BufferedConsoleOutput();
        $exitCode = (new DoctorCommand($services, $root, [$check]))->execute([], $output);

        self::assertSame(1, $exitCode);
        self::assertSame([], $output->errors());
    }

    public function testDoctorUsesExitCodeTwoForInfrastructureFailure(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        $output = new BufferedConsoleOutput();

        $exitCode = (new DoctorCommand($services, $root, [new \stdClass()]))->execute([], $output);

        self::assertSame(2, $exitCode);
        self::assertNotEmpty($output->errors());
    }
}
