<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Devtools\CommandRegistry;
use Goal\Legacy\Devtools\ConsoleApplication;
use Goal\Legacy\Devtools\StreamConsoleOutput;
use Goal\Legacy\Devtools\Commands\CoreSelfCheckCommand;
use Goal\Legacy\Devtools\Commands\ContentListCommand;
use Goal\Legacy\Devtools\Commands\DoctorCommand;
use Goal\Legacy\Devtools\Commands\InspectConfigurationCommand;
use Goal\Legacy\Devtools\Commands\InspectLogsCommand;
use Goal\Legacy\Devtools\Commands\ListModulesCommand;
use Goal\Legacy\Devtools\Commands\PersistenceSelfCheckCommand;
use Goal\Legacy\Devtools\Commands\TimeSelfCheckCommand;
use Goal\Legacy\Devtools\Commands\NationListCommand;
use Goal\Legacy\Devtools\Commands\NationSelfCheckCommand;
use Goal\Legacy\Devtools\Commands\CompetitionListCommand;
use Goal\Legacy\Devtools\Commands\ClubListCommand;
use Goal\Legacy\Devtools\Commands\PlayerCreateDemoCommand;
use Goal\Legacy\Devtools\Commands\WorldSelfCheckCommand;
use Goal\Legacy\Devtools\Commands\TransferSelfCheckCommand;

$projectRoot = dirname(__DIR__, 2);
$services = (new Bootstrap())->create($projectRoot);
$commands = new CommandRegistry();
$commands->register(new DoctorCommand($services, $projectRoot));
$commands->register(new ListModulesCommand($services));
$commands->register(new CoreSelfCheckCommand($services));
$commands->register(new InspectConfigurationCommand($services));
$commands->register(new InspectLogsCommand($services, $projectRoot));
$commands->register(new TimeSelfCheckCommand($services));
$commands->register(new PersistenceSelfCheckCommand($services));
$commands->register(new ContentListCommand($services));
$commands->register(new NationListCommand($services));
$commands->register(new NationSelfCheckCommand($services));
$commands->register(new CompetitionListCommand($services));
$commands->register(new ClubListCommand($services));
$commands->register(new PlayerCreateDemoCommand($services));
$commands->register(new WorldSelfCheckCommand($services));
$commands->register(new TransferSelfCheckCommand($services));

$application = new ConsoleApplication($commands);
exit($application->run($argv, new StreamConsoleOutput(STDOUT, STDERR)));
