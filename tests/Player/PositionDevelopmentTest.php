<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Player;

use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Domain\PositionDevelopmentState;
use Goal\Legacy\Modules\Player\PositionDevelopmentRules;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PHPUnit\Framework\TestCase;

final class PositionDevelopmentTest extends TestCase
{
    public function testCompatibilityIsAdjacentAndGoalkeeperCannotRetrain(): void
    {
        self::assertSame([PlayerPosition::CentralMidfielder], PositionDevelopmentRules::compatibleWith(PlayerPosition::DefensiveMidfielder));
        self::assertContains(PlayerPosition::AttackingMidfielder, PositionDevelopmentRules::compatibleWith(PlayerPosition::CentralMidfielder));
        self::assertSame([], PositionDevelopmentRules::compatibleWith(PlayerPosition::Goalkeeper));
        self::assertFalse(PositionDevelopmentRules::hasAttributeFit(PlayerPosition::Striker, new PlayerAttributeSet(30, 20, 30, 20, 40, 40)));
    }

    public function testProgressIsBoundedCompletesOnceAndPrimaryChangePreservesHistory(): void
    {
        $player = new PlayerId('position-player');
        $date = SimulationDate::fromIsoString('2024-08-01');
        $state = PositionDevelopmentState::empty($player)->withFocus(PlayerPosition::AttackingMidfielder, $date)->withProgress(PlayerPosition::AttackingMidfielder, 120, $date);

        self::assertSame(100, $state->progressFor(PlayerPosition::AttackingMidfielder));
        self::assertNull($state->developingPosition());
        self::assertSame([PlayerPosition::AttackingMidfielder], $state->secondaryPositions());

        $changed = $state->afterPrimaryChange(PlayerPosition::CentralMidfielder, PlayerPosition::AttackingMidfielder, $date);
        self::assertNull($changed->developingPosition());
        self::assertContains(PlayerPosition::CentralMidfielder, $changed->secondaryPositions());
        self::assertNotContains(PlayerPosition::AttackingMidfielder, $changed->secondaryPositions());
    }
}
