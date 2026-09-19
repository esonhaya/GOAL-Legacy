<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Match;

use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\PlayerMatchRatingService;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use PHPUnit\Framework\TestCase;

final class PlayerMatchRatingServiceTest extends TestCase
{
    private PlayerMatchRatingService $ratings;

    protected function setUp(): void
    {
        $this->ratings = new PlayerMatchRatingService();
    }

    public function testPositionAwarePolicyHandlesParticipationAndContributions(): void
    {
        self::assertNull($this->rate(PlayerPosition::Striker, false, 0));
        self::assertSame(6.0, $this->rate(PlayerPosition::CentralMidfielder, true, 90));
        self::assertSame(4.6, $this->rate(PlayerPosition::Striker, true, 6));
        self::assertGreaterThan($this->rate(PlayerPosition::Striker, true, 6), $this->rate(PlayerPosition::Striker, true, 6, goals: 1, shots: 1, shotsOnTarget: 1));
        self::assertGreaterThan($this->rate(PlayerPosition::Striker, true, 90), $this->rate(PlayerPosition::Striker, true, 90, goals: 1, shots: 1, shotsOnTarget: 1));
        self::assertGreaterThan($this->rate(PlayerPosition::CentralMidfielder, true, 90), $this->rate(PlayerPosition::CentralMidfielder, true, 90, assists: 1));
        self::assertGreaterThan($this->rate(PlayerPosition::Striker, true, 90, goals: 1, shots: 1, shotsOnTarget: 1), $this->rate(PlayerPosition::Striker, true, 90, goals: 3, shots: 3, shotsOnTarget: 3));
        self::assertLessThan(10.1, $this->rate(PlayerPosition::Striker, true, 90, goals: 90, shots: 90, shotsOnTarget: 90));
    }

    public function testShootingAndDefensiveGoalkeeperWeightsArePositionSpecificAndBounded(): void
    {
        self::assertLessThan(6.7, $this->rate(PlayerPosition::Striker, true, 90, shots: 10, shotsOnTarget: 1));
        self::assertGreaterThan($this->rate(PlayerPosition::CentreBack, true, 90), $this->rate(PlayerPosition::CentreBack, true, 90, cleanSheets: 1));
        self::assertGreaterThanOrEqual(6.8, $this->rate(PlayerPosition::CentreBack, true, 90, cleanSheets: 1));
        self::assertGreaterThan($this->rate(PlayerPosition::Goalkeeper, true, 90), $this->rate(PlayerPosition::Goalkeeper, true, 90, saves: 4));
        self::assertGreaterThanOrEqual(7.5, $this->rate(PlayerPosition::Goalkeeper, true, 90, saves: 4, cleanSheets: 1));
        self::assertSame($this->rate(PlayerPosition::CentralMidfielder, true, 90), $this->rate(PlayerPosition::CentralMidfielder, true, 90, saves: 12));
        self::assertSame($this->rate(PlayerPosition::Striker, true, 90), $this->rate(PlayerPosition::Striker, true, 90, cleanSheets: 1));
        self::assertSame($this->rate(PlayerPosition::Goalkeeper, true, 90, saves: 12), $this->rate(PlayerPosition::Goalkeeper, true, 90, saves: 12));
        self::assertLessThanOrEqual(10.0, $this->rate(PlayerPosition::Goalkeeper, true, 90, saves: 999999, cleanSheets: 1));
    }

    public function testDefensiveEvidenceIsPositionAwareBoundedAndDeterministic(): void
    {
        $base = $this->rate(PlayerPosition::CentreBack, true, 90);
        $tackles = $this->rate(PlayerPosition::CentreBack, true, 90, tackles: 3);
        $interceptions = $this->rate(PlayerPosition::CentreBack, true, 90, interceptions: 3);
        $blocks = $this->rate(PlayerPosition::CentreBack, true, 90, blocks: 3);
        $balanced = $this->rate(PlayerPosition::CentreBack, true, 90, tackles: 3, interceptions: 2, blocks: 1);
        $cleanSheetBalanced = $this->rate(PlayerPosition::CentreBack, true, 90, cleanSheets: 1, tackles: 3, interceptions: 2, blocks: 1);

        self::assertGreaterThan($base, $tackles);
        self::assertGreaterThan($base, $interceptions);
        self::assertGreaterThan($base, $blocks);
        self::assertGreaterThanOrEqual(6.8, $balanced, 'A defender can rate well through useful defensive work alone.');
        self::assertGreaterThan($this->rate(PlayerPosition::CentreBack, true, 90, cleanSheets: 1), $cleanSheetBalanced);
        self::assertLessThanOrEqual(10.0, $cleanSheetBalanced);

        $evidence = ['tackles' => 9, 'interceptions' => 6, 'blocks' => 4];
        $defender = $this->rate(PlayerPosition::CentreBack, true, 90, ...$evidence);
        $midfielder = $this->rate(PlayerPosition::CentralMidfielder, true, 90, ...$evidence);
        $attacker = $this->rate(PlayerPosition::Striker, true, 90, ...$evidence);
        $goalkeeper = $this->rate(PlayerPosition::Goalkeeper, true, 90, ...$evidence);
        $defenderBase = $this->rate(PlayerPosition::CentreBack, true, 90);
        $midfielderBase = $this->rate(PlayerPosition::CentralMidfielder, true, 90);
        $attackerBase = $this->rate(PlayerPosition::Striker, true, 90);
        $goalkeeperBase = $this->rate(PlayerPosition::Goalkeeper, true, 90);

        self::assertGreaterThan($midfielder - $midfielderBase, $defender - $defenderBase);
        self::assertGreaterThan($attacker - $attackerBase, $midfielder - $midfielderBase);
        self::assertGreaterThan($goalkeeper - $goalkeeperBase, $attacker - $attackerBase);
        self::assertSame($goalkeeperBase, $goalkeeper);
        self::assertSame($defender, $this->rate(PlayerPosition::CentreBack, true, 90, ...$evidence));
        self::assertSame($defender, $this->rate(PlayerPosition::CentreBack, true, 90, tackles: 999999, interceptions: 999999, blocks: 999999));
        self::assertGreaterThan($this->rate(PlayerPosition::CentreBack, true, 6), $this->rate(PlayerPosition::CentreBack, true, 6, tackles: 1));
        self::assertNull($this->rate(PlayerPosition::CentreBack, false, 0));
    }

    public function testPassingEvidenceIsConservativePositionAwareAndBounded(): void
    {
        $midBase = $this->rate(PlayerPosition::CentralMidfielder, true, 90);
        $tinyPerfect = $this->rate(PlayerPosition::CentralMidfielder, true, 90, passesAttempted: 3, passesCompleted: 3);
        $tinyPoor = $this->rate(PlayerPosition::CentralMidfielder, true, 90, passesAttempted: 1, passesCompleted: 0);
        $shortPerfect = $this->rate(PlayerPosition::CentralMidfielder, true, 6, passesAttempted: 3, passesCompleted: 3);
        $midPoor = $this->rate(PlayerPosition::CentralMidfielder, true, 90, passesAttempted: 30, passesCompleted: 15);
        $midAverage = $this->rate(PlayerPosition::CentralMidfielder, true, 90, passesAttempted: 30, passesCompleted: 22);
        $midStrong = $this->rate(PlayerPosition::CentralMidfielder, true, 90, passesAttempted: 30, passesCompleted: 27);
        $highVolumeMediocre = $this->rate(PlayerPosition::CentralMidfielder, true, 90, passesAttempted: 100, passesCompleted: 70);

        self::assertSame($midBase, $this->rate(PlayerPosition::CentralMidfielder, true, 90, passesAttempted: 0));
        self::assertLessThanOrEqual(0.1, $tinyPerfect - $midBase);
        self::assertSame($midBase, $tinyPoor);
        self::assertLessThanOrEqual(4.7, $shortPerfect);
        self::assertGreaterThan($midPoor, $midAverage);
        self::assertGreaterThan($midAverage, $midStrong);
        self::assertLessThan($midStrong, $highVolumeMediocre);

        $evidence = ['passesAttempted' => 30, 'passesCompleted' => 27];
        $defender = $this->rate(PlayerPosition::CentreBack, true, 90, ...$evidence);
        $midfielder = $this->rate(PlayerPosition::CentralMidfielder, true, 90, ...$evidence);
        $attacker = $this->rate(PlayerPosition::Striker, true, 90, ...$evidence);
        $goalkeeper = $this->rate(PlayerPosition::Goalkeeper, true, 90, ...$evidence);
        self::assertGreaterThan($defender - $this->rate(PlayerPosition::CentreBack, true, 90), $midfielder - $midBase);
        self::assertGreaterThan($attacker - $this->rate(PlayerPosition::Striker, true, 90), $defender - $this->rate(PlayerPosition::CentreBack, true, 90));
        self::assertGreaterThan($goalkeeper - $this->rate(PlayerPosition::Goalkeeper, true, 90), $attacker - $this->rate(PlayerPosition::Striker, true, 90));
        self::assertGreaterThan($this->rate(PlayerPosition::CentreBack, true, 90, tackles: 3), $this->rate(PlayerPosition::CentreBack, true, 90, tackles: 3, passesAttempted: 30, passesCompleted: 27));
        self::assertGreaterThan($this->rate(PlayerPosition::Goalkeeper, true, 90), $this->rate(PlayerPosition::Goalkeeper, true, 90, saves: 4));
        self::assertSame($midStrong, $this->rate(PlayerPosition::CentralMidfielder, true, 90, ...$evidence));
        self::assertSame($midStrong, $this->rate(PlayerPosition::CentralMidfielder, true, 90, passesAttempted: 999999, passesCompleted: 999999));
        self::assertGreaterThanOrEqual(0.0, $midStrong);
        self::assertLessThanOrEqual(10.0, $midStrong);
    }

    public function testDisciplinePenaltyIsBoundedAndRetainsOtherEvidence(): void
    {
        $base = $this->rate(PlayerPosition::CentreBack, true, 90, tackles: 3);
        $foul = $this->rate(PlayerPosition::CentreBack, true, 90, tackles: 3, foulsCommitted: 1);
        $yellow = $this->rate(PlayerPosition::CentreBack, true, 90, tackles: 3, foulsCommitted: 1, yellowCards: 1);
        $red = $this->rate(PlayerPosition::Striker, true, 90, goals: 2, shots: 2, shotsOnTarget: 2, foulsCommitted: 1, redCards: 1);
        $scoringBase = $this->rate(PlayerPosition::Striker, true, 90, goals: 2, shots: 2, shotsOnTarget: 2);

        self::assertGreaterThan($foul, $base);
        self::assertGreaterThan($yellow, $foul);
        self::assertGreaterThan($red, $scoringBase);
        self::assertGreaterThan(0.0, $red);
        self::assertLessThanOrEqual(10.0, $this->rate(PlayerPosition::Goalkeeper, true, 90, foulsCommitted: 99, yellowCards: 99, redCards: 1));
    }

    public function testRatingExplanationIsFactualAndPositionAware(): void
    {
        $striker = $this->ratings->explain($this->stat(PlayerPosition::Striker, goals: 1, assists: 1, shots: 2, shotsOnTarget: 2, yellowCards: 1), PlayerPosition::Striker);
        self::assertSame('goal', strtolower(explode(' ', $striker['positive'][0])[1] ?? ''));
        self::assertContains('1 assist', $striker['positive']);
        self::assertContains('1 yellow card', $striker['negative']);
        self::assertSame('Excellent', $striker['label']);

        $defender = $this->ratings->explain($this->stat(PlayerPosition::CentreBack, cleanSheets: 1, tackles: 3, interceptions: 2, blocks: 1), PlayerPosition::CentreBack);
        self::assertContains('Clean sheet', $defender['positive']);
        self::assertStringContainsString('Defensive work:', implode(' ', $defender['positive']));

        $goalkeeper = $this->ratings->explain($this->stat(PlayerPosition::Goalkeeper, saves: 4, cleanSheets: 1), PlayerPosition::Goalkeeper);
        self::assertContains('4 saves', $goalkeeper['positive']);
        self::assertContains('Clean sheet', $goalkeeper['positive']);
        self::assertStringNotContainsString('goal', strtolower(implode(' ', $goalkeeper['positive'])));
    }

    private function rate(PlayerPosition $position, bool $appeared, int $minutes, int $goals = 0, int $assists = 0, int $shots = 0, int $shotsOnTarget = 0, int $saves = 0, int $cleanSheets = 0, int $tackles = 0, int $interceptions = 0, int $blocks = 0, int $passesAttempted = 0, int $passesCompleted = 0, int $foulsCommitted = 0, int $yellowCards = 0, int $redCards = 0): ?float
    {
        return $this->ratings->rate(new PlayerMatchStat(new MatchId('rating-match'), new PlayerId('rating-player'), new ClubId('arsenal'), $appeared, $appeared, $minutes, $goals, $assists, $shots, $shotsOnTarget, $saves, $cleanSheets, $tackles, $interceptions, $blocks, $passesAttempted, $passesCompleted, $foulsCommitted, $yellowCards, $redCards), $position);
    }

    private function stat(PlayerPosition $position, int $goals = 0, int $assists = 0, int $shots = 0, int $shotsOnTarget = 0, int $saves = 0, int $cleanSheets = 0, int $tackles = 0, int $interceptions = 0, int $blocks = 0, int $yellowCards = 0): PlayerMatchStat
    {
        return new PlayerMatchStat(new MatchId('explanation-' . strtolower($position->value)), new PlayerId('explanation-player'), new ClubId('arsenal'), true, true, 90, $goals, $assists, $shots, $shotsOnTarget, $saves, $cleanSheets, $tackles, $interceptions, $blocks, 0, 0, $yellowCards, $yellowCards > 0 ? 1 : 0);
    }
}
