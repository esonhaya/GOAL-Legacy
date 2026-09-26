<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Devtools;

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Devtools\Presentation\CareerPresentationService;
use Goal\Legacy\Devtools\Simulation\GoalScenarioBuilder;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use PHPUnit\Framework\TestCase;

final class P3014MatchdayPresentationTest extends TestCase
{
    public function testPreMatchProjectionUsesCanonicalContextAndIsReadOnly(): void
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $fixture = (new GoalScenarioBuilder($services))->build('HEALTHY_LOW_MINUTES', 3014);
        try {
            $presentation = new CareerPresentationService($services);
            $snapshot = $presentation->snapshot($fixture->database(), $fixture->saveId(), false);
            $match = (new MatchRepository($fixture->database()))->get($fixture->matchIds()[0]);
            $before = (int) $fixture->database()->connection()->query('SELECT total_changes()')->fetchColumn();
            $view = $presentation->preMatch($fixture->database(), $match, $fixture->playerId()->value(), $fixture->clubId(), $snapshot['summary']);
            $repeat = $presentation->preMatch($fixture->database(), $match, $fixture->playerId()->value(), $fixture->clubId(), $snapshot['summary']);
            $after = (int) $fixture->database()->connection()->query('SELECT total_changes()')->fetchColumn();

            self::assertSame('pre_match', $view['state']);
            self::assertSame($match->id()->value(), $view['match_id']);
            self::assertSame('available', $view['player']['availability']['code']);
            self::assertSame('pending', $view['player']['selection']['code']);
            self::assertSame('Selection confirmed at kickoff', $view['player']['selection']['label']);
            self::assertSame($view, $repeat);
            self::assertSame($before, $after, 'pre-Match projection must remain read-only');
        } finally {
            $fixture->close();
        }
    }

    public function testInjuryProjectionDoesNotPromiseSelection(): void
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $fixture = (new GoalScenarioBuilder($services))->build('INJURY_LOW_MINUTES', 3015);
        try {
            $presentation = new CareerPresentationService($services);
            $snapshot = $presentation->snapshot($fixture->database(), $fixture->saveId(), false);
            $match = (new MatchRepository($fixture->database()))->get($fixture->matchIds()[0]);
            $view = $presentation->preMatch($fixture->database(), $match, $fixture->playerId()->value(), $fixture->clubId(), $snapshot['summary']);

            self::assertSame('injured', $view['player']['availability']['code']);
            self::assertSame('injured', $view['player']['selection']['code']);
            self::assertSame('Injured', $view['player']['selection']['label']);
            self::assertStringContainsString('recovery', strtolower((string) $view['player']['selection']['explanation']));
        } finally {
            $fixture->close();
        }
    }
}
