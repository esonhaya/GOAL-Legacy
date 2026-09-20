<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;

/** The single bounded compatibility policy for controlled positional evolution. */
final class PositionDevelopmentRules
{
    /** @return list<PlayerPosition> */
    public static function compatibleWith(PlayerPosition $primary): array
    {
        return match ($primary) {
            PlayerPosition::Goalkeeper => [],
            PlayerPosition::CentreBack => [PlayerPosition::LeftBack, PlayerPosition::RightBack],
            PlayerPosition::LeftBack => [PlayerPosition::CentreBack, PlayerPosition::LeftWinger],
            PlayerPosition::RightBack => [PlayerPosition::CentreBack, PlayerPosition::RightWinger],
            PlayerPosition::DefensiveMidfielder => [PlayerPosition::CentralMidfielder],
            PlayerPosition::CentralMidfielder => [PlayerPosition::DefensiveMidfielder, PlayerPosition::AttackingMidfielder],
            PlayerPosition::AttackingMidfielder => [PlayerPosition::CentralMidfielder, PlayerPosition::LeftWinger, PlayerPosition::RightWinger],
            PlayerPosition::LeftWinger => [PlayerPosition::AttackingMidfielder, PlayerPosition::Striker, PlayerPosition::RightWinger],
            PlayerPosition::RightWinger => [PlayerPosition::AttackingMidfielder, PlayerPosition::Striker, PlayerPosition::LeftWinger],
            PlayerPosition::Striker => [PlayerPosition::LeftWinger, PlayerPosition::RightWinger, PlayerPosition::AttackingMidfielder],
        };
    }

    public static function hasAttributeFit(PlayerPosition $target, PlayerAttributeSet $attributes): bool
    {
        return self::fitScore($target, $attributes) >= 35;
    }

    public static function fitScore(PlayerPosition $target, PlayerAttributeSet $attributes): int
    {
        return match ($target) {
            PlayerPosition::Goalkeeper => 0,
            PlayerPosition::CentreBack => (int) round(($attributes->defending() * 0.65) + ($attributes->physicality() * 0.35)),
            PlayerPosition::LeftBack, PlayerPosition::RightBack => (int) round(($attributes->defending() * 0.45) + ($attributes->pace() * 0.30) + ($attributes->physicality() * 0.25)),
            PlayerPosition::DefensiveMidfielder => (int) round(($attributes->defending() * 0.35) + ($attributes->passing() * 0.40) + ($attributes->physicality() * 0.25)),
            PlayerPosition::CentralMidfielder => (int) round(($attributes->passing() * 0.45) + ($attributes->dribbling() * 0.25) + ($attributes->physicality() * 0.15) + ($attributes->defending() * 0.15)),
            PlayerPosition::AttackingMidfielder => (int) round(($attributes->passing() * 0.40) + ($attributes->dribbling() * 0.40) + ($attributes->shooting() * 0.20)),
            PlayerPosition::LeftWinger, PlayerPosition::RightWinger => (int) round(($attributes->pace() * 0.40) + ($attributes->dribbling() * 0.40) + ($attributes->passing() * 0.20)),
            PlayerPosition::Striker => (int) round(($attributes->shooting() * 0.50) + ($attributes->physicality() * 0.25) + ($attributes->dribbling() * 0.25)),
        };
    }
}
