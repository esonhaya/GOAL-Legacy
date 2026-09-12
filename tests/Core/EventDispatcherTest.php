<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Core;

use Goal\Legacy\Core\Events\DuplicateSubscriptionException;
use Goal\Legacy\Core\Events\EventDispatcher;
use Goal\Legacy\Core\Events\GenericEvent;
use Goal\Legacy\Tests\Fixtures\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EventDispatcherTest extends TestCase
{
    public function testListenersRunByPriorityThenRegistrationOrder(): void
    {
        $dispatcher = new EventDispatcher(new RecordingLogger());
        $calls = [];
        $dispatcher->subscribe('sample', static function () use (&$calls): void { $calls[] = 'normal-first'; }, 0, 'one');
        $dispatcher->subscribe('sample', static function () use (&$calls): void { $calls[] = 'high'; }, 10, 'two');
        $dispatcher->subscribe('sample', static function () use (&$calls): void { $calls[] = 'normal-second'; }, 0, 'three');

        $result = $dispatcher->dispatch(new GenericEvent('sample'));

        self::assertSame(['high', 'normal-first', 'normal-second'], $calls);
        self::assertSame(3, $result->deliveredCount());
        self::assertFalse($result->hasFailures());
    }

    public function testUnsubscribeRemovesListener(): void
    {
        $dispatcher = new EventDispatcher(new RecordingLogger());
        $calls = 0;
        $subscription = $dispatcher->subscribe('sample', static function () use (&$calls): void { $calls++; }, 0, 'listener');

        self::assertTrue($dispatcher->unsubscribe($subscription));
        self::assertFalse($dispatcher->unsubscribe($subscription));
        $dispatcher->dispatch(new GenericEvent('sample'));

        self::assertSame(0, $calls);
        self::assertSame(0, $dispatcher->listenerCount());
    }

    public function testDuplicateSubscriptionIdIsRejected(): void
    {
        $dispatcher = new EventDispatcher(new RecordingLogger());
        $dispatcher->subscribe('sample', static function (): void {}, 0, 'same');

        $this->expectException(DuplicateSubscriptionException::class);
        $dispatcher->subscribe('sample', static function (): void {}, 0, 'same');
    }

    public function testListenerFailureIsReturnedAndDoesNotStopOtherListeners(): void
    {
        $logger = new RecordingLogger();
        $dispatcher = new EventDispatcher($logger);
        $calls = [];
        $dispatcher->subscribe('sample', static function () use (&$calls): void {
            throw new RuntimeException('expected failure');
        }, 10, 'failing');
        $dispatcher->subscribe('sample', static function () use (&$calls): void { $calls[] = 'survivor'; }, 0, 'survivor');

        $result = $dispatcher->dispatch(new GenericEvent('sample'));

        self::assertSame(['survivor'], $calls);
        self::assertCount(1, $result->failures());
        self::assertSame('failing', $result->failures()[0]->listenerId());
        self::assertNotEmpty($logger->records);
    }

    public function testQueuedEventsRunByEventPriorityThenQueueOrder(): void
    {
        $dispatcher = new EventDispatcher(new RecordingLogger());
        $calls = [];
        $dispatcher->subscribe('sample', static function ($event) use (&$calls): void { $calls[] = $event->payload()['id']; }, 0, 'recorder');
        $dispatcher->queue(new GenericEvent('sample', ['id' => 'normal'], 200));
        $dispatcher->queue(new GenericEvent('sample', ['id' => 'critical'], 400));
        $dispatcher->queue(new GenericEvent('sample', ['id' => 'high'], 300));

        $results = $dispatcher->dispatchQueued();

        self::assertCount(3, $results);
        self::assertSame(['critical', 'high', 'normal'], $calls);
    }
}
