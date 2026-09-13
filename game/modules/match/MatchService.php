<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match;

use Goal\Legacy\Core\Events\EventDispatcherInterface;
use Goal\Legacy\Core\Events\GenericEvent;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Match\Domain\MatchEventNames;
use Goal\Legacy\Modules\Match\Domain\MatchException;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\Persistence\MatchHighlightRepository;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

final class MatchService
{
    private readonly FixtureGenerationService $fixtureGenerator;
    private readonly MatchSimulationService $simulator;
    private readonly StandingsService $standings;

    public function __construct(private readonly ClubService $clubService, private readonly EventDispatcherInterface $events)
    {
        $this->fixtureGenerator = new FixtureGenerationService($clubService);
        $this->simulator = new MatchSimulationService($clubService);
        $this->standings = new StandingsService($clubService);
    }
    public function repository(DatabaseInterface $database): MatchRepository { return new MatchRepository($database); }
    public function statRepository(DatabaseInterface $database): PlayerMatchStatRepository { return new PlayerMatchStatRepository($database); }
    public function highlightRepository(DatabaseInterface $database): MatchHighlightRepository { return new MatchHighlightRepository($database); }
    public function generateFixtures(DatabaseInterface $database, string|CompetitionId $competitionId, string|SeasonId $seasonId): array { $competition = $competitionId instanceof CompetitionId ? $competitionId : new CompetitionId($competitionId); $season = $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId); $matches = $this->fixtureGenerator->generate($database, $competition, $season); $this->events->dispatch(new GenericEvent(MatchEventNames::FIXTURES_GENERATED, ['competition_id' => $competition->value(), 'season_id' => $season->value(), 'match_count' => count($matches)])); return $matches; }
    public function simulate(DatabaseInterface $database, string|MatchId $matchId): GameMatch
    {
        $repository = $this->repository($database); $match = $repository->get($matchId); if ($match->status() !== MatchStatus::Scheduled) { throw new MatchException('Only scheduled Matches can be simulated.'); }
        $simulation = $this->simulator->simulate($database, $match); $stats = $simulation->playerStats(); $highlights = $simulation->highlights(); $completed = $database->transaction(function () use ($repository, $match, $simulation, $stats, $highlights, $database): GameMatch { $completed = $match->complete($simulation->result()); $repository->saveInTransaction($completed); (new PlayerMatchStatRepository($database))->replaceForMatchInTransaction($stats); (new MatchHighlightRepository($database))->replaceForMatchInTransaction($highlights); return $completed; });
        $this->events->dispatch(new GenericEvent(MatchEventNames::COMPLETED, ['match_id' => $completed->id()->value(), 'competition_id' => $completed->competitionId()->value(), 'season_id' => $completed->seasonId()->value(), 'home_club_id' => $completed->homeClubId()->value(), 'away_club_id' => $completed->awayClubId()->value(), 'home_goals' => $completed->result()?->homeGoals(), 'away_goals' => $completed->result()?->awayGoals()]));
        $this->events->dispatch(new GenericEvent(MatchEventNames::STANDINGS_UPDATED, ['competition_id' => $completed->competitionId()->value(), 'season_id' => $completed->seasonId()->value()]));
        return $completed;
    }
    /** @return list<GameMatch> */
    public function simulateDue(DatabaseInterface $database, SimulationDate $date): array { $completed = []; foreach ($this->repository($database)->dueScheduled($date) as $match) { $completed[] = $this->simulate($database, $match->id()); } return $completed; }
    /** @return list<array<string, int|string>> */
    public function standings(DatabaseInterface $database, string|CompetitionId $competitionId, string|SeasonId $seasonId): array { return $this->standings->table($database, $competitionId instanceof CompetitionId ? $competitionId : new CompetitionId($competitionId), $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId)); }
    public function competitionComplete(DatabaseInterface $database, string|CompetitionId $competitionId, string|SeasonId $seasonId): bool { $matches = $this->repository($database)->byCompetition($competitionId, $seasonId); return $matches !== [] && count(array_filter($matches, static fn ($match): bool => $match->status() === MatchStatus::Completed)) === count($matches); }
    /** @return array<string, mixed>|null */
    public function playerSummary(DatabaseInterface $database, string|MatchId $matchId, string|PlayerId $playerId): ?array
    {
        $match = $this->repository($database)->get($matchId); $player = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId); $stat = array_values(array_filter($this->statRepository($database)->byMatch($match->id()), static fn (PlayerMatchStat $value): bool => $value->playerId()->value() === $player->value()))[0] ?? null; if ($stat === null) { return null; } $opponent = $stat->clubId()->value() === $match->homeClubId()->value() ? $match->awayClubId()->value() : $match->homeClubId()->value(); return ['match_id' => $match->id()->value(), 'player_id' => $player->value(), 'club_id' => $stat->clubId()->value(), 'opponent_club_id' => $opponent, 'home_club_id' => $match->homeClubId()->value(), 'away_club_id' => $match->awayClubId()->value(), 'home_goals' => $match->result()?->homeGoals(), 'away_goals' => $match->result()?->awayGoals(), 'appeared' => $stat->appeared(), 'started' => $stat->started(), 'minutes' => $stat->minutes(), 'goals' => $stat->goals(), 'highlights' => array_values(array_map(static fn ($highlight): array => $highlight->toArray(), array_filter($this->highlightRepository($database)->byMatch($match->id()), static fn ($highlight): bool => $highlight->playerId()?->value() === $player->value())) )];
    }
}
