<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Domain;

use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use InvalidArgumentException;

final readonly class MatchSubstitution
{
    public const REASON_WORKLOAD = 'WORKLOAD';
    public const REASON_READINESS = 'READINESS';
    public const REASON_TACTICAL = 'TACTICAL';

    public function __construct(
        private MatchId $matchId,
        private ClubId $clubId,
        private int $sequence,
        private PlayerId $outgoingPlayerId,
        private PlayerId $incomingPlayerId,
        private int $minute,
        private ?string $reason = null,
    ) {
        if ($this->sequence < 1 || $this->minute < 1 || $this->minute > 89) {
            throw new InvalidArgumentException('Match substitutions require positive sequence and minutes from 1 to 89.');
        }
        if ($this->outgoingPlayerId->value() === $this->incomingPlayerId->value()) {
            throw new InvalidArgumentException('A Match substitution must replace a different Player.');
        }
        if ($this->reason !== null && !in_array($this->reason, [self::REASON_WORKLOAD, self::REASON_READINESS, self::REASON_TACTICAL], true)) {
            throw new InvalidArgumentException('Match substitution context is not supported.');
        }
    }

    public function matchId(): MatchId { return $this->matchId; }
    public function clubId(): ClubId { return $this->clubId; }
    public function sequence(): int { return $this->sequence; }
    public function outgoingPlayerId(): PlayerId { return $this->outgoingPlayerId; }
    public function incomingPlayerId(): PlayerId { return $this->incomingPlayerId; }
    public function minute(): int { return $this->minute; }
    public function reason(): ?string { return $this->reason; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'match_id' => $this->matchId->value(),
            'club_id' => $this->clubId->value(),
            'sequence_number' => $this->sequence,
            'outgoing_player_id' => $this->outgoingPlayerId->value(),
            'incoming_player_id' => $this->incomingPlayerId->value(),
            'minute' => $this->minute,
            'reason' => $this->reason,
        ];
    }
}
