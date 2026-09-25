<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Simulation\SimulationScenario;
use Goal\Legacy\Core\Simulation\SimulationToolkit;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Goal\Legacy\Devtools\Simulation\GoalSimulationAdapter;
use RuntimeException;

/** One discoverable CLI frontend for the shared simulation toolkit. */
final class HayaSimulationCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services, private readonly string $projectRoot) {}

    public function name(): string { return 'haya:simulation'; }
    public function description(): string { return 'Discover, inspect, diagnose, or run bounded GOAL simulations through Haya Toolkit.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $adapter = new GoalSimulationAdapter($this->services, $this->projectRoot);
        $toolkit = new SimulationToolkit($adapter);
        $operation = (string) ($arguments[0] ?? 'capabilities');
        if ($operation === 'capabilities') {
            foreach ($toolkit->capabilityDescriptors() as $capability) { $output->write(sprintf('%s [%s] %s permission=%s', $capability['id'], $capability['read_only'] ? 'READ' : 'WRITE', $capability['description'], $capability['permission'])); }
            return 0;
        }
        $options = $this->options(array_slice($arguments, 1));
        if ($operation === 'scenarios') {
            $output->write(json_encode($adapter->scenarioDescriptors(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return 0;
        }
        if ($operation === 'inspect') {
            $saveId = $this->required($options, 'save');
            $output->write(json_encode($toolkit->inspect($saveId), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return 0;
        }
        if ($operation === 'diagnostics') {
            $saveId = $this->required($options, 'save');
            foreach ($toolkit->diagnostics($saveId) as $diagnostic) { $output->write(json_encode($diagnostic->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); }
            return 0;
        }
        if ($operation === 'run') {
            $horizon = max(1, min(5, (int) ($options['seasons'] ?? 1)));
            $result = $toolkit->run(new SimulationScenario('goal-legacy', (int) ($options['seed'] ?? 13008), $horizon, ['START', 'SEASON_1', 'SEASON_3', 'SEASON_5'], ['archetype' => $options['archetype'] ?? 'regular']));
            foreach ($result->checkpoints() as $checkpoint) { $output->write(json_encode($checkpoint->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); }
            $output->write('SIMULATION status=' . $result->status());
            return 0;
        }
        if ($operation === 'match' || $operation === 'matches') {
            $saveId = $this->required($options, 'save');
            $count = max(1, min(10, (int) ($options['count'] ?? 1)));
            $result = $adapter->runMatches($saveId, $count, isset($options['match']) ? (string) $options['match'] : null);
            $output->write(json_encode(['status' => $result->status(), 'checkpoints' => array_map(static fn ($checkpoint): array => $checkpoint->toArray(), $result->checkpoints()), 'metrics' => $result->metrics()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return 0;
        }
        if ($operation === 'scenario') {
            $scenario = $this->required($options, 'scenario');
            $result = $adapter->runScenario($scenario, (int) ($options['seed'] ?? 3009));
            $output->write(json_encode(['status' => $result->status(), 'checkpoints' => array_map(static fn ($checkpoint): array => $checkpoint->toArray(), $result->checkpoints()), 'metrics' => $result->metrics()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return 0;
        }

        throw new RuntimeException('Usage: haya:simulation [capabilities|scenarios|inspect|diagnostics|run|match|matches|scenario] --save=<id> [--count=1..10] [--scenario=ID] [--seasons=1..5] [--seed=n] [--archetype=regular].');
    }

    /** @return array<string,string> */
    private function options(array $arguments): array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (!str_starts_with($argument, '--')) { throw new RuntimeException('Haya simulation options must use --name=value syntax.'); }
            [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '');
            if ($name === '' || isset($options[$name])) { throw new RuntimeException('Haya simulation options must be unique and named.'); }
            $options[$name] = $value;
        }

        return $options;
    }

    private function required(array $options, string $name): string
    {
        $value = trim((string) ($options[$name] ?? ''));
        if ($value === '') { throw new RuntimeException(sprintf('Missing --%s=<value>.', $name)); }
        return $value;
    }
}
