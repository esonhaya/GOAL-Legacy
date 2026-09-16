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

    private function rate(PlayerPosition $position, bool $appeared, int $minutes, int $goals = 0, int $assists = 0, int $shots = 0, int $shotsOnTarget = 0, int $saves = 0, int $cleanSheets = 0, int $tackles = 0, int $interceptions = 0, int $blocks = 0): ?float
    {
        return $this->ratings->rate(new PlayerMatchStat(new MatchId('rating-match'), new PlayerId('rating-player'), new ClubId('arsenal'), $appeared, $appeared, $minutes, $goals, $assists, $shots, $shotsOnTarget, $saves, $cleanSheets, $tackles, $interceptions, $blocks), $position);
    }
}
