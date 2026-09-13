<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Domain;

/** @param list<PlayerMatchStat> $playerStats @param list<MatchHighlight> $highlights */
final readonly class MatchSimulation
{
    public function __construct(private MatchResult $result, private array $playerStats, private array $highlights) {}
    public function result(): MatchResult { return $this->result; }
    /** @return list<PlayerMatchStat> */
    public function playerStats(): array { return $this->playerStats; }
    /** @return list<MatchHighlight> */
    public function highlights(): array { return $this->highlights; }
}
