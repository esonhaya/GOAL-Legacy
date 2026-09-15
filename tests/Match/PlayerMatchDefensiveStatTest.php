<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Match;

use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PlayerMatchDefensiveStatTest extends TestCase
{
    public function testDefensiveStatisticsDefaultToZeroAndSerialize(): void
    {
        $stat = $this->stat();
        self::assertSame(0, $stat->tackles());
        self::assertSame(0, $stat->interceptions());
        self::assertSame(0, $stat->blocks());
        self::assertSame(0, $stat->toArray()['tackles']);
        self::assertSame(0, $stat->toArray()['interceptions']);
        self::assertSame(0, $stat->toArray()['blocks']);
        $withActions = new PlayerMatchStat(new MatchId('defensive-match-actions'), new PlayerId('defensive-player-actions'), new ClubId('arsenal'), true, true, 90, 0, 0, 0, 0, 0, 0, 3, 2, 1);
        self::assertSame([3, 2, 1], [$withActions->tackles(), $withActions->interceptions(), $withActions->blocks()]);
    }

    public function testDefensiveStatisticsRequireAnAppearanceAndAreNonNegative(): void
    {
        self::expectException(InvalidArgumentException::class);
        new PlayerMatchStat(new MatchId('defensive-match'), new PlayerId('defensive-player'), new ClubId('arsenal'), false, false, 0, 0, 0, 0, 0, 0, 0, 1);
    }

    private function stat(): PlayerMatchStat
    {
        return new PlayerMatchStat(new MatchId('defensive-match'), new PlayerId('defensive-player'), new ClubId('arsenal'), true, true, 90, 0);
    }
}
