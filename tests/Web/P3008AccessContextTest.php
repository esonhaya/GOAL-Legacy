<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Web;

use Goal\Legacy\Core\Simulation\SimulationPermission;
use Goal\Legacy\Web\WebAccessContext;
use PHPUnit\Framework\TestCase;

final class P3008AccessContextTest extends TestCase
{
    public function testDefaultSessionIsPlayerOnly(): void
    {
        $context = WebAccessContext::fromSession([]);
        self::assertSame(SimulationPermission::PLAYER, $context->permission());
        self::assertFalse($context->canSandbox());
        self::assertFalse($context->isDeveloper());
    }

    public function testPremiumAndDeveloperBoundariesAreDistinct(): void
    {
        $premium = WebAccessContext::fromSession(['account_id' => 'premium-1', 'haya_premium_sandbox' => true]);
        self::assertSame(SimulationPermission::PREMIUM_SANDBOX, $premium->permission());
        self::assertTrue($premium->canSandbox());
        self::assertFalse($premium->isDeveloper());

        $developer = WebAccessContext::fromSession(['account_id' => 'developer-1', 'haya_role' => 'DEVELOPER']);
        self::assertSame(SimulationPermission::DEVELOPER, $developer->permission());
        self::assertTrue($developer->canSandbox());
        self::assertTrue($developer->isDeveloper());
    }
}
