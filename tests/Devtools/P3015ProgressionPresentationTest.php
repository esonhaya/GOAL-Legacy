<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Devtools;

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Devtools\Presentation\CareerPresentationService;
use Goal\Legacy\Devtools\Simulation\GoalScenarioBuilder;
use Goal\Legacy\Modules\Player\Domain\TrainingRequest;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PHPUnit\Framework\TestCase;

final class P3015ProgressionPresentationTest extends TestCase
{
    public function testCanonicalTrainingChangeIsProjectedAndSurvivesReload(): void
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $fixture = (new GoalScenarioBuilder($services))->build('HEALTHY_LOW_MINUTES', 3015);
        try {
            $training = $services->playerModule()->service()->trainingService();
            $result = $training->complete($fixture->database(), new TrainingRequest(
                $fixture->playerId(),
                'p3015-training',
                'passing',
                SimulationDate::fromIsoString('2024-08-01'),
                SimulationDate::fromIsoString('2024-09-26'),
            ));
            self::assertTrue($result->applied());
            $player = (new PlayerRepository($fixture->database()))->get($fixture->playerId());
            $presentation = new CareerPresentationService($services);
            $snapshot = $presentation->snapshot($fixture->database(), $fixture->saveId(), false);
            $progression = $presentation->progressionContext($snapshot['summary']);

            self::assertSame($player->overallRating(), $progression['current']['ovr']);
            self::assertSame('passing', $progression['training']['focus']);
            self::assertNotEmpty($progression['recent_changes']);
            self::assertSame($result->beforeOverall(), $progression['recent_changes'][0]['before_ovr']);
            self::assertSame($result->afterOverall(), $progression['recent_changes'][0]['after_ovr']);
            self::assertSame($result->attributeDeltas(), array_combine(
                array_column($progression['recent_changes'][0]['attribute_changes'], 'attribute'),
                array_column($progression['recent_changes'][0]['attribute_changes'], 'delta'),
            ));

            $before = (int) $fixture->database()->connection()->query('SELECT total_changes()')->fetchColumn();
            $repeat = $presentation->progressionContext($snapshot['summary']);
            self::assertSame($progression, $repeat);
            self::assertSame($before, (int) $fixture->database()->connection()->query('SELECT total_changes()')->fetchColumn());

            $fixture->reload();
            $reloaded = $presentation->snapshot($fixture->database(), $fixture->saveId(), false);
            self::assertSame($progression, $presentation->progressionContext($reloaded['summary']));
        } finally {
            $fixture->close();
        }
    }
}
