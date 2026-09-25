<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Devtools\Presentation\CareerPresentationService;
use Goal\Legacy\Devtools\Simulation\GoalScenarioBuilder;
use PHPUnit\Framework\TestCase;

final class P3011CareerHomeTest extends TestCase
{
    public function testCareerHomeUsesReusableScenarioAndDoesNotWriteWhileReading(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        $fixture = (new GoalScenarioBuilder($services))->build('INJURY_LOW_MINUTES', 3011);
        try {
            $presentation = new CareerPresentationService($services);
            $before = (int) $fixture->database()->connection()->query('SELECT total_changes()')->fetchColumn();
            $world = $services->worldModule()->service()->load($fixture->database(), $fixture->saveId());
            $snapshot = $presentation->snapshot($fixture->database(), $fixture->saveId(), false);
            $next = $presentation->nextMatch($fixture->database(), $snapshot['summary']);
            $home = $presentation->careerHome($snapshot['summary'], $snapshot['date'], $next);
            $after = (int) $fixture->database()->connection()->query('SELECT total_changes()')->fetchColumn();

            self::assertSame($world->currentSeasonId()->value(), $snapshot['summary']['current_season_id']);
            self::assertSame('injured', $home['current_status']['code']);
            self::assertSame('RECOVERY', $home['next_up']['action']['priority']);
            self::assertNotEmpty($home['needs_attention']);
            self::assertLessThanOrEqual(5, count($home['recent_story']));
            self::assertSame($before, $after, 'Career Home projection must remain read-only.');
        } finally {
            $fixture->close();
        }
    }

    public function testCareerHomeContractAndSeasonLinksComeFromCanonicalSummary(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        $fixture = (new GoalScenarioBuilder($services))->build('CONTRACT_EXPIRING', 3012);
        try {
            $snapshot = (new CareerPresentationService($services))->snapshot($fixture->database(), $fixture->saveId(), false);
            $home = (new CareerPresentationService($services))->homeContext($snapshot['summary'], null, $snapshot['date']);

            self::assertSame('Arsenal', $home['contract']['club']);
            self::assertSame('2024-12-31', $home['contract']['end_date']);
            self::assertSame('market', $home['quick_links'][4]['page'] ?? null);
            self::assertSame('contract_uncertainty', $home['outlook']['category'] ?? null);
        } finally {
            $fixture->close();
        }
    }
}
