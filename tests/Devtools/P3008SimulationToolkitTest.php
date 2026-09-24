<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Devtools;

use Goal\Legacy\Core\Simulation\GameSimulationAdapter;
use Goal\Legacy\Core\Simulation\SimulationCapability;
use Goal\Legacy\Core\Simulation\SimulationCheckpoint;
use Goal\Legacy\Core\Simulation\SimulationDiagnosticResult;
use Goal\Legacy\Core\Simulation\SimulationMutation;
use Goal\Legacy\Core\Simulation\SimulationMutationResult;
use Goal\Legacy\Core\Simulation\SimulationScenario;
use Goal\Legacy\Core\Simulation\SimulationPermission;
use Goal\Legacy\Core\Simulation\SimulationResult;
use Goal\Legacy\Core\Simulation\SimulationToolkit;
use Goal\Legacy\Devtools\Diagnostics\HayaSimulationCheck;
use PHPUnit\Framework\TestCase;

final class P3008SimulationToolkitTest extends TestCase
{
    public function testGenericToolkitAcceptsNonFootballAdapter(): void
    {
        $adapter = new class implements GameSimulationAdapter {
            public function gameIdentifier(): string { return 'nation-legacy-fixture'; }
            public function capabilities(): array { return [new SimulationCapability('time.advance', 'Advance month', 'Advance a national period.', 'TIME', false, 'NATION', SimulationPermission::SYSTEM_TEST)]; }
            public function inspect(string $saveId): array { return ['entity' => 'nation', 'save' => $saveId]; }
            public function run(SimulationScenario $scenario): SimulationResult { return new SimulationResult('PASS', [new SimulationCheckpoint('month-1', 'NATION', ['economy' => 10])]); }
            public function diagnostics(string $saveId, ?array $ids = null): array { return [new SimulationDiagnosticResult('nation.health', 'PASS', 'INFO', 'Fixture healthy')]; }
            public function mutate(SimulationMutation $mutation): SimulationMutationResult { return new SimulationMutationResult('PASS'); }
        };
        $toolkit = new SimulationToolkit($adapter);

        self::assertSame('nation-legacy-fixture', $toolkit->adapter()->gameIdentifier());
        self::assertSame('nation', $toolkit->inspect('fixture')['entity']);
        self::assertSame('PASS', $toolkit->run(new SimulationScenario('nation-legacy-fixture', 7, 1))->status());
        self::assertSame('nation.health', $toolkit->diagnostics('fixture')[0]->id());
        self::assertSame('nation-legacy-fixture', $toolkit->capabilityDescriptors() === [] ? '' : $toolkit->adapter()->gameIdentifier());
    }

    public function testDoctorBridgeDiscoversGameNeutralAdapterCapabilities(): void
    {
        $adapter = new class implements GameSimulationAdapter {
            public function gameIdentifier(): string { return 'nation-fixture'; }
            public function capabilities(): array { return [new SimulationCapability('state.inspect', 'Inspect', 'Inspect state.', 'STATE', true, 'NATION', SimulationPermission::PLAYER)]; }
            public function inspect(string $saveId): array { return []; }
            public function run(SimulationScenario $scenario): SimulationResult { return new SimulationResult('PASS'); }
            public function diagnostics(string $saveId, ?array $ids = null): array { return []; }
            public function mutate(SimulationMutation $mutation): SimulationMutationResult { return new SimulationMutationResult('PASS'); }
        };

        $result = (new HayaSimulationCheck($adapter))->run();
        self::assertSame('PASS', $result->status);
        self::assertSame('haya.simulation.nation-fixture', $result->id);
        self::assertSame('CHEAP', $result->metadata['cost']);
    }

    public function testDeveloperPermissionMayUsePremiumSandboxCapability(): void
    {
        $adapter = new class implements GameSimulationAdapter {
            public function gameIdentifier(): string { return 'sandbox-fixture'; }
            public function capabilities(): array { return [new SimulationCapability('sandbox.mutate', 'Sandbox mutation', 'Mutate isolated state.', 'MUTATION', false, 'SAVE', SimulationPermission::PREMIUM_SANDBOX)]; }
            public function inspect(string $saveId): array { return []; }
            public function run(SimulationScenario $scenario): SimulationResult { return new SimulationResult('PASS'); }
            public function diagnostics(string $saveId, ?array $ids = null): array { return []; }
            public function mutate(SimulationMutation $mutation): SimulationMutationResult { return new SimulationMutationResult('PASS'); }
        };

        $result = (new SimulationToolkit($adapter))->mutate(new SimulationMutation('sandbox.mutate', 'save', 'save', [], 'developer', SimulationPermission::DEVELOPER));

        self::assertSame('PASS', $result->status());
    }
}
