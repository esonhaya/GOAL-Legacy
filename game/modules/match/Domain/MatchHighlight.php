<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Domain;

use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use InvalidArgumentException;

final readonly class MatchHighlight
{
    /** @param array<string, scalar|null> $data */
    public function __construct(private MatchId $matchId, private int $sequence, private int $minute, private string $type, private ?ClubId $clubId, private ?PlayerId $playerId, private array $data = [])
    {
        if ($sequence < 1 || $minute < 0 || $minute > 120 || trim($type) === '') { throw new InvalidArgumentException('Match Highlight identity is invalid.'); }
    }
    public function matchId(): MatchId { return $this->matchId; }
    public function sequence(): int { return $this->sequence; }
    public function minute(): int { return $this->minute; }
    public function type(): string { return $this->type; }
    public function clubId(): ?ClubId { return $this->clubId; }
    public function playerId(): ?PlayerId { return $this->playerId; }
    /** @return array<string, scalar|null> */
    public function data(): array { return $this->data; }
    /** @return array<string, mixed> */
    public function toArray(): array { return ['match_id' => $this->matchId->value(), 'sequence_number' => $this->sequence, 'minute' => $this->minute, 'type' => $this->type, 'club_id' => $this->clubId?->value(), 'player_id' => $this->playerId?->value(), 'data' => $this->data]; }
}
