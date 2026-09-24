<?php

declare(strict_types=1);

namespace Goal\Legacy\Core\Simulation;

final class SimulationPermission
{
    public const PLAYER = 'PLAYER';
    public const PREMIUM_SANDBOX = 'PREMIUM_SANDBOX';
    public const DEVELOPER = 'DEVELOPER';
    public const SYSTEM_TEST = 'SYSTEM_TEST';

    public static function canUse(string $granted, string $required): bool
    {
        if ($granted === self::SYSTEM_TEST || $granted === $required) {
            return true;
        }

        return $granted === self::DEVELOPER && $required === self::PREMIUM_SANDBOX;
    }

    private function __construct() {}
}
