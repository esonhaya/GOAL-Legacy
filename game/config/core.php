<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'GOAL: Legacy',
        'environment' => 'development',
        'minimum_php_version' => '8.2',
    ],
    'logging' => [
        'path' => 'game/logs/core.log',
        'level' => 'debug',
        'component' => 'core',
    ],
    'modules' => [],
    'features' => [],
    'content' => [
        'path' => 'game/data/packages',
        'selected' => ['core-nations', 'core-competitions'],
    ],
];
