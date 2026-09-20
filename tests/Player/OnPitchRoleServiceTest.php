<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Player;

use Goal\Legacy\Core\Persistence\SqliteDatabase;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\PlayerMatchRatingService;
use Goal\Legacy\Modules\Player\Domain\DevelopmentProfile;
use Goal\Legacy\Modules\Player\Domain\OnPitchRole;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerFoot;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Domain\WeakFootTier;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Nation\Domain\NationId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\Player\OnPitchRoleService;
use PHPUnit\Framework\TestCase;

final class OnPitchRoleServiceTest extends TestCase
{
    public function testCatalogHasPositionScopedReadableRolesAndSafeDefaults(): void
    {
        $service = new OnPitchRoleService();
        $catalog = $service->catalog();

        self::assertCount(16, $catalog);
        self::assertCount(16, array_unique(array_column($catalog, 'label')));
        self::assertSame(21, array_sum(array_map(static fn (array $definition): int => count($definition['positions']), $catalog)));

        foreach (PlayerPosition::cases() as $position) {
            $default = $service->defaultRole($position);
            self::assertTrue($service->isCompatible($default, $position));
            self::assertNotEmpty($service->rolesForPosition($position));
        }
        self::assertFalse($service->isCompatible(OnPitchRole::InsideForward, PlayerPosition::Striker));
        self::assertFalse($service->isCompatible(OnPitchRole::Poacher, PlayerPosition::CentralMidfielder));
    }

    public function testSuitabilityUsesAttributesAndFootContextWithoutAUniversalScore(): void
    {
        $service = new OnPitchRoleService();
        $creator = $this->player('creator', PlayerPosition::AttackingMidfielder, new PlayerAttributeSet(70, 65, 90, 75, 45, 60));
        $developing = $this->player('developing', PlayerPosition::AttackingMidfielder, new PlayerAttributeSet(45, 45, 45, 45, 45, 45), PlayerFoot::Left, WeakFootTier::Limited);

        $creatorRoles = $service->rolesForPosition(PlayerPosition::AttackingMidfielder, $creator);
        $developingRoles = $service->rolesForPosition(PlayerPosition::AttackingMidfielder, $developing);
        $creatorRow = array_values(array_filter($creatorRoles, static fn (array $row): bool => $row['key'] === OnPitchRole::Creator->value))[0];
        $developingRow = array_values(array_filter($developingRoles, static fn (array $row): bool => $row['key'] === OnPitchRole::Creator->value))[0];

        self::assertSame('natural', $creatorRow['suitability']);
        self::assertSame('developing', $developingRow['suitability']);
        self::assertSame('preferred-side option', $service->rolesForPosition(PlayerPosition::LeftWinger, $this->player('left', PlayerPosition::LeftWinger, new PlayerAttributeSet(70, 60, 65, 65, 45, 60), PlayerFoot::Left))[0]['foot_context']);
        self::assertArrayNotHasKey('score', $creatorRow);
    }

    public function testRoleChangesActionTendencyButNotSuccessOrRating(): void
    {
        $service = new OnPitchRoleService();
        self::assertGreaterThan($service->actionTendency(OnPitchRole::CentreForward, 'shoot'), $service->actionTendency(OnPitchRole::Poacher, 'shoot'));
        self::assertGreaterThan($service->actionTendency(OnPitchRole::CentreForward, 'assist'), $service->actionTendency(OnPitchRole::Creator, 'assist'));

        $stat = new PlayerMatchStat(new MatchId('role-boundary-match'), new PlayerId('role-boundary-player'), new ClubId('arsenal'), true, true, 90, 1, 1, 3, 2, 0, 0, 1, 1, 0, 40, 32);
        $rating = new PlayerMatchRatingService();
        self::assertSame($rating->rate($stat, PlayerPosition::Striker), $rating->rate($stat, PlayerPosition::Striker));
        self::assertSame(0, $service->actionTendency(OnPitchRole::Poacher, 'rating'));
        self::assertSame(0, $service->actionTendency(OnPitchRole::Poacher, 'success'));
    }

    public function testNpcDerivationIsDeterministicAndDoesNotCreateRoleStorage(): void
    {
        $database = new SqliteDatabase(':memory:');
        $service = new OnPitchRoleService();
        $player = $this->player('npc-role', PlayerPosition::Striker, new PlayerAttributeSet(65, 82, 55, 60, 40, 60));

        $first = $service->publicContext($database, $player);
        $second = $service->publicContext($database, $player);

        self::assertSame($first, $second);
        self::assertSame(OnPitchRole::Poacher->value, $first['role']);
        self::assertSame([], $database->connection()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE '%role%'")->fetchAll());
    }

    private function player(string $id, PlayerPosition $position, PlayerAttributeSet $attributes, PlayerFoot $foot = PlayerFoot::Right, WeakFootTier $weakFoot = WeakFootTier::Usable): Player
    {
        return new Player(
            new PlayerId($id),
            'Role',
            'Test',
            'Role Test',
            SimulationDate::fromIsoString('2000-01-01'),
            new NationId('england'),
            [],
            new NationId('england'),
            [new NationId('england')],
            180,
            75,
            $position,
            $attributes,
            99,
            DevelopmentProfile::Regular,
            24,
            preferredFoot: $foot,
            weakFoot: $weakFoot,
        );
    }
}
