<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Events;

use LogicException;

final class DuplicateSubscriptionException extends LogicException
{
    public function __construct(string $eventName, string $listenerId)
    {
        parent::__construct(sprintf('Listener "%s" is already subscribed to event "%s".', $listenerId, $eventName));
    }
}
