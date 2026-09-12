<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Devtools\CommandRegistry;
use Goal\Legacy\Devtools\ConsoleApplication;
use Goal\Legacy\Devtools\StreamConsoleOutput;
use Goal\Legacy\Devtools\Commands\CoreSelfCheckCommand;
use Goal\Legacy\Devtools\Commands\DoctorCommand;
use Goal\Legacy\Devtools\Commands\InspectConfigurationCommand;
use Goal\Legacy\Devtools\Commands\InspectLogsCommand;
use Goal\Legacy\Devtools\Commands\ListModulesCommand;

$projectRoot = dirname(__DIR__, 2);
$services = (new Bootstrap())->create($projectRoot);
$commands = new CommandRegistry();
$commands->register(new DoctorCommand($services, $projectRoot));
$commands->register(new ListModulesCommand($services));
$commands->register(new CoreSelfCheckCommand($services));
$commands->register(new InspectConfigurationCommand($services));
$commands->register(new InspectLogsCommand($services, $projectRoot));

$application = new ConsoleApplication($commands);
exit($application->run($argv, new StreamConsoleOutput(STDOUT, STDERR)));
