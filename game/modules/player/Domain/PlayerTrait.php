<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

/** Small descriptive football identities derived from demonstrated evidence. */
enum PlayerTrait: string
{
    case Finisher = 'finisher';
    case GoalThreat = 'goal_threat';
    case Creator = 'creator';
    case Playmaker = 'playmaker';
    case BallWinner = 'ball_winner';
    case DefensiveAnchor = 'defensive_anchor';
    case Workhorse = 'workhorse';
    case ConsistentPerformer = 'consistent_performer';
    case Versatile = 'versatile';
    case TwoFooted = 'two_footed';

    public function label(): string
    {
        return match ($this) {
            self::Finisher => 'Finisher',
            self::GoalThreat => 'Goal Threat',
            self::Creator => 'Creator',
            self::Playmaker => 'Playmaker',
            self::BallWinner => 'Ball Winner',
            self::DefensiveAnchor => 'Defensive Anchor',
            self::Workhorse => 'Workhorse',
            self::ConsistentPerformer => 'Consistent Performer',
            self::Versatile => 'Versatile',
            self::TwoFooted => 'Two-Footed',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Finisher => 'Converts chances consistently across a meaningful scoring sample.',
            self::GoalThreat => 'Provides sustained shooting volume and a reliable goal threat.',
            self::Creator => 'Creates goals through a sustained combination of passing and assists.',
            self::Playmaker => 'Takes responsibility for high-volume, accurate progression through passing.',
            self::BallWinner => 'Regularly recovers possession through tackles and interceptions.',
            self::DefensiveAnchor => 'Combines defensive work with sustained clean-sheet contribution.',
            self::Workhorse => 'Sustains heavy minutes through physical durability and regular involvement.',
            self::ConsistentPerformer => 'Produces dependable Match ratings over a substantial sample.',
            self::Versatile => 'Has established more than one credible position for the Career.',
            self::TwoFooted => 'Can contribute meaningfully with either foot after sustained evidence.',
        };
    }

    /** Stable presentation priority used only when more than five traits qualify. */
    public function priority(): int
    {
        return match ($this) {
            self::Finisher, self::Creator, self::BallWinner, self::DefensiveAnchor => 100,
            self::GoalThreat, self::Playmaker => 90,
            self::Workhorse, self::ConsistentPerformer => 80,
            self::Versatile, self::TwoFooted => 70,
        };
    }
}
