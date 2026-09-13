<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Persistence\SeasonRepository;

final class FixtureGenerationService
{
    public function __construct(private readonly ClubService $clubService) {}

    /** @return list<GameMatch> */
    public function generate(DatabaseInterface $database, CompetitionId $competitionId, SeasonId $seasonId): array
    {
        $membershipRepository = $this->clubService->membershipRepository($database);
        $clubs = array_values(array_unique(array_map(static fn ($membership): string => $membership->clubId()->value(), $membershipRepository->byCompetition($competitionId, $seasonId))));
        sort($clubs, SORT_STRING);
        $count = count($clubs);
        if ($count < 2 || $count % 2 !== 0) { throw new \InvalidArgumentException('League fixture generation requires an even number of Clubs of at least two.'); }
        $season = (new SeasonRepository($database))->get($seasonId);
        $roundCount = $count - 1;
        $totalRounds = $roundCount * 2;
        $cadence = max(1, intdiv(max(1, $season->startDate()->daysUntil($season->endDate())), max(1, $totalRounds - 1)));
        $rotation = $clubs;
        $repository = new MatchRepository($database);
        $database->transaction(function () use ($database, $repository, $competitionId, $seasonId, $season, $roundCount, $cadence, &$rotation): void {
            for ($round = 0; $round < $roundCount; $round++) {
                $this->createRound($database, $repository, $competitionId, $seasonId, $season->startDate()->addDays($round * $cadence), $round + 1, $rotation, false);
                $rotation = $this->rotate($rotation);
            }
            for ($round = 0; $round < $roundCount; $round++) {
                $roundNumber = $round + $roundCount + 1;
                $this->createRound($database, $repository, $competitionId, $seasonId, $season->startDate()->addDays(($roundNumber - 1) * $cadence), $roundNumber, $rotation, true);
                $rotation = $this->rotate($rotation);
            }
        });

        return $repository->byCompetition($competitionId, $seasonId);
    }

    /** @param list<string> $rotation */
    private function createRound(DatabaseInterface $database, MatchRepository $repository, CompetitionId $competitionId, SeasonId $seasonId, \Goal\Legacy\Modules\World\Domain\SimulationDate $date, int $round, array $rotation, bool $reverse): void
    {
        $half = intdiv(count($rotation), 2);
        for ($i = 0; $i < $half; $i++) {
            $first = $rotation[$i]; $second = $rotation[count($rotation) - 1 - $i];
            $home = (($round + $i) % 2 === 0) ? $first : $second;
            $away = (($round + $i) % 2 === 0) ? $second : $first;
            if ($reverse) { [$home, $away] = [$away, $home]; }
            $match = new GameMatch(new MatchId(sprintf('%s.%s.r%02d.%s.%s', $competitionId->value(), $seasonId->value(), $round, $home, $away)), $competitionId, $seasonId, $round, $date, new ClubId($home), new ClubId($away));
            if (!$repository->exists($match->id())) { $repository->saveInTransaction($match); }
        }
    }

    /** @param list<string> $rotation @return list<string> */
    private function rotate(array $rotation): array
    {
        return array_merge([$rotation[0], $rotation[count($rotation) - 1]], array_slice($rotation, 1, -1));
    }
}
