<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Core;

use Goal\Legacy\Core\Configuration\Configuration;
use Goal\Legacy\Core\Events\EventDispatcher;
use Goal\Legacy\Core\Modules\DuplicateModuleException;
use Goal\Legacy\Core\Modules\ModuleDependencyException;
use Goal\Legacy\Core\Modules\ModuleDescriptor;
use Goal\Legacy\Core\Modules\ModuleRegistry;
use Goal\Legacy\Core\Modules\ModuleState;
use Goal\Legacy\Tests\Fixtures\RecordingLogger;
use Goal\Legacy\Tests\Fixtures\RecordingModule;
use PHPUnit\Framework\TestCase;

final class ModuleRegistryTest extends TestCase
{
    public function testDependenciesControlDeterministicLifecycleOrder(): void
    {
        $first = new RecordingModule(new ModuleDescriptor('first', 'First', '1.0'));
        $second = new RecordingModule(new ModuleDescriptor('second', 'Second', '1.0', ['first']));
        $registry = $this->registry();
        $registry->register($second);
        $registry->register($first);

        $registry->start();
        $registry->shutdown();

        self::assertSame(['boot', 'start', 'stop'], $first->calls);
        self::assertSame(['boot', 'start', 'stop'], $second->calls);
        self::assertSame(ModuleState::Stopped, $registry->statuses()[0]->state());
    }

    public function testDuplicateModuleIdIsRejected(): void
    {
        $registry = $this->registry();
        $registry->register(new RecordingModule(new ModuleDescriptor('same', 'Same', '1.0')));

        $this->expectException(DuplicateModuleException::class);
        $registry->register(new RecordingModule(new ModuleDescriptor('same', 'Same again', '1.0')));
    }

    public function testMissingDependencyIsRejectedBeforeLifecycle(): void
    {
        $registry = $this->registry();
        $registry->register(new RecordingModule(new ModuleDescriptor('dependent', 'Dependent', '1.0', ['missing'])));

        $this->expectException(ModuleDependencyException::class);
        $registry->boot();
    }

    public function testDisabledModuleDoesNotRunAndCanBeEnabledBeforeBoot(): void
    {
        $module = new RecordingModule(new ModuleDescriptor('optional', 'Optional', '1.0', [], false));
        $registry = $this->registry();
        $registry->register($module);
        $registry->start();
        self::assertSame([], $module->calls);

        $registry->setEnabled('optional', true);
        $registry->start();
        self::assertSame(['boot', 'start'], $module->calls);
        self::assertTrue($registry->isEnabled('optional'));
    }

    public function testConfigurationCanDisableAFeatureFlaggedModule(): void
    {
        $configuration = new Configuration(['features' => ['optional' => false]]);
        $registry = new ModuleRegistry($configuration, new EventDispatcher(new RecordingLogger()), new RecordingLogger());
        $module = new RecordingModule(new ModuleDescriptor('flagged', 'Flagged', '1.0', [], true, false, 'optional'));
        $registry->register($module);
        $registry->start();

        self::assertFalse($registry->isEnabled('flagged'));
        self::assertSame([], $module->calls);
    }

    private function registry(): ModuleRegistry
    {
        $logger = new RecordingLogger();
        return new ModuleRegistry(new Configuration([]), new EventDispatcher($logger), $logger);
    }
}
