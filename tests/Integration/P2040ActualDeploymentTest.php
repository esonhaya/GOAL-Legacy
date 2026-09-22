<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\SqliteDatabase;
use Goal\Legacy\Modules\Match\MatchSelectionService;
use Goal\Legacy\Modules\Nation\Domain\Nation;
use Goal\Legacy\Modules\Nation\Domain\NationId;
use Goal\Legacy\Modules\Nation\Persistence\NationRepository;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\DevelopmentProfile;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Domain\PositionDevelopmentState;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PositionDevelopmentRepository;
use Goal\Legacy\Modules\Player\PositionDevelopmentService;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PHPUnit\Framework\TestCase;

final class P2040ActualDeploymentTest extends TestCase
{
    public function testDeploymentUsesCanonicalSecondaryPositionOnlyForACompatibleNeed(): void
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $database = new SqliteDatabase(':memory:');
        (new NationRepository($database))->save(new Nation(new NationId('england'), 'England', 'England', 'ENG', 'europe', 'england', 'association-england', 'test-package', '1.0.0', 1));
        $player = new Player(
            new PlayerId('p2040-deployment-player'),
            'Deployment',
            'Player',
            'Deployment Player',
            SimulationDate::fromIsoString('2005-01-01'),
            new NationId('england'),
            [],
            new NationId('england'),
            [new NationId('england')],
            180,
            75,
            PlayerPosition::CentralMidfielder,
            new PlayerAttributeSet(70, 70, 75, 75, 65, 70),
            99,
            DevelopmentProfile::Regular,
            2040,
        );
        (new PlayerRepository($database))->save($player);
        (new CareerPlayerRepository($database))->save(new CareerPlayerReference(
            new CareerId('p2040-deployment-career'),
            $player->id(),
            SimulationDate::fromIsoString('2024-08-01'),
        ));
        $date = SimulationDate::fromIsoString('2024-08-01');
        $positions = new PositionDevelopmentRepository($database, true);
        $database->transaction(static function () use ($positions, $player, $date): void {
            $positions->saveStateInTransaction(PositionDevelopmentState::empty($player->id())->withProgress(PlayerPosition::DefensiveMidfielder, 100, $date));
        });

        $selection = new MatchSelectionService($services->clubModule()->service(), null, null, new PositionDevelopmentService());

        self::assertSame(PlayerPosition::CentralMidfielder, $selection->deploymentPosition($database, $player));
        self::assertSame(PlayerPosition::DefensiveMidfielder, $selection->deploymentPosition($database, $player, PlayerPosition::DefensiveMidfielder));
        self::assertSame(PlayerPosition::CentralMidfielder, $selection->deploymentPosition($database, $player, PlayerPosition::LeftBack));
        self::assertFalse($selection->isPositionCompatible($database, $player, $this->player('p2040-fullback', PlayerPosition::LeftBack)));
    }

    private function player(string $id, PlayerPosition $position): Player
    {
        return new Player(
            new PlayerId($id),
            'Replacement',
            'Player',
            'Replacement Player',
            SimulationDate::fromIsoString('2005-01-01'),
            new NationId('england'),
            [],
            new NationId('england'),
            [new NationId('england')],
            180,
            75,
            $position,
            new PlayerAttributeSet(70, 70, 70, 70, 65, 70),
            99,
            DevelopmentProfile::Regular,
            2040,
        );
    }
}
