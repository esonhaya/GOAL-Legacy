<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Modules\Nation\Domain\Nation;
use Goal\Legacy\Modules\Nation\Domain\NationId;
use Goal\Legacy\Modules\Player\Domain\DevelopmentProfile;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\PlayerException;
use Goal\Legacy\Modules\Player\Domain\PlayerCareerState;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Domain\PlayerFoot;
use Goal\Legacy\Modules\Player\Domain\WeakFootTier;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

final class PlayerCreationService
{
    /** @param list<Nation> $nations */
    public function __construct(private readonly array $nations)
    {
    }

    public function create(PlayerCreationRequest $request): Player
    {
        $nationIds = [];
        foreach ($this->nations as $nation) {
            $nationIds[$nation->id()->value()] = true;
        }
        $primaryNationId = $this->nationId($request->primaryNationId, $nationIds, 'primary nationality');
        $secondaryNationIds = [];
        foreach ($request->secondaryNationIds as $nationId) {
            $secondaryNationIds[] = $this->nationId($nationId, $nationIds, 'secondary nationality');
        }
        $birthNationId = $this->nationId($request->birthNationId ?? $request->primaryNationId, $nationIds, 'birth Nation');
        $eligibilityNationIds = $request->eligibilityNationIds === null
            ? [$primaryNationId]
            : array_map(fn (string $nationId): NationId => $this->nationId($nationId, $nationIds, 'eligibility'), $request->eligibilityNationIds);
        $position = PlayerPosition::fromInput($request->primaryPosition);
        $profile = DevelopmentProfile::fromInput($request->developmentProfile);
        $attributes = $request->attributes ?? $this->generateAttributes($request, $profile);
        $preferredFoot = $request->preferredFoot ?? PlayerFoot::fromStableSeed($request->playerId, $request->seed);
        $weakFoot = $request->weakFoot ?? WeakFootTier::fromStableSeed($request->playerId, $request->seed, $profile);

        try {
            return new Player(
                new PlayerId($request->playerId),
                $request->firstName,
                $request->lastName,
                $request->preferredName ?? trim($request->firstName . ' ' . $request->lastName),
                SimulationDate::fromIsoString($request->birthDate),
                $primaryNationId,
                $this->sortNationIds($secondaryNationIds),
                $birthNationId,
                $this->sortNationIds($eligibilityNationIds),
                $request->heightCm,
                $request->weightKg,
                $position,
                $attributes,
                $request->potential,
                $profile,
                $request->seed,
                PlayerCareerState::Active,
                $preferredFoot,
                $weakFoot,
            );
        } catch (PlayerException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new PlayerException(sprintf('Player creation request is invalid: %s', $exception->getMessage()), 0, $exception);
        }
    }

    /** @param array<string, bool> $nationIds */
    private function nationId(string $value, array $nationIds, string $label): NationId
    {
        $id = new NationId($value);
        if (!isset($nationIds[$id->value()])) {
            throw new PlayerException(sprintf('Player %s references unknown Nation "%s".', $label, $id->value()));
        }

        return $id;
    }

    private function generateAttributes(PlayerCreationRequest $request, DevelopmentProfile $profile): PlayerAttributeSet
    {
        $gap = match ($profile) {
            DevelopmentProfile::LateBloomer => 34,
            DevelopmentProfile::Regular => 22,
            DevelopmentProfile::Prodigy => 12,
        };
        $base = max(0, $request->potential - $gap);
        $values = [];
        foreach (['pace', 'shooting', 'passing', 'dribbling', 'defending', 'physicality'] as $index => $attribute) {
            $digest = hash('sha256', implode('|', [$request->playerId, $request->seed, $profile->value, $attribute]), true);
            $jitter = (ord($digest[$index]) % 7) - 3;
            $values[$attribute] = min($request->potential, max(0, $base + $jitter));
        }

        return new PlayerAttributeSet(...array_values($values));
    }

    /** @param list<NationId> $ids @return list<NationId> */
    private function sortNationIds(array $ids): array
    {
        usort($ids, static fn (NationId $left, NationId $right): int => $left->value() <=> $right->value());

        return $ids;
    }
}
