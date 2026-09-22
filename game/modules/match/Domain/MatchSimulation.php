<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match\Domain;

use Goal\Legacy\Modules\Player\Domain\OnPitchRole;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;

/** @param list<PlayerMatchStat> $playerStats @param list<MatchHighlight> $highlights @param list<PlayerSelection> $selections @param list<MatchSubstitution> $substitutions @param array<string, PlayerPosition> $controlledPositions @param array<string, OnPitchRole> $controlledRoles */
final readonly class MatchSimulation
{
    public function __construct(private MatchResult $result, private array $playerStats, private array $highlights, private array $selections = [], private array $substitutions = [], private array $controlledPositions = [], private array $controlledRoles = []) {}
    public function result(): MatchResult { return $this->result; }
    /** @return list<PlayerMatchStat> */
    public function playerStats(): array { return $this->playerStats; }
    /** @return list<MatchHighlight> */
    public function highlights(): array { return $this->highlights; }
    /** @return list<PlayerSelection> */
    public function selections(): array { return $this->selections; }
    /** @return list<MatchSubstitution> */
    public function substitutions(): array { return $this->substitutions; }
    /** @return array<string, PlayerPosition> */
    public function controlledPositions(): array { return $this->controlledPositions; }
    /** @return array<string, OnPitchRole> */
    public function controlledRoles(): array { return $this->controlledRoles; }
}
