<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\World\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Goal\Legacy\Core\Time\SimulationTime;

final readonly class World
{
    private WorldId $id;
    private string $label;
    private int $universeSeed;
    private DateTimeImmutable $createdAt;
    private SimulationTime $currentTime;
    private ?SeasonId $currentSeasonId;
    /** @var list<string> */
    private array $nationIds;
    /** @var list<string> */
    private array $competitionIds;
    /** @var list<string> */
    private array $contentPackageIds;
    private WorldSimulationState $simulationState;

    /** @param list<string> $nationIds @param list<string> $competitionIds @param list<string> $contentPackageIds */
    public function __construct(
        WorldId $id,
        string $label,
        int $universeSeed,
        DateTimeImmutable $createdAt,
        SimulationTime $currentTime,
        ?SeasonId $currentSeasonId,
        array $nationIds,
        array $competitionIds,
        array $contentPackageIds,
        WorldSimulationState $simulationState = WorldSimulationState::Running,
    ) {
        if (trim($label) === '') {
            throw new InvalidArgumentException('World labels cannot be empty.');
        }
        if ($universeSeed < 0) {
            throw new InvalidArgumentException('World universe seeds cannot be negative.');
        }
        $this->id = $id;
        $this->label = $label;
        $this->universeSeed = $universeSeed;
        $this->createdAt = $createdAt->setTimezone(new \DateTimeZone('UTC'));
        $this->currentTime = $currentTime;
        $this->currentSeasonId = $currentSeasonId;
        $this->nationIds = self::normalizeReferences($nationIds, 'Nation');
        $this->competitionIds = self::normalizeReferences($competitionIds, 'Competition');
        $this->contentPackageIds = self::normalizeReferences($contentPackageIds, 'Content package');
        $this->simulationState = $simulationState;
    }

    public function id(): WorldId { return $this->id; }

    public function label(): string { return $this->label; }

    public function universeSeed(): int { return $this->universeSeed; }

    public function createdAt(): DateTimeImmutable { return $this->createdAt; }

    public function currentTime(): SimulationTime { return $this->currentTime; }

    public function currentDate(SimulationCalendar $calendar): SimulationDate
    {
        return $calendar->dateAt($this->currentTime);
    }

    public function currentSeasonId(): ?SeasonId { return $this->currentSeasonId; }

    /** @return list<string> */
    public function nationIds(): array { return $this->nationIds; }

    /** @return list<string> */
    public function competitionIds(): array { return $this->competitionIds; }

    /** @return list<string> */
    public function contentPackageIds(): array { return $this->contentPackageIds; }

    public function simulationState(): WorldSimulationState { return $this->simulationState; }

    public function withTimeline(SimulationTime $time, ?SeasonId $seasonId = null): self
    {
        return new self(
            $this->id,
            $this->label,
            $this->universeSeed,
            $this->createdAt,
            $time,
            $seasonId ?? $this->currentSeasonId,
            $this->nationIds,
            $this->competitionIds,
            $this->contentPackageIds,
            $this->simulationState,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'competition_ids' => $this->competitionIds,
            'content_package_ids' => $this->contentPackageIds,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'current_season_id' => $this->currentSeasonId?->value(),
            'current_time' => $this->currentTime->ticks(),
            'id' => $this->id->value(),
            'label' => $this->label,
            'nation_ids' => $this->nationIds,
            'simulation_state' => $this->simulationState->value,
            'universe_seed' => $this->universeSeed,
        ];
    }

    /** @param list<string> $references @return list<string> */
    private static function normalizeReferences(array $references, string $label): array
    {
        $ordered = [];
        foreach ($references as $reference) {
            if (!is_string($reference) || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $reference) !== 1) {
                throw new InvalidArgumentException(sprintf('%s references must be valid stable IDs.', $label));
            }
            if (isset($ordered[$reference])) {
                throw new InvalidArgumentException(sprintf('%s references cannot contain duplicates.', $label));
            }
            $ordered[$reference] = true;
        }
        ksort($ordered, SORT_STRING);
        return array_keys($ordered);
    }
}
