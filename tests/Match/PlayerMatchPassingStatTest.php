<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Match;

use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\MatchSimulationService;
use Goal\Legacy\Modules\Nation\Domain\NationId;
use Goal\Legacy\Modules\Player\Domain\DevelopmentProfile;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PlayerMatchPassingStatTest extends TestCase
{
    public function testPassingStatisticsDefaultSerializeAndReconcile(): void
    {
        $defaults = $this->stat();
        self::assertSame(0, $defaults->passesAttempted());
        self::assertSame(0, $defaults->passesCompleted());
        self::assertSame(0, $defaults->toArray()['passes_attempted']);
        self::assertSame(0, $defaults->toArray()['passes_completed']);

        $passing = new PlayerMatchStat(new MatchId('passing-match'), new PlayerId('passing-player'), new ClubId('arsenal'), true, true, 90, 0, 0, 0, 0, 0, 0, 0, 0, 0, 42, 35);
        self::assertSame([42, 35], [$passing->passesAttempted(), $passing->passesCompleted()]);
    }

    public function testPassingStatisticsRejectInvalidCompletionRelationship(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PlayerMatchStat(new MatchId('passing-invalid-match'), new PlayerId('passing-invalid-player'), new ClubId('arsenal'), true, true, 90, 0, 0, 0, 0, 0, 0, 0, 0, 0, 5, 6);
    }

    public function testAggregatePassingPolicyUsesMinutesPositionAttributeAndStableKeys(): void
    {
        $service = (new \ReflectionClass(MatchSimulationService::class))->newInstanceWithoutConstructor();
        $evidence = new \ReflectionMethod(MatchSimulationService::class, 'passingEvidence');
        $lowMidfielder = $this->player(PlayerPosition::CentralMidfielder, 0);
        $highMidfielder = $this->player(PlayerPosition::CentralMidfielder, 99);

        $full = $evidence->invoke($service, 'passing-policy-match', $lowMidfielder, 90);
        $short = $evidence->invoke($service, 'passing-policy-match', $lowMidfielder, 10);
        $high = $evidence->invoke($service, 'passing-policy-match', $highMidfielder, 90);
        $defender = $evidence->invoke($service, 'passing-policy-match', $this->player(PlayerPosition::CentreBack, 0), 90);
        $attacker = $evidence->invoke($service, 'passing-policy-match', $this->player(PlayerPosition::Striker, 0), 90);
        $goalkeeper = $evidence->invoke($service, 'passing-policy-match', $this->player(PlayerPosition::Goalkeeper, 0), 90);

        self::assertGreaterThan($short[0], $full[0]);
        self::assertGreaterThan($defender[0], $full[0]);
        self::assertGreaterThan($attacker[0], $full[0]);
        self::assertGreaterThan(0, $goalkeeper[0]);
        self::assertGreaterThan($full[1], $high[1]);
        foreach ([$full, $short, $high, $defender, $attacker, $goalkeeper] as [$attempted, $completed]) {
            self::assertGreaterThanOrEqual(0, $attempted);
            self::assertGreaterThanOrEqual(0, $completed);
            self::assertLessThanOrEqual($attempted, $completed);
        }
        self::assertSame($full, $evidence->invoke($service, 'passing-policy-match', $lowMidfielder, 90));
        self::assertSame([0, 0], $evidence->invoke($service, 'passing-policy-match', $lowMidfielder, 0));
    }

    private function stat(): PlayerMatchStat
    {
        return new PlayerMatchStat(new MatchId('passing-default-match'), new PlayerId('passing-default-player'), new ClubId('arsenal'), true, true, 90, 0);
    }

    private function player(PlayerPosition $position, int $passing): Player
    {
        return new Player(new PlayerId('passing-policy-player'), 'Passing', 'Policy', 'Passing Policy', SimulationDate::fromIsoString('2000-01-01'), new NationId('england'), [], new NationId('england'), [new NationId('england')], 180, 75, $position, new PlayerAttributeSet(50, 50, $passing, 50, 50, 50), 99, DevelopmentProfile::Regular, 1);
    }
}
