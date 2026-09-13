<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Player\Domain\PerformanceEvaluation;

final class PlayerPerformanceEvaluator
{
    public function evaluate(PlayerMatchStat $stat, GameMatch $match): PerformanceEvaluation
    {
        if (!$stat->appeared() || $stat->minutes() < 1 || $match->result() === null) {
            return new PerformanceEvaluation(0, 'not_played');
        }
        $score = 35 + min(25, (int) round($stat->minutes() / 90 * 25)) + ($stat->started() ? 8 : 0) + min(20, $stat->goals() * 10);
        $result = $this->clubResult($stat->clubId()->value(), $match->homeClubId()->value(), $match->result());
        $score += $result === 'win' ? 10 : ($result === 'draw' ? 5 : 0);
        $score = min(100, max(0, $score));
        $band = $score >= 85 ? 'excellent' : ($score >= 75 ? 'good' : ($score >= 60 ? 'acceptable' : ($score >= 45 ? 'below_expectation' : 'poor')));

        return new PerformanceEvaluation($score, $band);
    }

    private function clubResult(string $clubId, string $homeClubId, MatchResult $result): string
    {
        if ($result->homeGoals() === $result->awayGoals()) { return 'draw'; }
        $homeWon = $result->homeGoals() > $result->awayGoals();
        $won = ($clubId === $homeClubId) === $homeWon;

        return $won ? 'win' : 'loss';
    }
}
