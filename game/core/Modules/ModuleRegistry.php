<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Modules;

use Goal\Legacy\Core\Configuration\ConfigurationInterface;
use Goal\Legacy\Core\Events\EventDispatcherInterface;
use Goal\Legacy\Core\Logging\LoggerInterface;
use InvalidArgumentException;
use Throwable;

final class ModuleRegistry
{
    /** @var array<string, ModuleInterface> */
    private array $modules = [];

    /** @var array<string, bool> */
    private array $enabled = [];

    /** @var array<string, ModuleState> */
    private array $states = [];

    /** @var list<string> */
    private array $lifecycleOrder = [];

    private readonly ModuleContext $context;

    public function __construct(
        private readonly ConfigurationInterface $configuration,
        EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
        $this->context = new ModuleContext($configuration, $eventDispatcher, $logger);
    }

    public function register(ModuleInterface $module): void
    {
        $descriptor = $module->descriptor();
        $id = $descriptor->id();
        if (isset($this->modules[$id])) {
            throw new DuplicateModuleException($id);
        }

        $configured = $this->configuration->get('modules.' . $id . '.enabled', $descriptor->enabledByDefault());
        if (!is_bool($configured)) {
            throw new InvalidArgumentException(sprintf('Module enablement for "%s" must be boolean.', $id));
        }
        if ($descriptor->featureFlag() !== null) {
            $configured = $this->configuration->boolean('features.' . $descriptor->featureFlag(), $configured);
        }

        $this->modules[$id] = $module;
        $this->enabled[$id] = $configured;
        $this->states[$id] = ModuleState::Registered;
    }

    public function setEnabled(string $moduleId, bool $enabled): void
    {
        $this->requireModule($moduleId);
        if ($this->states[$moduleId] !== ModuleState::Registered && $this->states[$moduleId] !== ModuleState::Stopped) {
            throw new \LogicException('Module enablement can only change before boot or after shutdown.');
        }
        $this->enabled[$moduleId] = $enabled;
    }

    public function isEnabled(string $moduleId): bool
    {
        $this->requireModule($moduleId);

        return $this->enabled[$moduleId];
    }

    public function boot(): void
    {
        $this->lifecycleOrder = $this->resolveOrder();
        foreach ($this->lifecycleOrder as $moduleId) {
            if (!$this->enabled[$moduleId] || $this->states[$moduleId] !== ModuleState::Registered) {
                continue;
            }
            if (!$this->dependenciesReady($moduleId)) {
                continue;
            }
            $this->runLifecycle($moduleId, 'boot', fn (ModuleInterface $module) => $module->boot($this->context));
        }
    }

    public function start(): void
    {
        $this->boot();
        foreach ($this->lifecycleOrder as $moduleId) {
            if (!$this->enabled[$moduleId] || $this->states[$moduleId] !== ModuleState::Booted) {
                continue;
            }
            if (!$this->dependenciesReady($moduleId)) {
                continue;
            }
            $this->runLifecycle($moduleId, 'start', fn (ModuleInterface $module) => $module->start());
        }
    }

    public function stop(): void
    {
        foreach (array_reverse($this->lifecycleOrder) as $moduleId) {
            if ($this->states[$moduleId] !== ModuleState::Started) {
                continue;
            }
            try {
                $this->modules[$moduleId]->stop();
                $this->states[$moduleId] = ModuleState::Stopped;
            } catch (Throwable $exception) {
                $this->states[$moduleId] = ModuleState::Failed;
                $this->logger->error('core.modules', 'Module failed during stop.', [
                    'module' => $moduleId,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
                if ($this->modules[$moduleId]->descriptor()->critical()) {
                    throw new ModuleLifecycleException($moduleId, 'stop', $exception);
                }
            }
        }
    }

    public function shutdown(): void
    {
        $this->stop();
    }

    /** @return list<ModuleStatus> */
    public function statuses(): array
    {
        return array_map(
            fn (string $id): ModuleStatus => new ModuleStatus($this->modules[$id]->descriptor(), $this->states[$id], $this->enabled[$id]),
            array_keys($this->modules),
        );
    }

    public function get(string $moduleId): ModuleInterface
    {
        $this->requireModule($moduleId);

        return $this->modules[$moduleId];
    }

    /** @return list<string> */
    private function resolveOrder(): array
    {
        $order = [];
        $visiting = [];
        $visited = [];
        foreach (array_keys($this->modules) as $moduleId) {
            if ($this->enabled[$moduleId]) {
                $this->visit($moduleId, $order, $visiting, $visited);
            }
        }

        return $order;
    }

    /** @param list<string> $order @param array<string, bool> $visiting @param array<string, bool> $visited */
    private function visit(string $moduleId, array &$order, array &$visiting, array &$visited): void
    {
        if (isset($visited[$moduleId])) {
            return;
        }
        if (isset($visiting[$moduleId])) {
            throw new ModuleDependencyException(sprintf('Circular module dependency detected at "%s".', $moduleId));
        }

        $visiting[$moduleId] = true;
        foreach ($this->modules[$moduleId]->descriptor()->dependencies() as $dependency) {
            if (!isset($this->modules[$dependency])) {
                throw new ModuleDependencyException(sprintf('Module "%s" depends on unregistered module "%s".', $moduleId, $dependency));
            }
            if (!$this->enabled[$dependency]) {
                throw new ModuleDependencyException(sprintf('Module "%s" depends on disabled module "%s".', $moduleId, $dependency));
            }
            $this->visit($dependency, $order, $visiting, $visited);
        }
        unset($visiting[$moduleId]);
        $visited[$moduleId] = true;
        $order[] = $moduleId;
    }

    private function dependenciesReady(string $moduleId): bool
    {
        foreach ($this->modules[$moduleId]->descriptor()->dependencies() as $dependency) {
            if ($this->states[$dependency] !== ModuleState::Booted && $this->states[$dependency] !== ModuleState::Started) {
                $this->logger->warning('core.modules', 'Module skipped because a dependency is not ready.', [
                    'module' => $moduleId,
                    'dependency' => $dependency,
                ]);
                return false;
            }
        }

        return true;
    }

    /** @param callable(ModuleInterface): null $action */
    private function runLifecycle(string $moduleId, string $phase, callable $action): void
    {
        try {
            $action($this->modules[$moduleId]);
            $this->states[$moduleId] = $phase === 'boot' ? ModuleState::Booted : ModuleState::Started;
        } catch (Throwable $exception) {
            $this->states[$moduleId] = ModuleState::Failed;
            $this->logger->error('core.modules', 'Module lifecycle action failed.', [
                'module' => $moduleId,
                'phase' => $phase,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            if ($this->modules[$moduleId]->descriptor()->critical()) {
                throw new ModuleLifecycleException($moduleId, $phase, $exception);
            }
        }
    }

    private function requireModule(string $moduleId): void
    {
        if (!isset($this->modules[$moduleId])) {
            throw new InvalidArgumentException(sprintf('Module "%s" is not registered.', $moduleId));
        }
    }
}
