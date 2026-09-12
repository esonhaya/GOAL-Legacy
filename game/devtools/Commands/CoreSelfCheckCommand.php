<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Events\GenericEvent;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;

final class CoreSelfCheckCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services)
    {
    }

    public function name(): string { return 'core:self-check'; }

    public function description(): string { return 'Run an isolated Core event and lifecycle self-check.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $received = false;
        $dispatcher = $this->services->eventDispatcher();
        $subscription = $dispatcher->subscribe(
            'core.self_check',
            static function (GenericEvent $event) use (&$received): void { $received = $event->payload()['ok'] ?? false; },
            priority: 10,
            listenerId: 'devtools.core-self-check',
        );
        $result = $dispatcher->dispatch(new GenericEvent('core.self_check', ['ok' => true]));
        $dispatcher->unsubscribe($subscription);
        $this->services->moduleRegistry()->start();
        $this->services->moduleRegistry()->shutdown();

        if (!$received || $result->hasFailures() || $result->deliveredCount() !== 1) {
            $output->error('Core self-check failed.');
            return 1;
        }
        $output->write('Core self-check passed.');
        return 0;
    }
}
