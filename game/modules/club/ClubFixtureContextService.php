<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Club;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Competition\Domain\Competition;
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
use Goal\Legacy\Modules\Competition\Persistence\CompetitionRepository;
use Goal\Legacy\Modules\Match\Domain\GameMatch;

/**
 * Read-only fixture context. It composes canonical Club relationships,
 * competition facts, and already-derived Season stakes without changing
 * simulation, selection, ratings, or narrative persistence.
 */
final class ClubFixtureContextService
{
    /** @param array<string,mixed>|null $seasonStakes @param list<string> $formerClubIds @return array<string,mixed> */
    public function forMatch(GameMatch $match, Competition $competition, ?array $seasonStakes = null, array $formerClubIds = [], ?string $perspectiveClubId = null): array
    {
        $relationship = ClubRivalryCatalog::relationship($match->homeClubId()->value(), $match->awayClubId()->value());
        $opponentId = $perspectiveClubId === $match->homeClubId()->value()
            ? $match->awayClubId()->value()
            : ($perspectiveClubId === $match->awayClubId()->value() ? $match->homeClubId()->value() : null);
        $formerClubId = $opponentId !== null && in_array($opponentId, array_values(array_unique(array_map('strval', $formerClubIds))), true)
            ? $opponentId
            : null;
        $labels = [];
        if ($relationship['is_derby'] === true) {
            $labels[] = 'DERBY';
        }
        if ($relationship['is_rivalry'] === true) {
            $labels[] = 'RIVALRY';
        }
        if ($formerClubId !== null) {
            $labels[] = 'FORMER CLUB';
        }
        if ($seasonStakes !== null && $seasonStakes !== []) {
            $labels[] = 'SEASON STAKES';
        }

        $contextLabel = $relationship['name'] === null ? null : (string) $relationship['name'];
        $displayLabel = $labels === [] ? null : implode(' · ', $labels);

        return [
            'category' => $relationship['type'],
            'is_derby' => $relationship['is_derby'],
            'is_rivalry' => $relationship['is_rivalry'],
            'relationship_name' => $contextLabel,
            'relationship_reason' => $relationship['reason'],
            'pair_key' => $relationship['pair_key'],
            'labels' => $labels,
            'label' => $displayLabel,
            'display_label' => $displayLabel === null ? $contextLabel : $displayLabel,
            'competition' => [
                'id' => $competition->id()->value(),
                'name' => $competition->name(),
                'type' => $competition->type()->value,
                'stage' => $this->stageLabel($competition->type(), $match->round()),
                'round' => $match->round(),
            ],
            'season_stakes' => $seasonStakes,
            'former_club' => $formerClubId === null ? null : ['club_id' => $formerClubId, 'label' => 'Facing Former Club'],
            'important' => $labels !== [],
            'source_key' => 'fixture-context:v1:' . $match->id()->value(),
            'simulation_effect' => false,
        ];
    }

    /** @param array<string,mixed>|null $seasonStakes @param list<string> $formerClubIds @return array<string,mixed> */
    public function context(DatabaseInterface $database, GameMatch $match, ?array $seasonStakes = null, array $formerClubIds = [], ?string $perspectiveClubId = null): array
    {
        $competition = (new CompetitionRepository($database))->get($match->competitionId());

        return $this->forMatch($match, $competition, $seasonStakes, $formerClubIds, $perspectiveClubId);
    }

    private function stageLabel(CompetitionType $type, int $round): string
    {
        return match ($type) {
            CompetitionType::DomesticLeague => 'League round ' . $round,
            CompetitionType::DomesticCup => 'Domestic Cup round ' . $round,
            CompetitionType::Continental => 'European round ' . $round,
            CompetitionType::International => 'International round ' . $round,
            CompetitionType::FriendlyTournament => 'Friendly round ' . $round,
        };
    }
}
