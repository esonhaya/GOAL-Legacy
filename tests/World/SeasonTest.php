<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\World;

use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SeasonLifecycleService;
use Goal\Legacy\Modules\World\Domain\SeasonStatus;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SeasonTest extends TestCase
{
    public function testSeasonTransitionsAtDeterministicBoundaries(): void
    {
        $season = $this->season();
        $lifecycle = new SeasonLifecycleService();

        $before = $lifecycle->evaluate($season, SimulationDate::fromIsoString('2026-07-31'));
        self::assertSame(SeasonStatus::Upcoming, $before->season()->status());
        self::assertFalse($before->started());

        $active = $lifecycle->evaluate($season, $season->startDate());
        self::assertSame(SeasonStatus::Active, $active->season()->status());
        self::assertTrue($active->started());

        $completed = $lifecycle->evaluate($active->season(), $season->endDate());
        self::assertSame(SeasonStatus::Completed, $completed->season()->status());
        self::assertTrue($completed->completed());
    }

    public function testSeasonCannotRegressOrUseAnInvertedDateRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Season(
            new SeasonId('invalid-season'),
            'Invalid',
            SimulationDate::fromIsoString('2027-01-01'),
            SimulationDate::fromIsoString('2026-12-31'),
        );
    }

    public function testCompletedSeasonCannotBeActivatedAgain(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->season()->withStatus(SeasonStatus::Completed)->activate();
    }

    private function season(): Season
    {
        return new Season(
            new SeasonId('season-2026-27'),
            '2026/27',
            SimulationDate::fromIsoString('2026-08-01'),
            SimulationDate::fromIsoString('2027-05-31'),
        );
    }
}
