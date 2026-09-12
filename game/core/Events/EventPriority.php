<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Events;

enum EventPriority: int
{
    case Low = 100;
    case Normal = 200;
    case High = 300;
    case Critical = 400;
}
