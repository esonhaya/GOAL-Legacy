<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match;

use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;

/**
 * The single canonical owner of GOAL: Legacy's descriptive Match rating.
 * Ratings are derived from finalized factual Match stats and are never saved.
 */
final class PlayerMatchRatingService
{
    public const MINIMUM = 0.0;
    public const MAXIMUM = 10.0;

    private const DEFENSIVE_EVIDENCE_CAP = 1.2;
    private const PASSING_MEANINGFUL_ATTEMPTS = 30;

    public function rate(PlayerMatchStat $stat, PlayerPosition $position): ?float
    {
        if (!$stat->appeared() || $stat->minutes() < 1) {
            return null;
        }

        $minutes = min(90, max(0, $stat->minutes()));
        $goals = max(0, $stat->goals());
        $assists = max(0, $stat->assists());
        $shotsOnTarget = max(0, $stat->shotsOnTarget() - $goals);
        $saves = max(0, $stat->saves());
        $cleanSheets = min(1, max(0, $stat->cleanSheets()));

        // Participation supplies a conservative 4.5--6.0 foundation; decisive
        // evidence is added separately so brief substitutes can still excel.
        $rating = 4.5 + (1.5 * ($minutes / 90));
        [$goalWeight, $assistWeight, $sotWeight, $cleanSheetWeight, $saveWeight] = $this->weights($position);
        $rating += min(3.5, $goals * $goalWeight);
        $rating += min(2.0, $assists * $assistWeight);
        $rating += min(0.6, $shotsOnTarget * $sotWeight);
        $rating += $cleanSheets * $cleanSheetWeight;
        $rating += min(1.4, $saves * $saveWeight);
        $rating += $this->defensiveBonus($stat, $position);
        $rating += $this->passingBonus($stat, $position);
        $rating -= $this->disciplinePenalty($stat);

        return round(min(self::MAXIMUM, max(self::MINIMUM, $rating)), 1);
    }

    /**
     * Explain the same finalized evidence used by rate(). The explanation is
     * deliberately descriptive: it never exposes coefficients or invents an
     * action that is absent from the persisted stat line.
     *
     * @return array{rating:float|null,label:string,positive:list<string>,negative:list<string>}
     */
    public function explain(PlayerMatchStat $stat, PlayerPosition $position): array
    {
        $rating = $this->rate($stat, $position);
        if ($rating === null) {
            return ['rating' => null, 'label' => 'No rating', 'positive' => [], 'negative' => []];
        }

        $positive = [];
        $negative = [];
        if ($stat->goals() > 0) { $positive[] = $this->countText($stat->goals(), 'goal'); }
        if ($stat->assists() > 0) { $positive[] = $this->countText($stat->assists(), 'assist'); }
        if ($stat->saves() > 0) { $positive[] = $this->countText($stat->saves(), 'save'); }
        if ($stat->cleanSheets() > 0 && $this->cleanSheetPosition($position)) { $positive[] = 'Clean sheet'; }

        $defensive = array_filter([
            $stat->tackles() > 0 ? $this->countText($stat->tackles(), 'tackle') : null,
            $stat->interceptions() > 0 ? $this->countText($stat->interceptions(), 'interception') : null,
            $stat->blocks() > 0 ? $this->countText($stat->blocks(), 'block') : null,
        ]);
        if ($defensive !== [] && $this->defensivePosition($position)) {
            $positive[] = 'Defensive work: ' . implode(', ', $defensive);
        }
        if ($stat->passesAttempted() >= self::PASSING_MEANINGFUL_ATTEMPTS && $stat->passesCompleted() / max(1, $stat->passesAttempted()) >= 0.70) {
            $positive[] = sprintf('Strong passing: %d/%d completed', $stat->passesCompleted(), $stat->passesAttempted());
        }
        if ($stat->shotsOnTarget() > $stat->goals() && $this->attackingPosition($position)) {
            $positive[] = $this->countText($stat->shotsOnTarget() - $stat->goals(), 'shot on target');
        }

        if ($stat->yellowCards() > 0) { $negative[] = $this->countText($stat->yellowCards(), 'yellow card'); }
        if ($stat->redCards() > 0) { $negative[] = 'Sent off'; }
        if ($stat->foulsCommitted() > 0 && $stat->yellowCards() === 0 && $stat->redCards() === 0) { $negative[] = $this->countText($stat->foulsCommitted(), 'foul') . ' committed'; }
        if ($stat->minutes() < 30 && $positive === []) { $negative[] = 'Limited involvement'; }
        if ($positive === [] && $negative === [] && $rating < 6.0) { $negative[] = 'Few decisive contributions'; }

        return ['rating' => $rating, 'label' => $this->performanceLabel($rating), 'positive' => array_values($positive), 'negative' => array_values($negative)];
    }

    public function performanceLabel(?float $rating): string
    {
        if ($rating === null) { return 'No rating'; }

        return match (true) {
            $rating >= 8.5 => 'Outstanding',
            $rating >= 7.5 => 'Excellent',
            $rating >= 6.5 => 'Good',
            $rating >= 5.8 => 'Solid',
            $rating >= 5.0 => 'Quiet',
            default => 'Poor',
        };
    }

    /** @return array{float, float, float, float, float} */
    private function weights(PlayerPosition $position): array
    {
        return match ($position) {
            PlayerPosition::Goalkeeper => [1.0, 0.5, 0.0, 0.9, 0.28],
            PlayerPosition::CentreBack, PlayerPosition::LeftBack, PlayerPosition::RightBack => [1.6, 0.8, 0.10, 0.8, 0.0],
            PlayerPosition::DefensiveMidfielder, PlayerPosition::CentralMidfielder, PlayerPosition::AttackingMidfielder => [1.35, 0.95, 0.10, 0.0, 0.0],
            PlayerPosition::LeftWinger, PlayerPosition::RightWinger, PlayerPosition::Striker => [1.25, 0.75, 0.12, 0.0, 0.0],
        };
    }

    /**
     * DOMAIN-030 actions are finalized factual evidence. The same action
     * evidence has context-sensitive value: most for defenders, moderate for
     * midfielders, incidental for attackers, and none for goalkeepers (whose
     * save evidence is deliberately separate).
     */
    private function defensiveBonus(PlayerMatchStat $stat, PlayerPosition $position): float
    {
        $evidence = min(self::DEFENSIVE_EVIDENCE_CAP, ($stat->tackles() * 0.12) + ($stat->interceptions() * 0.14) + ($stat->blocks() * 0.16));

        return match ($position) {
            PlayerPosition::Goalkeeper => 0.0,
            PlayerPosition::CentreBack, PlayerPosition::LeftBack, PlayerPosition::RightBack => $evidence,
            PlayerPosition::DefensiveMidfielder, PlayerPosition::CentralMidfielder, PlayerPosition::AttackingMidfielder => min(0.8, $evidence),
            PlayerPosition::LeftWinger, PlayerPosition::RightWinger, PlayerPosition::Striker => min(0.3, $evidence),
        };
    }

    /**
     * Passing is one bounded evidence component. Volume only establishes how
     * much confidence to place in completion quality; routine volume alone
     * earns no bonus, and tiny perfect samples remain conservative.
     */
    private function passingBonus(PlayerMatchStat $stat, PlayerPosition $position): float
    {
        $attempted = max(0, $stat->passesAttempted());
        $completed = min($attempted, max(0, $stat->passesCompleted()));
        if ($attempted === 0) {
            return 0.0;
        }

        $completion = $completed / $attempted;
        $confidence = min(1.0, $attempted / self::PASSING_MEANINGFUL_ATTEMPTS);
        $quality = min(1.0, max(0.0, ($completion - 0.60) / 0.30));
        $cap = match ($position) {
            PlayerPosition::Goalkeeper => 0.15,
            PlayerPosition::CentreBack, PlayerPosition::LeftBack, PlayerPosition::RightBack => 0.50,
            PlayerPosition::DefensiveMidfielder, PlayerPosition::CentralMidfielder, PlayerPosition::AttackingMidfielder => 0.70,
            PlayerPosition::LeftWinger, PlayerPosition::RightWinger, PlayerPosition::Striker => 0.35,
        };

        return $cap * $confidence * $quality;
    }

    private function disciplinePenalty(PlayerMatchStat $stat): float
    {
        // Cards are the meaningful discipline outcome; ordinary fouls remain
        // deliberately minor so normal defensive work is not double-punished.
        return min(1.10, min(0.10, max(0, $stat->foulsCommitted()) * 0.03) + min(0.40, max(0, $stat->yellowCards()) * 0.20) + min(0.90, max(0, $stat->redCards()) * 0.90));
    }

    private function countText(int $count, string $noun): string
    {
        return $count . ' ' . $noun . ($count === 1 ? '' : 's');
    }

    private function cleanSheetPosition(PlayerPosition $position): bool
    {
        return in_array($position, [PlayerPosition::Goalkeeper, PlayerPosition::CentreBack, PlayerPosition::LeftBack, PlayerPosition::RightBack], true);
    }

    private function defensivePosition(PlayerPosition $position): bool
    {
        return in_array($position, [PlayerPosition::CentreBack, PlayerPosition::LeftBack, PlayerPosition::RightBack, PlayerPosition::DefensiveMidfielder, PlayerPosition::CentralMidfielder, PlayerPosition::AttackingMidfielder], true);
    }

    private function attackingPosition(PlayerPosition $position): bool
    {
        return in_array($position, [PlayerPosition::LeftWinger, PlayerPosition::RightWinger, PlayerPosition::Striker, PlayerPosition::AttackingMidfielder], true);
    }
}
