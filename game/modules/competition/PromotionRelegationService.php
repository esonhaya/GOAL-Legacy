<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Competition;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Competition\Domain\CompetitionDefinition;
use Goal\Legacy\Modules\Match\StandingsService;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonStatus;
use RuntimeException;

/** Determines the one bounded tier exchange used by Season rollover. */
final class PromotionRelegationService
{
    /** Direct automatic places only; playoff places remain deferred. */
    private const AUTOMATIC_EXCHANGE_COUNTS = [
        'england' => 2,
        'spain' => 2,
        'germany' => 2,
        'italy' => 2,
        'france' => 2,
    ];

    public function __construct(private readonly ClubService $clubService)
    {
    }

    /**
     * @param list<CompetitionDefinition> $definitions
     * @return array{promoted:list<array{club_id:string,from_competition_id:string,to_competition_id:string,nation_id:string}>,relegated:list<array{club_id:string,from_competition_id:string,to_competition_id:string,nation_id:string}>}
     */
    public function determine(DatabaseInterface $database, Season $season, array $definitions): array
    {
        if ($season->status() !== SeasonStatus::Completed) {
            throw new RuntimeException('Promotion/relegation requires a completed outgoing Season.');
        }
        $byNation = [];
        foreach ($definitions as $definition) {
            $nation = $definition->nationId()->value();
            $byNation[$nation][$definition->tier()][] = $definition;
        }

        $standings = new StandingsService($this->clubService);
        $promoted = [];
        $relegated = [];
        foreach (self::AUTOMATIC_EXCHANGE_COUNTS as $nation => $count) {
            $tiers = $byNation[$nation] ?? [];
            if (!isset($tiers[1], $tiers[2])) {
                continue;
            }
            if (count($tiers[1]) !== 1 || count($tiers[2]) !== 1) {
                throw new RuntimeException(sprintf('Promotion/relegation requires exactly one tier-1 and tier-2 Competition for %s.', $nation));
            }

            $top = $tiers[1][0];
            $lower = $tiers[2][0];
            $topTable = $standings->table($database, $top->id(), $season->id());
            $lowerTable = $standings->table($database, $lower->id(), $season->id());
            if (count($topTable) < $count || count($lowerTable) < $count) {
                throw new RuntimeException(sprintf('Competition pair %s does not have enough Clubs for an exchange of %d.', $nation, $count));
            }

            foreach (array_slice($lowerTable, 0, $count) as $row) {
                $promoted[] = [
                    'club_id' => (string) $row['club_id'],
                    'from_competition_id' => $lower->id()->value(),
                    'to_competition_id' => $top->id()->value(),
                    'nation_id' => $nation,
                ];
            }
            foreach (array_slice($topTable, -$count) as $row) {
                $relegated[] = [
                    'club_id' => (string) $row['club_id'],
                    'from_competition_id' => $top->id()->value(),
                    'to_competition_id' => $lower->id()->value(),
                    'nation_id' => $nation,
                ];
            }
        }

        $this->assertUniqueMovement($promoted, $relegated);

        return ['promoted' => $promoted, 'relegated' => $relegated];
    }

    /** @param list<array{club_id:string,from_competition_id:string,to_competition_id:string,nation_id:string}> $promoted @param list<array{club_id:string,from_competition_id:string,to_competition_id:string,nation_id:string}> $relegated */
    private function assertUniqueMovement(array $promoted, array $relegated): void
    {
        $clubs = [];
        foreach (array_merge($promoted, $relegated) as $movement) {
            if (isset($clubs[$movement['club_id']])) {
                throw new RuntimeException(sprintf('Club "%s" was selected for multiple tier movements.', $movement['club_id']));
            }
            $clubs[$movement['club_id']] = true;
        }
    }
}
