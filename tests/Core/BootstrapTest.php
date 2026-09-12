<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Core;

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Events\GenericEvent;
use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    public function testBootstrapCreatesAllCoreServicesWithSelectedNationContent(): void
    {
        $services = (new Bootstrap())->create(
            dirname(__DIR__, 2),
            ['APP_ENV' => 'test', 'APP_LOG_LEVEL' => 'debug'],
        );

        self::assertSame('test', $services->configuration()->string('app.environment'));
        self::assertNotNull($services->logger());
        self::assertNotNull($services->eventDispatcher());
        self::assertNotNull($services->moduleRegistry());
        self::assertSame(0, $services->clock()->now()->ticks());
        self::assertFalse($services->scheduler()->hasPendingTasks());
        self::assertNotNull($services->saveStore());
        self::assertCount(1, $services->contentPackages()->packages());
        self::assertTrue($services->contentPackages()->isSelected('core-nations'));
        self::assertSame('nation', $services->nationModule()->descriptor()->id());
        self::assertFileExists(dirname(__DIR__, 2) . '/game/logs/core.log');

        $received = false;
        $subscription = $services->eventDispatcher()->subscribe(
            'bootstrap.check',
            static function (GenericEvent $event) use (&$received): void { $received = $event->payload()['ready']; },
            listenerId: 'bootstrap-test',
        );
        $services->eventDispatcher()->dispatch(new GenericEvent('bootstrap.check', ['ready' => true]));
        $services->eventDispatcher()->unsubscribe($subscription);

        self::assertTrue($received);
    }
}
