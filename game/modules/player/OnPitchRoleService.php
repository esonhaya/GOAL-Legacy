<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Player\Domain\OnPitchRole;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PositionDevelopmentRepository;
use RuntimeException;

/**
 * The single owner of controlled on-pitch role preference and role context.
 * Roles describe action tendencies; they never change capability or success.
 */
final class OnPitchRoleService
{
    /** @var array<string, array{label:string,description:string,positions:list<string>,requirements:array<string,int>,tendencies:array<string,int>}> */
    private const CATALOG = [
        'goalkeeper' => ['label' => 'Goalkeeper', 'description' => 'Protects the goal and starts play safely from the back.', 'positions' => ['GK'], 'requirements' => ['defending' => 45], 'tendencies' => ['pass' => 1, 'defend' => 3]],
        'central_defender' => ['label' => 'Central Defender', 'description' => 'Prioritises defensive positioning, challenges and blocks in the centre.', 'positions' => ['CB'], 'requirements' => ['defending' => 60, 'physicality' => 55], 'tendencies' => ['pass' => -1, 'defend' => 4]],
        'ball_playing_defender' => ['label' => 'Ball-Playing Defender', 'description' => 'Takes a little more responsibility for progressive circulation from defence.', 'positions' => ['CB'], 'requirements' => ['defending' => 50, 'passing' => 65], 'tendencies' => ['pass' => 4, 'defend' => 2]],
        'full_back' => ['label' => 'Full-Back', 'description' => 'Balances wide defensive work with simple support in possession.', 'positions' => ['LB', 'RB'], 'requirements' => ['defending' => 55, 'pace' => 50], 'tendencies' => ['pass' => 1, 'defend' => 2]],
        'attacking_full_back' => ['label' => 'Attacking Full-Back', 'description' => 'Offers more width and forward involvement from the full-back line.', 'positions' => ['LB', 'RB'], 'requirements' => ['pace' => 65, 'dribbling' => 55], 'tendencies' => ['pass' => 2, 'assist' => 2, 'shoot' => 1, 'defend' => -1]],
        'holding_midfielder' => ['label' => 'Holding Midfielder', 'description' => 'Protects the defence and focuses on recoveries and secure circulation.', 'positions' => ['DM'], 'requirements' => ['defending' => 60, 'physicality' => 55], 'tendencies' => ['pass' => 1, 'defend' => 4, 'shoot' => -1]],
        'deep_lying_playmaker' => ['label' => 'Deep-Lying Playmaker', 'description' => 'Connects the first pass from deep and takes responsibility for circulation.', 'positions' => ['DM', 'CM'], 'requirements' => ['passing' => 68, 'defending' => 45], 'tendencies' => ['pass' => 4, 'defend' => 1, 'assist' => 2]],
        'central_midfielder' => ['label' => 'Central Midfielder', 'description' => 'Provides a balanced midfield presence without a narrow specialist brief.', 'positions' => ['CM'], 'requirements' => ['passing' => 55, 'physicality' => 50], 'tendencies' => ['pass' => 2, 'defend' => 1, 'shoot' => 1]],
        'box_to_box_midfielder' => ['label' => 'Box-to-Box Midfielder', 'description' => 'Joins both phases with sustained running, support and defensive effort.', 'positions' => ['CM'], 'requirements' => ['physicality' => 65, 'defending' => 50, 'dribbling' => 50], 'tendencies' => ['pass' => 1, 'defend' => 3, 'assist' => 1, 'shoot' => 1]],
        'attacking_midfielder' => ['label' => 'Attacking Midfielder', 'description' => 'Receives between the lines and looks to connect attacks near the box.', 'positions' => ['AM'], 'requirements' => ['passing' => 60, 'dribbling' => 60], 'tendencies' => ['pass' => 2, 'assist' => 3, 'shoot' => 2]],
        'creator' => ['label' => 'Creator', 'description' => 'Looks for the final pass and helps make chances for teammates.', 'positions' => ['AM'], 'requirements' => ['passing' => 70, 'dribbling' => 55], 'tendencies' => ['pass' => 4, 'assist' => 5, 'shoot' => 0]],
        'winger' => ['label' => 'Winger', 'description' => 'Maintains width and uses pace and dribbling to progress down the flank.', 'positions' => ['LW', 'RW'], 'requirements' => ['pace' => 60, 'dribbling' => 60], 'tendencies' => ['pass' => 2, 'assist' => 3, 'shoot' => 1]],
        'inside_forward' => ['label' => 'Inside Forward', 'description' => 'Starts wide but makes more inward runs toward shooting positions.', 'positions' => ['LW', 'RW'], 'requirements' => ['dribbling' => 60, 'shooting' => 60], 'tendencies' => ['pass' => 0, 'assist' => 2, 'shoot' => 4]],
        'centre_forward' => ['label' => 'Centre Forward', 'description' => 'Leads the attack with a balanced mix of finishing, movement and link play.', 'positions' => ['ST'], 'requirements' => ['shooting' => 55, 'physicality' => 50], 'tendencies' => ['pass' => 1, 'assist' => 1, 'shoot' => 2]],
        'poacher' => ['label' => 'Poacher', 'description' => 'Stays focused on finding shooting opportunities close to goal.', 'positions' => ['ST'], 'requirements' => ['shooting' => 70], 'tendencies' => ['pass' => -2, 'assist' => -1, 'shoot' => 5]],
        'link_forward' => ['label' => 'Link Forward', 'description' => 'Drops into play more often to connect midfield and the front line.', 'positions' => ['ST'], 'requirements' => ['passing' => 60, 'physicality' => 50], 'tendencies' => ['pass' => 4, 'assist' => 3, 'shoot' => 1]],
    ];

    /** @return array<string, array<string, mixed>> */
    public function catalog(): array
    {
        $catalog = [];
        foreach (self::CATALOG as $key => $definition) {
            $catalog[$key] = $definition + ['key' => $key];
        }

        return $catalog;
    }

    public function defaultRole(PlayerPosition|string $position): OnPitchRole
    {
        $position = $this->position($position);

        return match ($position) {
            PlayerPosition::Goalkeeper => OnPitchRole::Goalkeeper,
            PlayerPosition::CentreBack => OnPitchRole::CentralDefender,
            PlayerPosition::LeftBack, PlayerPosition::RightBack => OnPitchRole::FullBack,
            PlayerPosition::DefensiveMidfielder => OnPitchRole::HoldingMidfielder,
            PlayerPosition::CentralMidfielder => OnPitchRole::CentralMidfielder,
            PlayerPosition::AttackingMidfielder => OnPitchRole::AttackingMidfielder,
            PlayerPosition::LeftWinger, PlayerPosition::RightWinger => OnPitchRole::Winger,
            PlayerPosition::Striker => OnPitchRole::CentreForward,
        };
    }

    public function isCompatible(OnPitchRole|string $role, PlayerPosition|string $position): bool
    {
        $role = $this->role($role);
        $position = $this->position($position);

        return in_array($position->value, self::CATALOG[$role->value]['positions'], true);
    }

    /** @return list<array<string, mixed>> */
    public function rolesForPosition(PlayerPosition|string $position, ?Player $player = null): array
    {
        $position = $this->position($position);
        $roles = [];
        foreach (self::CATALOG as $key => $definition) {
            if (!in_array($position->value, $definition['positions'], true)) {
                continue;
            }
            $role = OnPitchRole::from($key);
            $row = [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'position' => $position->value,
            ];
            if ($player !== null) {
                $row += $this->suitability($player, $position, $role);
            }
            $roles[] = $row;
        }

        return $roles;
    }

    /** @return array<string, mixed> */
    public function context(DatabaseInterface $database, PlayerId|string $playerId, ?Player $player = null): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $player ??= (new PlayerRepository($database))->get($id);
        $reference = (new CareerPlayerRepository($database))->byPlayer($id);
        if ($reference === null) {
            return $this->publicContext($database, $player);
        }
        $primary = $player->primaryPosition();
        $preferred = $reference->preferredOnPitchRole();
        $role = $preferred !== null && $this->isCompatible($preferred, $primary) ? $preferred : $this->defaultRole($primary);
        $capable = $this->capablePositions($database, $player);
        $secondaryRoles = [];
        foreach (array_slice($capable, 1) as $position) {
            $secondaryRoles[$position->value] = $this->rolesForPosition($position, $player);
        }

        return [
            'position' => $primary->value,
            'capable_positions' => array_map(static fn (PlayerPosition $position): string => $position->value, $capable),
            'preferred_role' => $preferred?->value,
            'role' => $role->value,
            'role_label' => self::CATALOG[$role->value]['label'],
            'role_description' => self::CATALOG[$role->value]['description'],
            'role_source' => $preferred !== null && $this->isCompatible($preferred, $primary) ? 'chosen' : 'safe_default',
            'available_roles' => $this->rolesForPosition($primary, $player),
            'secondary_role_options' => $secondaryRoles,
            'derived' => true,
        ];
    }

    /** @return array<string, mixed> */
    public function publicContext(DatabaseInterface $database, Player $player): array
    {
        $position = $player->primaryPosition();
        $role = $this->derivedRole($player, $position);

        return [
            'position' => $position->value,
            'capable_positions' => [$position->value],
            'preferred_role' => null,
            'role' => $role->value,
            'role_label' => self::CATALOG[$role->value]['label'],
            'role_description' => self::CATALOG[$role->value]['description'],
            'role_source' => 'derived',
            'available_roles' => $this->rolesForPosition($position, $player),
            'secondary_role_options' => [],
            'derived' => true,
        ];
    }

    /** @return array<string, mixed> */
    public function setPreferredRole(DatabaseInterface $database, PlayerId|string $playerId, OnPitchRole|string $role): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $player = (new PlayerRepository($database))->get($id);
        $referenceRepository = new CareerPlayerRepository($database);
        $reference = $referenceRepository->byPlayer($id);
        if ($reference === null) {
            throw new RuntimeException('On-pitch role selection is available only for the controlled Career.');
        }
        if ($player->isRetired()) {
            throw new RuntimeException('The playing Career is complete; on-pitch role selection is closed.');
        }
        $role = $this->role($role);
        if (!$this->isCompatible($role, $player->primaryPosition())) {
            throw new RuntimeException(sprintf('%s is not compatible with the Player\'s current position.', self::CATALOG[$role->value]['label']));
        }
        $referenceRepository->save($reference->withPreferredOnPitchRole($role));

        return $this->context($database, $id, $player);
    }

    /** @return array<string, OnPitchRole> keyed by Player ID */
    public function controlledRoles(DatabaseInterface $database): array
    {
        $players = new PlayerRepository($database);
        $roles = [];
        foreach ((new CareerPlayerRepository($database))->playerIds() as $playerId) {
            $player = $players->get($playerId);
            $context = $this->context($database, $player->id(), $player);
            $roles[$playerId] = OnPitchRole::from((string) $context['role']);
        }

        return $roles;
    }

    /** @return array<string, mixed> */
    public function matchRole(OnPitchRole|string|null $role, PlayerPosition|string $position): array
    {
        $position = $this->position($position);
        $resolved = $role === null || !$this->isCompatible($role, $position) ? $this->defaultRole($position) : $this->role($role);

        return [
            'key' => $resolved->value,
            'label' => self::CATALOG[$resolved->value]['label'],
            'description' => self::CATALOG[$resolved->value]['description'],
            'fallback' => $role === null || !$this->isCompatible($role, $position),
        ];
    }

    /** Small action-volume tendency; zero never changes success or team result. */
    public function actionTendency(OnPitchRole|string|null $role, string $action): int
    {
        if ($role === null) {
            return 0;
        }

        $role = $this->role($role);

        return (int) (self::CATALOG[$role->value]['tendencies'][$action] ?? 0);
    }

    private function derivedRole(Player $player, PlayerPosition $position): OnPitchRole
    {
        $attributes = $player->attributes();

        return match ($position) {
            PlayerPosition::Goalkeeper => OnPitchRole::Goalkeeper,
            PlayerPosition::CentreBack => $attributes->passing() >= $attributes->defending() + 8 ? OnPitchRole::BallPlayingDefender : OnPitchRole::CentralDefender,
            PlayerPosition::LeftBack, PlayerPosition::RightBack => $attributes->pace() >= 65 && $attributes->dribbling() >= 55 ? OnPitchRole::AttackingFullBack : OnPitchRole::FullBack,
            PlayerPosition::DefensiveMidfielder => $attributes->passing() >= $attributes->defending() + 8 ? OnPitchRole::DeepLyingPlaymaker : OnPitchRole::HoldingMidfielder,
            PlayerPosition::CentralMidfielder => $attributes->passing() >= 70 ? OnPitchRole::DeepLyingPlaymaker : ($attributes->physicality() >= 70 && $attributes->defending() >= 55 ? OnPitchRole::BoxToBoxMidfielder : OnPitchRole::CentralMidfielder),
            PlayerPosition::AttackingMidfielder => $attributes->passing() >= 70 && $attributes->dribbling() >= 55 ? OnPitchRole::Creator : OnPitchRole::AttackingMidfielder,
            PlayerPosition::LeftWinger, PlayerPosition::RightWinger => $attributes->shooting() >= $attributes->passing() + 8 ? OnPitchRole::InsideForward : OnPitchRole::Winger,
            PlayerPosition::Striker => $attributes->shooting() >= 75 ? OnPitchRole::Poacher : ($attributes->passing() >= 70 ? OnPitchRole::LinkForward : OnPitchRole::CentreForward),
        };
    }

    /** @return list<PlayerPosition> */
    private function capablePositions(DatabaseInterface $database, Player $player): array
    {
        $positions = [$player->primaryPosition()];
        foreach ((new PositionDevelopmentRepository($database, false))->state($player->id())->secondaryPositions() as $position) {
            if (!in_array($position, $positions, true)) {
                $positions[] = $position;
            }
        }

        return $positions;
    }

    /** @return array{suitability:string,why_it_fits:string,foot_context:string} */
    private function suitability(Player $player, PlayerPosition $position, OnPitchRole $role): array
    {
        $requirements = self::CATALOG[$role->value]['requirements'];
        $attributes = $player->attributes()->toArray();
        $natural = true;
        $supported = true;
        $names = [];
        foreach ($requirements as $attribute => $minimum) {
            $value = $attributes[$attribute] ?? 0;
            $natural = $natural && $value >= min(85, $minimum + 10);
            $supported = $supported && $value >= $minimum;
            if ($value >= $minimum) {
                $names[] = ucfirst($attribute);
            }
        }
        $suitability = $natural ? 'natural' : ($supported ? 'suitable' : 'developing');
        $why = $names === []
            ? 'This role is available because the position is compatible; its specialist evidence is still developing.'
            : implode(' and ', $names) . ' support this usage.';

        $foot = new PlayerFootService();

        return ['suitability' => $suitability, 'why_it_fits' => $why, 'foot_context' => $foot->positionContext($player, $position)];
    }

    private function role(OnPitchRole|string $role): OnPitchRole
    {
        return $role instanceof OnPitchRole ? $role : OnPitchRole::fromInput($role);
    }

    private function position(PlayerPosition|string $position): PlayerPosition
    {
        return $position instanceof PlayerPosition ? $position : PlayerPosition::fromInput($position);
    }
}
