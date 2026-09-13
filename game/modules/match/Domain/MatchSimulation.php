<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Domain;

/** @param list<PlayerMatchStat> $playerStats @param list<MatchHighlight> $highlights @param list<PlayerSelection> $selections */
final readonly class MatchSimulation
{
    public function __construct(private MatchResult $result, private array $playerStats, private array $highlights, private array $selections = []) {}
    public function result(): MatchResult { return $this->result; }
    /** @return list<PlayerMatchStat> */
    public function playerStats(): array { return $this->playerStats; }
    /** @return list<MatchHighlight> */
    public function highlights(): array { return $this->highlights; }
    /** @return list<PlayerSelection> */
    public function selections(): array { return $this->selections; }
}
