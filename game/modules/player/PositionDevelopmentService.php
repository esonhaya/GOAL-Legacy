<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Domain\PositionDevelopmentState;
use Goal\Legacy\Modules\Player\Domain\TrainingIntensity;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PositionDevelopmentRepository;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use RuntimeException;

final class PositionDevelopmentService
{
    private const COMPLETE_PROGRESS = 100;

    /** @return array<string,mixed> */
    public function context(DatabaseInterface $database, PlayerId|string $playerId, SimulationDate $date): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $player = (new PlayerRepository($database))->get($id);
        $state = (new PositionDevelopmentRepository($database, false))->state($id);
        $secondary = array_values(array_filter($state->secondaryPositions(), static fn (PlayerPosition $position): bool => $position !== $player->primaryPosition()));
        $foot = new PlayerFootService();
        $eligible = [];
        foreach (PositionDevelopmentRules::compatibleWith($player->primaryPosition()) as $position) {
            if (in_array($position, $secondary, true) || !PositionDevelopmentRules::hasAttributeFit($position, $player->attributes())) {
                continue;
            }
            $eligible[] = ['position' => $position->value, 'fit_score' => PositionDevelopmentRules::fitScore($position, $player->attributes()), 'foot_context' => $foot->positionContext($player, $position), 'foot_suitability' => $foot->positionSuitability($player, $position)];
        }
        usort($eligible, static fn (array $left, array $right): int => (($right['fit_score'] <=> $left['fit_score']) ?: strcmp($left['position'], $right['position'])));
        $familiarity = [];
        foreach (PlayerPosition::cases() as $position) {
            $tier = 'unfamiliar';
            $progress = $state->progressFor($position);
            if ($position === $player->primaryPosition()) { $tier = 'primary'; $progress = 100; }
            elseif (in_array($position, $secondary, true)) { $tier = 'secondary'; $progress = 100; }
            elseif ($state->developingPosition() === $position) { $tier = 'developing'; }
            $familiarity[$position->value] = ['tier' => $tier, 'progress' => $progress];
        }

        return [
            'primary_position' => $player->primaryPosition()->value,
            'preferred_foot' => $player->preferredFoot()->value,
            'weak_foot' => $player->weakFoot()->value,
            'foot_context' => $foot->positionContext($player, $player->primaryPosition()),
            'secondary_positions' => array_map(static fn (PlayerPosition $position): string => $position->value, $secondary),
            'developing_position' => $state->developingPosition()?->value,
            'progress' => $state->developingPosition() === null ? 0 : $state->progressFor($state->developingPosition()),
            'eligible_next_positions' => $eligible,
            'familiarity' => $familiarity,
            'updated_date' => $state->updatedDate()?->toIsoString(),
            'can_change_primary' => $state->developingPosition() === null && $secondary !== [],
            'date' => $date->toIsoString(),
        ];
    }

    public function setFocus(DatabaseInterface $database, PlayerId|string $playerId, PlayerPosition|string $position, SimulationDate $date): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $target = $position instanceof PlayerPosition ? $position : PlayerPosition::fromInput($position);
        $this->assertControlledAndActive($database, $id);
        $player = (new PlayerRepository($database))->get($id);
        $state = (new PositionDevelopmentRepository($database, true))->state($id);
        if (!in_array($target, PositionDevelopmentRules::compatibleWith($player->primaryPosition()), true) || in_array($target, $state->secondaryPositions(), true) || !PositionDevelopmentRules::hasAttributeFit($target, $player->attributes())) {
            throw new RuntimeException('That position is not an eligible adjacent development path.');
        }
        $database->transaction(function () use ($database, $state, $target, $date): void {
            (new PositionDevelopmentRepository($database, true))->saveStateInTransaction($state->withFocus($target, $date));
        });

        return $this->context($database, $id, $date);
    }

    public function cancelFocus(DatabaseInterface $database, PlayerId|string $playerId, SimulationDate $date): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $this->assertControlledAndActive($database, $id);
        $repository = new PositionDevelopmentRepository($database, true);
        $state = $repository->state($id);
        $database->transaction(fn (): mixed => $repository->saveStateInTransaction($state->withFocus(null, $date)));

        return $this->context($database, $id, $date);
    }

    /** Must be called only after the canonical TrainingService applied a new block. */
    public function applyTrainingInTransaction(DatabaseInterface $database, PlayerId $playerId, SimulationDate $date, int $weeks, TrainingIntensity $intensity): ?array
    {
        if (!$this->isControlled($database, $playerId)) {
            return null;
        }
        $repository = new PositionDevelopmentRepository($database, false);
        $state = $repository->state($playerId);
        $target = $state->developingPosition();
        if ($target === null) {
            return null;
        }
        $pointsPerWeek = match ($intensity) {
            TrainingIntensity::Light => 7,
            TrainingIntensity::Normal => 10,
            TrainingIntensity::Intense => 13,
        };
        $next = $state->progressFor($target) + max(1, $weeks) * $pointsPerWeek;
        $repository = new PositionDevelopmentRepository($database, true);
        $repository->saveStateInTransaction($state->withProgress($target, $next, $date));

        return $this->context($database, $playerId, $date);
    }

    public function changePrimary(DatabaseInterface $database, PlayerId|string $playerId, PlayerPosition|string $position, SimulationDate $date): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $target = $position instanceof PlayerPosition ? $position : PlayerPosition::fromInput($position);
        $this->assertControlledAndActive($database, $id);
        $players = new PlayerRepository($database);
        $player = $players->get($id);
        if ($player->primaryPosition() === $target) {
            return $this->context($database, $id, $date);
        }
        $repository = new PositionDevelopmentRepository($database, true);
        $state = $repository->state($id);
        if (!in_array($target, $state->secondaryPositions(), true) || $state->progressFor($target) < self::COMPLETE_PROGRESS) {
            throw new RuntimeException('That position has not been fully developed yet.');
        }
        $old = $player->primaryPosition();
        $careerRepository = new CareerPlayerRepository($database);
        $careerReference = $careerRepository->byPlayer($player->id());
        $roles = new OnPitchRoleService();
        $database->transaction(function () use ($database, $players, $repository, $careerRepository, $careerReference, $roles, $player, $old, $target, $state, $date): void {
            $players->saveInTransaction($player->withPrimaryPosition($target));
            $repository->saveStateInTransaction($state->afterPrimaryChange($old, $target, $date));
            $repository->saveChangeInTransaction($player->id(), $old, $target, $date);
            if ($careerReference !== null && ($preferred = $careerReference->preferredOnPitchRole()) !== null && !$roles->isCompatible($preferred, $target)) {
                $careerRepository->save($careerReference->withPreferredOnPitchRole($roles->defaultRole($target)));
            }
        });

        return $this->context($database, $id, $date);
    }

    /** @return list<array<string,string>> */
    public function history(DatabaseInterface $database, PlayerId|string $playerId): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);

        return (new PositionDevelopmentRepository($database, false))->changes($id);
    }

    public function selectionInfluence(DatabaseInterface $database, Player $player): int
    {
        if (!$this->isControlled($database, $player->id())) {
            return 0;
        }
        $state = (new PositionDevelopmentRepository($database, false))->state($player->id());

        return $state->developingPosition() === null ? 0 : -2;
    }

    /** @return list<string> */
    public function capabilityValues(DatabaseInterface $database, Player $player): array
    {
        $state = (new PositionDevelopmentRepository($database, false))->state($player->id());
        $positions = [$player->primaryPosition()->value];
        foreach ($state->secondaryPositions() as $position) {
            if (!in_array($position->value, $positions, true)) { $positions[] = $position->value; }
        }

        return $positions;
    }

    private function assertControlledAndActive(DatabaseInterface $database, PlayerId $playerId): void
    {
        if (!$this->isControlled($database, $playerId)) {
            throw new RuntimeException('Positional development is available only for the controlled Career.');
        }
        if ((new PlayerRepository($database))->get($playerId)->isRetired()) {
            throw new RuntimeException('The playing Career is complete; positional development is closed.');
        }
    }

    private function isControlled(DatabaseInterface $database, PlayerId $playerId): bool
    {
        return (new CareerPlayerRepository($database))->byPlayer($playerId) !== null;
    }
}
