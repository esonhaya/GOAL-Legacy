<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use ValueError;

/** Small, position-scoped descriptions of how a Player is used on the pitch. */
enum OnPitchRole: string
{
    case Goalkeeper = 'goalkeeper';
    case CentralDefender = 'central_defender';
    case BallPlayingDefender = 'ball_playing_defender';
    case FullBack = 'full_back';
    case AttackingFullBack = 'attacking_full_back';
    case HoldingMidfielder = 'holding_midfielder';
    case DeepLyingPlaymaker = 'deep_lying_playmaker';
    case CentralMidfielder = 'central_midfielder';
    case BoxToBoxMidfielder = 'box_to_box_midfielder';
    case AttackingMidfielder = 'attacking_midfielder';
    case Creator = 'creator';
    case Winger = 'winger';
    case InsideForward = 'inside_forward';
    case CentreForward = 'centre_forward';
    case Poacher = 'poacher';
    case LinkForward = 'link_forward';

    public static function fromInput(string $value): self
    {
        try {
            return self::from(strtolower(trim($value)));
        } catch (ValueError $exception) {
            throw new PlayerException(sprintf('Unsupported on-pitch role "%s".', $value), 0, $exception);
        }
    }
}
