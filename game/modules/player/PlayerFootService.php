<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerFoot;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Domain\WeakFootTier;

/** Bounded football context for the immutable Player foot identity. */
final class PlayerFootService
{
    public function positionSuitability(Player $player, PlayerPosition $position): int
    {
        $expected = match ($position) {
            PlayerPosition::LeftBack, PlayerPosition::LeftWinger => PlayerFoot::Left,
            PlayerPosition::RightBack, PlayerPosition::RightWinger => PlayerFoot::Right,
            default => null,
        };
        if ($expected === null) { return 0; }
        if ($player->preferredFoot() === $expected) { return 2; }

        return match ($player->weakFoot()) {
            WeakFootTier::Limited => -2,
            WeakFootTier::Usable => -1,
            WeakFootTier::Comfortable, WeakFootTier::Strong => 0,
        };
    }

    public function positionContext(Player $player, PlayerPosition $position): string
    {
        $suitability = $this->positionSuitability($player, $position);
        if ($suitability > 0) { return 'preferred-side option'; }
        if ($suitability < 0) { return 'opposite-side option'; }

        return in_array($position, [PlayerPosition::LeftBack, PlayerPosition::RightBack, PlayerPosition::LeftWinger, PlayerPosition::RightWinger], true)
            ? 'two-foot capable wide option'
            : 'central-foot context';
    }

    /** Deterministic per-action choice; this never uses PHP/global RNG. */
    public function actionFoot(Player $player, string $actionKey): PlayerFoot
    {
        $digest = hash('sha256', 'player-foot-action:v1|' . $player->id()->value() . '|' . $actionKey);
        $unit = hexdec(substr($digest, 0, 12)) / 281474976710655;

        return $unit < $player->weakFoot()->actionChance() ? $player->preferredFoot()->opposite() : $player->preferredFoot();
    }

    /** Attributes remain primary; this only nudges controlled action ordering. */
    public function executionModifier(Player $player, PlayerFoot $actionFoot): int
    {
        if ($actionFoot === $player->preferredFoot()) { return 0; }

        return match ($player->weakFoot()) {
            WeakFootTier::Limited => -2,
            WeakFootTier::Usable => -1,
            WeakFootTier::Comfortable, WeakFootTier::Strong => 0,
        };
    }
}
