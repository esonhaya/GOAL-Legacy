<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Player;

use Goal\Legacy\Modules\Player\Domain\AvailabilityAssessment;
use Goal\Legacy\Modules\Player\Domain\AvailabilityStatus;
use Goal\Legacy\Modules\Player\Domain\CareerPriority;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\TrainingIntensity;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PHPUnit\Framework\TestCase;

final class TrainingIntensityTest extends TestCase
{
    public function testExistingCareerPrioritiesMapToOneBoundedTrainingPolicy(): void
    {
        self::assertSame(TrainingIntensity::Intense, TrainingIntensity::forPriority(CareerPriority::Development));
        self::assertSame(TrainingIntensity::Light, TrainingIntensity::forPriority(CareerPriority::Recovery));
        self::assertSame(TrainingIntensity::Normal, TrainingIntensity::forPriority(CareerPriority::Professional));
        self::assertSame(TrainingIntensity::Normal, TrainingIntensity::forPriority(CareerPriority::Balanced));
        self::assertSame(TrainingIntensity::Light, TrainingIntensity::forPriority(CareerPriority::Lifestyle));
        self::assertLessThan(TrainingIntensity::Intense->loadPerWeek(), TrainingIntensity::Light->loadPerWeek());
        self::assertLessThan(TrainingIntensity::Intense->developmentPercent(), TrainingIntensity::Light->developmentPercent());
    }

    public function testReadinessLabelsAreDerivedFromAvailabilityEvidence(): void
    {
        $date = SimulationDate::fromIsoString('2024-08-01');
        $player = new PlayerId('readiness-label-player');
        self::assertSame('fresh', (new AvailabilityAssessment($player, AvailabilityStatus::Available, 0, $date))->readinessLabel());
        self::assertSame('ready', (new AvailabilityAssessment($player, AvailabilityStatus::Available, 30, $date))->readinessLabel());
        self::assertSame('managed', (new AvailabilityAssessment($player, AvailabilityStatus::Limited, 55, $date))->readinessLabel());
        self::assertSame('tired', (new AvailabilityAssessment($player, AvailabilityStatus::Limited, 75, $date))->readinessLabel());
        self::assertSame('fatigued', (new AvailabilityAssessment($player, AvailabilityStatus::Unavailable, 90, $date))->readinessLabel());
    }
}
