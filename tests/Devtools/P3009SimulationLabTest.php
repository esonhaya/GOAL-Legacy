<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Devtools;

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Simulation\SimulationPermission;
use Goal\Legacy\Core\Simulation\SimulationStateDiff;
use Goal\Legacy\Core\Simulation\SimulationMutation;
use Goal\Legacy\Devtools\Simulation\GoalScenarioBuilder;
use Goal\Legacy\Devtools\Simulation\GoalScenarioCatalog;
use Goal\Legacy\Devtools\Simulation\GoalSimulationAdapter;
use Goal\Legacy\Modules\Player\PlayerDisciplineService;
use PHPUnit\Framework\TestCase;
use Goal\Legacy\Tests\Support\SimulationAssertions;

final class P3009SimulationLabTest extends TestCase
{
    use SimulationAssertions;

    public function testCatalogIsDiscoverableAndStateDiffIsGameNeutral(): void
    {
        $catalog = new GoalScenarioCatalog();
        self::assertCount(17, $catalog->all());
        self::assertSame('HIGH_OVR_STRONG_COMPETITION', $catalog->get('high_ovr_strong_competition')->id());
        self::assertSame([
            ['path' => 'player.ovr', 'status' => 'changed', 'before' => 81, 'after' => 82],
            ['path' => 'player.role', 'status' => 'added', 'before' => null, 'after' => 'rotation'],
        ], SimulationStateDiff::compare(['player' => ['ovr' => 81]], ['player' => ['ovr' => 82, 'role' => 'rotation']]));
    }

    public function testScenarioBuilderAndOneMatchRunnerUseCanonicalMatchPath(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        $fixture = (new GoalScenarioBuilder($services))->build('HEALTHY_LOW_MINUTES', 3009);
        try {
            $result = (new GoalSimulationAdapter($services, $root, $fixture->store()))->runMatches($fixture->saveId(), 2);
            self::assertSame('PASS', $result->status());
            self::assertSame(2, $result->metrics()['matches_completed']);
            self::assertCount(4, $result->checkpoints());
            $state = $result->checkpoints()[1]->state();
            $this->assertMatchStatus($state, 'completed');
            $this->assertMatchPlayer($state, $fixture->playerId()->value());
        } finally {
            $fixture->close();
        }
    }

    public function testInjuryAndSuspensionScenarioControlsRemainSaveScoped(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        $fixture = (new GoalScenarioBuilder($services))->build('HEALTHY_LOW_MINUTES', 3010);
        try {
            $adapter = new GoalSimulationAdapter($services, $root, $fixture->store());
            $adapter->mutate(new SimulationMutation('goal.player.apply_injury', $fixture->saveId(), $fixture->playerId()->value(), ['severity' => 'minor'], 'system-test', SimulationPermission::SYSTEM_TEST));
            $injury = $adapter->inspect($fixture->saveId())['career']['availability'] ?? null;
            self::assertSame('unavailable', $injury);
            $adapter->mutate(new SimulationMutation('goal.player.apply_suspension', $fixture->saveId(), $fixture->playerId()->value(), ['matches' => 1], 'system-test', SimulationPermission::SYSTEM_TEST));
            $discipline = (new PlayerDisciplineService())->context($fixture->database(), $fixture->playerId());
            self::assertTrue($discipline['active']);
        } finally {
            $fixture->close();
        }
    }
}
