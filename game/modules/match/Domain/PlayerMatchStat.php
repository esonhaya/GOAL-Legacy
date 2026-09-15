<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Domain;

use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use InvalidArgumentException;

final readonly class PlayerMatchStat
{
    public function __construct(private MatchId $matchId, private PlayerId $playerId, private ClubId $clubId, private bool $appeared, private bool $started, private int $minutes, private int $goals, private int $assists = 0)
    {
        if (!$appeared && ($started || $minutes !== 0 || $goals !== 0 || $assists !== 0)) { throw new InvalidArgumentException('A non-appearing Player cannot have Match statistics.'); }
        if ($appeared && $minutes < 1) { throw new InvalidArgumentException('An appearing Player must have positive Match minutes.'); }
        if ($started && !$appeared) { throw new InvalidArgumentException('A starting Player must appear in the Match.'); }
        if ($minutes < 0 || $minutes > 90 || $goals < 0 || $assists < 0) { throw new InvalidArgumentException('Player Match statistics are outside the Phase-1 bounds.'); }
        if ($goals > $minutes) { throw new InvalidArgumentException('Player goals cannot exceed minutes played.'); }
    }
    public function matchId(): MatchId { return $this->matchId; }
    public function playerId(): PlayerId { return $this->playerId; }
    public function clubId(): ClubId { return $this->clubId; }
    public function appeared(): bool { return $this->appeared; }
    public function started(): bool { return $this->started; }
    public function minutes(): int { return $this->minutes; }
    public function goals(): int { return $this->goals; }
    public function assists(): int { return $this->assists; }
    /** @return array<string, mixed> */
    public function toArray(): array { return ['match_id' => $this->matchId->value(), 'player_id' => $this->playerId->value(), 'club_id' => $this->clubId->value(), 'appeared' => $this->appeared ? 1 : 0, 'started' => $this->started ? 1 : 0, 'minutes' => $this->minutes, 'goals' => $this->goals, 'assists' => $this->assists]; }
}
