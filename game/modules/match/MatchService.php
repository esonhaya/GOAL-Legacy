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
use Goal\Legacy\Modules\Match\Domain\SimulationFidelity;
use Goal\Legacy\Modules\Match\Persistence\MatchHighlightRepository;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
use Goal\Legacy\Modules\Match\Persistence\MatchSubstitutionRepository;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\PlayerDevelopmentService;
use Goal\Legacy\Modules\Player\PlayerAvailabilityService;
use Goal\Legacy\Modules\Player\Persistence\CareerEvaluationRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerAvailabilityRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerDevelopmentRepository;
use Goal\Legacy\Modules\Player\ClubExpectationService;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Persistence\PlayerSeasonStatisticsRepository;
use Goal\Legacy\Modules\Competition\DomesticCupService;
use Goal\Legacy\Modules\Competition\EuropeanCompetitionService;
use Goal\Legacy\Modules\International\InternationalCompetitionService;
use Goal\Legacy\Modules\Player\FootballSocialService;

final class MatchService
{
    private readonly FixtureGenerationService $fixtureGenerator;
    private readonly MatchSimulationService $simulator;
    private readonly StandingsService $standings;

    public function __construct(private readonly ClubService $clubService, private readonly EventDispatcherInterface $events, private readonly ?PlayerDevelopmentService $development = null, private readonly ?ClubExpectationService $expectations = null, private readonly ?PlayerAvailabilityService $availability = null, private readonly ?DomesticCupService $domesticCups = null, private readonly ?EuropeanCompetitionService $europeanCompetitions = null, private readonly ?InternationalCompetitionService $internationalCompetitions = null, private readonly ?FootballSocialService $footballSocial = null)
    {
        $this->fixtureGenerator = new FixtureGenerationService($clubService);
        $this->simulator = new MatchSimulationService($clubService, new MatchSelectionService($clubService, $availability));
        $this->standings = new StandingsService($clubService);
    }
    public function repository(DatabaseInterface $database): MatchRepository { return new MatchRepository($database); }
    public function statRepository(DatabaseInterface $database): PlayerMatchStatRepository { return new PlayerMatchStatRepository($database); }
    public function highlightRepository(DatabaseInterface $database): MatchHighlightRepository { return new MatchHighlightRepository($database); }
    public function selectionRepository(DatabaseInterface $database): MatchSelectionRepository { return new MatchSelectionRepository($database); }
    public function substitutionRepository(DatabaseInterface $database): MatchSubstitutionRepository { return new MatchSubstitutionRepository($database); }
    public function generateFixtures(DatabaseInterface $database, string|CompetitionId $competitionId, string|SeasonId $seasonId): array { $competition = $competitionId instanceof CompetitionId ? $competitionId : new CompetitionId($competitionId); $season = $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId); $matches = $this->internationalCompetitions?->isInternationalCompetition($database, $competition->value()) === true ? $this->internationalCompetitions->generateFixtures($database, $competition, $season) : ($this->europeanCompetitions?->isEuropeanCompetition($database, $competition->value()) === true ? $this->europeanCompetitions->generateFixtures($database, $competition, $season) : ($this->domesticCups?->isDomesticCup($database, $competition->value()) === true ? $this->domesticCups->generateFixtures($database, $competition, $season) : $this->fixtureGenerator->generate($database, $competition, $season))); $this->events->dispatch(new GenericEvent(MatchEventNames::FIXTURES_GENERATED, ['competition_id' => $competition->value(), 'season_id' => $season->value(), 'match_count' => count($matches)])); return $matches; }
    /** @param list<string> $competitionIds @return list<GameMatch> */
    public function generateSeasonFixtures(DatabaseInterface $database, array $competitionIds, SeasonId|string $seasonId): array
    {
        $season = $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId);
        $ordered = $competitionIds;
        usort($ordered, function (string $left, string $right) use ($database): int {
            $rank = function (string $competitionId) use ($database): int {
                if ($this->domesticCups?->isDomesticCup($database, $competitionId) === true) { return 1; }
                if ($this->europeanCompetitions?->isEuropeanCompetition($database, $competitionId) === true) { return 2; }
                if ($this->internationalCompetitions?->isInternationalCompetition($database, $competitionId) === true) { return 3; }

                return 0;
            };
            return ($rank($left) <=> $rank($right)) ?: strcmp($left, $right);
        });
        $matches = [];
        foreach ($ordered as $competitionId) {
            $matches = array_merge($matches, $this->generateFixtures($database, $competitionId, $season));
        }

        return $matches;
    }
    public function simulate(DatabaseInterface $database, string|MatchId $matchId, ?SimulationFidelity $fidelity = null): GameMatch
    {
        $repository = $this->repository($database); $match = $repository->get($matchId); if ($match->status() !== MatchStatus::Scheduled) { throw new MatchException('Only scheduled Matches can be simulated.'); }
        // Direct Match callers retain the historical full-evidence contract.
        // Production world progression resolves the boundary explicitly in
        // simulateDue(), where a controlled career reference is available.
        $fidelity ??= SimulationFidelity::Player;
        $simulation = $this->simulator->simulate($database, $match, $fidelity); $stats = $simulation->playerStats(); $highlights = $simulation->highlights(); $selections = $simulation->selections(); $substitutions = $simulation->substitutions();
        $international = $this->internationalCompetitions?->isInternationalCompetition($database, $match->competitionId()->value()) === true;
        $positions = [];
        if ($fidelity === SimulationFidelity::World && !$international) {
            $players = new PlayerRepository($database);
            foreach ($stats as $stat) { $positions[$stat->playerId()->value()] = $players->get($stat->playerId())->primaryPosition(); }
        }
        // Match persistence repositories are constructed again inside the
        // atomic write. Warm their schemas before the transaction so guarded
        // DDL can never become part of a rollback-prone Match transaction.
        new MatchSelectionRepository($database); new MatchSubstitutionRepository($database); new PlayerMatchStatRepository($database); new MatchHighlightRepository($database); new PlayerAvailabilityRepository($database); new PlayerDevelopmentRepository($database); new CareerEvaluationRepository($database);
        if ($fidelity === SimulationFidelity::World && !$international) { new PlayerSeasonStatisticsRepository($database); }
        $transactionResult = $database->transaction(function () use ($repository, $match, $simulation, $stats, $highlights, $selections, $substitutions, $database, $fidelity, $positions, $international): array {
            $completed = $match->complete($simulation->result());
            $repository->saveInTransaction($completed);
            if ($fidelity === SimulationFidelity::Player) {
                (new MatchSelectionRepository($database))->replaceForMatchInTransaction($selections);
                (new MatchSubstitutionRepository($database))->replaceForMatchInTransaction($substitutions);
                (new PlayerMatchStatRepository($database))->replaceForMatchInTransaction($stats);
            } elseif (!$international) {
                (new PlayerSeasonStatisticsRepository($database))->addMatchInTransaction($completed, $stats, $positions);
            }
            (new MatchHighlightRepository($database))->replaceForMatchInTransaction($highlights);
            $availability = $this->availability?->reconcileInTransaction($database, $completed->scheduledDate()) ?? [];
            $availability = array_merge($availability, $this->availability?->applyMatchInTransaction($database, $completed, $stats, $fidelity === SimulationFidelity::Player) ?? []);
            $development = $this->development?->applyMatchInTransaction($database, $completed, $stats, $fidelity === SimulationFidelity::Player) ?? [];
            return [$completed, $development, $availability];
        });
        [$completed, $development, $availability] = $transactionResult;
        foreach ($development as $application) {
            if ($application->applied()) { $this->events->dispatch(new GenericEvent('player.developed', $application->toArray())); }
        }
        $this->availability?->dispatchChanges($availability);
        $this->domesticCups?->recordCompletedMatch($database, $completed);
        $this->europeanCompetitions?->recordCompletedMatch($database, $completed);
        $this->internationalCompetitions?->recordCompletedMatch($database, $completed);
        $this->footballSocial?->recordMatch($database, $completed, $fidelity);
        $this->events->dispatch(new GenericEvent(MatchEventNames::COMPLETED, ['match_id' => $completed->id()->value(), 'competition_id' => $completed->competitionId()->value(), 'season_id' => $completed->seasonId()->value(), 'home_club_id' => $completed->homeClubId()->value(), 'away_club_id' => $completed->awayClubId()->value(), 'home_goals' => $completed->result()?->homeGoals(), 'away_goals' => $completed->result()?->awayGoals()]));
        $this->events->dispatch(new GenericEvent(MatchEventNames::STANDINGS_UPDATED, ['competition_id' => $completed->competitionId()->value(), 'season_id' => $completed->seasonId()->value()]));
        $this->expectations?->evaluateMatch($database, $completed, $fidelity === SimulationFidelity::World ? $simulation : null, $fidelity);
        return $completed;
    }
    /** @return list<GameMatch> */
    public function simulateDue(DatabaseInterface $database, SimulationDate $date): array { $completed = []; foreach ($this->repository($database)->dueScheduled($date) as $match) { $completed[] = $this->simulate($database, $match->id(), $this->fidelityFor($database, $match)); } return $completed; }
    /** @return list<array<string, int|string>> */
    public function standings(DatabaseInterface $database, string|CompetitionId $competitionId, string|SeasonId $seasonId): array { $competition = $competitionId instanceof CompetitionId ? $competitionId : new CompetitionId($competitionId); if ($this->domesticCups?->isDomesticCup($database, $competition->value()) === true || $this->europeanCompetitions?->isEuropeanCompetition($database, $competition->value()) === true || $this->internationalCompetitions?->isInternationalCompetition($database, $competition->value()) === true) { return []; } return $this->standings->table($database, $competition, $seasonId instanceof SeasonId ? $seasonId : new SeasonId($seasonId)); }
    public function competitionComplete(DatabaseInterface $database, string|CompetitionId $competitionId, string|SeasonId $seasonId): bool { $matches = $this->repository($database)->byCompetition($competitionId, $seasonId); return $matches !== [] && count(array_filter($matches, static fn ($match): bool => $match->status() === MatchStatus::Completed)) === count($matches); }

    private function fidelityFor(DatabaseInterface $database, GameMatch $match): SimulationFidelity
    {
        $tables = $database->connection()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('career_player_references', 'club_squad_memberships', 'international_team_squads')")->fetchAll(\PDO::FETCH_COLUMN);
        if (count($tables) < 2) {
            return SimulationFidelity::Player;
        }
        $referenceCount = (int) $database->connection()->query('SELECT COUNT(*) FROM career_player_references')->fetchColumn();
        if ($referenceCount === 0) {
            return SimulationFidelity::Player;
        }
        $statement = $database->connection()->prepare(
            'SELECT 1 FROM career_player_references careers JOIN club_squad_memberships squads ON squads.player_id = careers.player_id '
            . 'WHERE squads.season_id = :season_id AND squads.club_id IN (:home_club_id, :away_club_id) LIMIT 1'
        );
        $statement->execute(['season_id' => $match->seasonId()->value(), 'home_club_id' => $match->homeClubId()->value(), 'away_club_id' => $match->awayClubId()->value()]);

        if ($statement->fetchColumn() !== false) {
            return SimulationFidelity::Player;
        }
        if ($this->internationalCompetitions?->isInternationalCompetition($database, $match->competitionId()->value()) === true) {
            $international = $database->connection()->prepare(
                'SELECT 1 FROM career_player_references careers JOIN international_team_squads squads ON squads.player_id = careers.player_id '
                . 'WHERE squads.season_id = :season_id AND squads.national_team_id IN (:home_club_id, :away_club_id) AND squads.status = :status LIMIT 1'
            );
            $international->execute(['season_id' => $match->seasonId()->value(), 'home_club_id' => $match->homeClubId()->value(), 'away_club_id' => $match->awayClubId()->value(), 'status' => 'selected']);
            return $international->fetchColumn() === false ? SimulationFidelity::World : SimulationFidelity::Player;
        }

        return SimulationFidelity::World;
    }
    /** @return array<string, mixed>|null */
    public function playerSummary(DatabaseInterface $database, string|MatchId $matchId, string|PlayerId $playerId): ?array
    {
        $match = $this->repository($database)->get($matchId); $player = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId); $stat = array_values(array_filter($this->statRepository($database)->byMatch($match->id()), static fn (PlayerMatchStat $value): bool => $value->playerId()->value() === $player->value()))[0] ?? null; if ($stat === null) { return null; } $opponent = $stat->clubId()->value() === $match->homeClubId()->value() ? $match->awayClubId()->value() : $match->homeClubId()->value(); $position = (new PlayerRepository($database))->get($player)->primaryPosition(); return ['match_id' => $match->id()->value(), 'player_id' => $player->value(), 'club_id' => $stat->clubId()->value(), 'opponent_club_id' => $opponent, 'home_club_id' => $match->homeClubId()->value(), 'away_club_id' => $match->awayClubId()->value(), 'appeared' => $stat->appeared(), 'started' => $stat->started(), 'minutes' => $stat->minutes(), 'goals' => $stat->goals(), 'assists' => $stat->assists(), 'shots' => $stat->shots(), 'shots_on_target' => $stat->shotsOnTarget(), 'saves' => $stat->saves(), 'clean_sheets' => $stat->cleanSheets(), 'tackles' => $stat->tackles(), 'interceptions' => $stat->interceptions(), 'blocks' => $stat->blocks(), 'passes_attempted' => $stat->passesAttempted(), 'passes_completed' => $stat->passesCompleted(), 'fouls_committed' => $stat->foulsCommitted(), 'yellow_cards' => $stat->yellowCards(), 'red_cards' => $stat->redCards(), 'rating' => (new PlayerMatchRatingService())->rate($stat, $position), 'highlights' => array_values(array_map(static fn ($highlight): array => $highlight->toArray(), array_filter($this->highlightRepository($database)->byMatch($match->id()), static fn ($highlight): bool => $highlight->playerId()?->value() === $player->value() || (($highlight->data()['assist_player_id'] ?? null) === $player->value()))))];
    }
}
