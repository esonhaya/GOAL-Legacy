<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use Goal\Legacy\Core\Persistence\SqliteDatabase;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\DevelopmentProfile;
use Goal\Legacy\Modules\Player\Domain\OnPitchRole;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Domain\PositionDevelopmentState;
use Goal\Legacy\Modules\Player\OnPitchRoleService;
use Goal\Legacy\Modules\Player\PositionDevelopmentService;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PositionDevelopmentRepository;
use Goal\Legacy\Modules\Nation\Domain\Nation;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\Nation\Domain\NationId;
use Goal\Legacy\Modules\Nation\Persistence\NationRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class P2024OnPitchRoleTest extends TestCase
{
    public function testControlledRoleSelectionUsesOnePersistedPreferenceAndLegacyDefaults(): void
    {
        $database = new SqliteDatabase(':memory:');
        $this->saveNation($database);
        $player = $this->player('p2024-controlled', PlayerPosition::CentralMidfielder);
        (new PlayerRepository($database))->save($player);
        $careers = new CareerPlayerRepository($database);
        $careers->save(new CareerPlayerReference(new CareerId('p2024-career'), $player->id(), SimulationDate::fromIsoString('2024-08-01')));
        $roles = new OnPitchRoleService();

        self::assertSame(OnPitchRole::CentralMidfielder->value, $roles->context($database, $player->id())['role']);
        $selected = $roles->setPreferredRole($database, $player->id(), OnPitchRole::BoxToBoxMidfielder);
        self::assertSame(OnPitchRole::BoxToBoxMidfielder->value, $selected['role']);
        self::assertSame(OnPitchRole::BoxToBoxMidfielder, $careers->get('p2024-career')->preferredOnPitchRole());
        self::assertSame(['career_id', 'player_id', 'start_date'], array_keys($careers->get('p2024-career')->toArray()));
        self::assertSame(1, (int) $database->connection()->query('SELECT COUNT(*) FROM career_player_references')->fetchColumn());
    }

    public function testIncompatibleSelectionIsRejectedAndNpcContextIsReadOnly(): void
    {
        $database = new SqliteDatabase(':memory:');
        $this->saveNation($database);
        $player = $this->player('p2024-striker', PlayerPosition::Striker);
        (new PlayerRepository($database))->save($player);
        $careers = new CareerPlayerRepository($database);
        $careers->save(new CareerPlayerReference(new CareerId('p2024-striker-career'), $player->id(), SimulationDate::fromIsoString('2024-08-01')));
        $roles = new OnPitchRoleService();

        try {
            $roles->setPreferredRole($database, $player->id(), OnPitchRole::InsideForward);
            self::fail('A striker cannot choose a wide-only role.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }

        $npc = $this->player('p2024-npc', PlayerPosition::Striker);
        (new PlayerRepository($database))->save($npc);
        $context = $roles->publicContext($database, $npc);
        self::assertSame(OnPitchRole::LinkForward->value, $context['role']);
        self::assertSame([], $database->connection()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE '%role%'")->fetchAll());
    }

    public function testPositionChangeReplacesAnIncompatiblePreferenceWithTheNewDefault(): void
    {
        $database = new SqliteDatabase(':memory:');
        $this->saveNation($database);
        $player = $this->player('p2024-position', PlayerPosition::CentralMidfielder);
        (new PlayerRepository($database))->save($player);
        (new CareerPlayerRepository($database))->save(new CareerPlayerReference(new CareerId('p2024-position-career'), $player->id(), SimulationDate::fromIsoString('2024-08-01')));
        $roles = new OnPitchRoleService();
        $roles->setPreferredRole($database, $player->id(), OnPitchRole::BoxToBoxMidfielder);
        $date = SimulationDate::fromIsoString('2024-08-01');
        $positionRepository = new PositionDevelopmentRepository($database, true);
        $database->transaction(static function () use ($positionRepository, $player, $date): void {
            $positionRepository->saveStateInTransaction(PositionDevelopmentState::empty($player->id())->withProgress(PlayerPosition::AttackingMidfielder, 100, $date));
        });

        (new PositionDevelopmentService())->changePrimary($database, $player->id(), PlayerPosition::AttackingMidfielder, $date);

        self::assertSame(OnPitchRole::AttackingMidfielder, (new CareerPlayerRepository($database))->byPlayer($player->id())?->preferredOnPitchRole());
        self::assertSame(OnPitchRole::AttackingMidfielder->value, $roles->context($database, $player->id())['role']);
    }

    private function player(string $id, PlayerPosition $position): Player
    {
        return new Player(
            new PlayerId($id),
            'Role',
            'Integration',
            'Role Integration',
            SimulationDate::fromIsoString('2000-01-01'),
            new NationId('england'),
            [],
            new NationId('england'),
            [new NationId('england')],
            180,
            75,
            $position,
            new PlayerAttributeSet(70, 70, 72, 70, 65, 70),
            99,
            DevelopmentProfile::Regular,
            2024,
        );
    }

    private function saveNation(SqliteDatabase $database): void
    {
        (new NationRepository($database))->save(new Nation(new NationId('england'), 'England', 'England', 'ENG', 'europe', 'england', 'association-england', 'test-package', '1.0.0', 1));
    }
}
