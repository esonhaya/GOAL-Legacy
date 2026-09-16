<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Presentation;

use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
use Goal\Legacy\Modules\Competition\Persistence\CompetitionRepository;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\SelectionStatus;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
use Goal\Legacy\Modules\Match\Persistence\MatchSubstitutionRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

/** Assembles presentation input from canonical services and persisted facts. */
final class CareerPresentationService
{
    public function __construct(private readonly CoreServices $services)
    {
    }

    /** @return array{world:object,summary:array<string,mixed>,date:SimulationDate} */
    public function snapshot(DatabaseInterface $database, string $saveId): array
    {
        $worldService = $this->services->worldModule()->service();
        $world = $worldService->load($database, $saveId);
        $date = $world->currentDate($worldService->calendar());
        $career = (new CareerPlayerRepository($database))->get($saveId);
        $summary = (new PlayerCareerProgressionQuery($this->services->clubModule()->service()))->summary(
            $database,
            $career->playerId(),
            $date,
            $world->currentSeasonId(),
        );

        return ['world' => $world, 'summary' => $summary, 'date' => $date];
    }

    /** @param array<string, mixed> $summary @return array<string, mixed>|null */
    public function nextMatch(DatabaseInterface $database, array $summary): ?array
    {
        $next = $summary['next_scheduled_match'] ?? null;
        if (!is_array($next) || !isset($next['match_id'])) {
            return null;
        }
        $match = $this->services->matchModule()->service()->repository($database)->get((string) $next['match_id']);
        $clubs = $this->services->clubModule()->service()->repository($database);
        $competition = (new CompetitionRepository($database))->get($match->competitionId());

        return [
            'match_id' => $match->id()->value(),
            'date' => $match->scheduledDate()->toIsoString(),
            'home_club' => $clubs->get($match->homeClubId())->canonicalName(),
            'away_club' => $clubs->get($match->awayClubId())->canonicalName(),
            'competition' => $competition->name(),
            'competition_type' => $competition->type()->value,
            'competition_id' => $competition->id()->value(),
            'season_id' => $match->seasonId()->value(),
        ];
    }

    /** @param array<string, mixed> $summary @return array<string, int|string>|null */
    public function clubContext(DatabaseInterface $database, array $summary): ?array
    {
        $club = is_array($summary['current_club'] ?? null) ? $summary['current_club'] : null;
        $competition = is_array($summary['current_competition'] ?? null) ? $summary['current_competition'] : null;
        $seasonId = $this->seasonId($summary);
        if ($club === null || $competition === null || $seasonId === null || !isset($competition['id'])) {
            return null;
        }
        $competitionRecord = (new CompetitionRepository($database))->get((string) $competition['id']);
        if ($competitionRecord->type() !== CompetitionType::DomesticLeague) {
            return null;
        }
        $table = $this->services->matchModule()->service()->standings($database, $competitionRecord->id(), $seasonId);
        foreach ($table as $index => $row) {
            if (($row['club_id'] ?? null) === ($club['id'] ?? null)) {
                return [
                    'position' => $index + 1,
                    'played' => (int) ($row['played'] ?? 0),
                    'points' => (int) ($row['points'] ?? 0),
                ];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $summary @return array<string, mixed> */
    public function matchday(DatabaseInterface $database, GameMatch $match, string $playerId, ?string $controlledClubId = null): array
    {
        $matchService = $this->services->matchModule()->service();
        $clubs = $this->services->clubModule()->service()->repository($database);
        $players = new PlayerRepository($database);
        $competition = (new CompetitionRepository($database))->get($match->competitionId());
        $homeClub = $clubs->get($match->homeClubId());
        $awayClub = $clubs->get($match->awayClubId());
        $player = $players->get($playerId);
        $selection = $this->playerSelection($database, $match, $playerId);
        $performance = $matchService->playerSummary($database, $match->id(), $playerId);
        $performance ??= [
            'appeared' => false,
            'started' => false,
            'minutes' => 0,
            'rating' => null,
        ];
        $performance['selection_status'] = $selection?->status()->value ?? SelectionStatus::NotSelected->value;
        $performance['position'] = $player->primaryPosition()->value;
        $performance['player_name'] = $player->preferredName();
        $performance['substitution_minute'] = $this->substitutionMinute($database, $match, $playerId);
        $controlledClubId ??= (string) ($this->services->clubModule()->service()->squadRepository($database)->byPlayer($playerId, $match->seasonId())[0]?->clubId()->value() ?? ($performance['club_id'] ?? ''));
        if ($controlledClubId === '') {
            $controlledClubId = $performance['club_id'] ?? $match->homeClubId()->value();
        }
        $result = $match->result();
        $homeGoals = $result?->homeGoals() ?? 0;
        $awayGoals = $result?->awayGoals() ?? 0;
        $controlledHome = $controlledClubId === $match->homeClubId()->value();
        $controlledGoals = $controlledHome ? $homeGoals : $awayGoals;
        $opponentGoals = $controlledHome ? $awayGoals : $homeGoals;
        $perspectiveResult = $controlledGoals === $opponentGoals ? 'draw' : ($controlledGoals > $opponentGoals ? 'win' : 'loss');
        $postDate = $match->scheduledDate();
        $postSummary = (new PlayerCareerProgressionQuery($this->services->clubModule()->service()))->summary(
            $database,
            $player->id(),
            $postDate,
            $match->seasonId(),
        );
        $postContext = $competition->type() === CompetitionType::DomesticLeague
            ? $this->clubContext($database, $postSummary)
            : null;

        return [
            'competition' => $competition->name(),
            'competition_type' => $competition->type()->value,
            'date' => $match->scheduledDate()->toIsoString(),
            'home_club' => $homeClub->canonicalName(),
            'away_club' => $awayClub->canonicalName(),
            'controlled_club_id' => $controlledClubId,
            'controlled_club' => $controlledHome ? $homeClub->canonicalName() : $awayClub->canonicalName(),
            'result' => ['home_goals' => $homeGoals, 'away_goals' => $awayGoals],
            'perspective_result' => $perspectiveResult,
            'performance' => $performance,
            'highlights' => $this->highlightLines($database, $match, $playerId),
            'post_match' => [
                'recent_form' => $postSummary['recent_form'] ?? [],
                'season_stats' => $postSummary['season_stats'] ?? [],
                'season_performance' => $postSummary['season_performance'] ?? [],
                'club_position' => $postContext['position'] ?? null,
                'club_points' => $postContext['points'] ?? null,
            ],
        ];
    }

    /** @param array<string, mixed> $summary @return array<string, mixed> */
    public function world(DatabaseInterface $database, array $summary, SimulationDate $date): array
    {
        $competition = is_array($summary['current_competition'] ?? null) ? $summary['current_competition'] : null;
        $club = is_array($summary['current_club'] ?? null) ? $summary['current_club'] : null;
        $seasonId = $this->seasonId($summary);
        if ($competition === null || $club === null || $seasonId === null) {
            return ['competition' => 'No current competition', 'standings' => [], 'recent_result' => null, 'next_fixture' => null];
        }
        $competitionRecord = (new CompetitionRepository($database))->get((string) $competition['id']);
        $matchService = $this->services->matchModule()->service();
        $clubs = $this->services->clubModule()->service()->repository($database);
        $standings = [];
        if ($competitionRecord->type() === CompetitionType::DomesticLeague) {
            foreach ($matchService->standings($database, $competitionRecord->id(), $seasonId) as $row) {
                $standings[] = $row + [
                    'club' => $clubs->get((string) $row['club_id'])->canonicalName(),
                    'controlled' => (string) $row['club_id'] === (string) $club['id'],
                ];
            }
        }
        $recent = null;
        foreach (array_reverse($matchService->repository($database)->byClub((string) $club['id'], $seasonId)) as $match) {
            if ($match->status()->value !== 'completed' || $match->scheduledDate()->isAfter($date)) { continue; }
            $recent = $this->fixtureText($database, $match, (string) $club['id']);
            break;
        }

        return [
            'competition' => $competitionRecord->name(),
            'standings' => $standings,
            'recent_result' => $recent,
            'next_fixture' => ($next = $this->nextMatch($database, $summary)) === null ? null : $this->fixtureTextFromView($next),
        ];
    }

    /** @return object|null */
    private function playerSelection(DatabaseInterface $database, GameMatch $match, string $playerId): ?object
    {
        foreach ((new MatchSelectionRepository($database))->byMatch($match->id()) as $selection) {
            if ($selection->playerId()->value() === $playerId) { return $selection; }
        }

        return null;
    }

    private function substitutionMinute(DatabaseInterface $database, GameMatch $match, string $playerId): ?int
    {
        foreach ((new MatchSubstitutionRepository($database))->byMatch($match->id()) as $substitution) {
            if ($substitution->incomingPlayerId()->value() === $playerId) { return $substitution->minute(); }
        }

        return null;
    }

    /** @return list<string> */
    private function highlightLines(DatabaseInterface $database, GameMatch $match, string $playerId): array
    {
        $clubs = $this->services->clubModule()->service()->repository($database);
        $players = new PlayerRepository($database);
        $lines = [];
        foreach ($this->services->matchModule()->service()->highlightRepository($database)->byMatch($match->id()) as $highlight) {
            $type = match ($highlight->type()) {
                'goal' => 'GOAL',
                'yellow_card' => 'YELLOW CARD',
                'red_card' => 'RED CARD',
                'substitution' => 'SUBSTITUTION',
                default => CareerLabels::value($highlight->type()),
            };
            $clubName = $highlight->clubId() === null ? null : $clubs->get($highlight->clubId())->canonicalName();
            $playerName = $highlight->playerId() === null ? null : $players->get($highlight->playerId())->preferredName();
            $controlled = $highlight->playerId()?->value() === $playerId;
            $data = $highlight->data();
            if ($highlight->type() === 'substitution') {
                $incomingId = (string) ($data['incoming_player_id'] ?? $highlight->playerId()?->value() ?? '');
                $outgoingId = (string) ($data['outgoing_player_id'] ?? '');
                $incoming = $incomingId === '' ? 'Player' : $players->get($incomingId)->preferredName();
                $outgoing = $outgoingId === '' ? 'Player' : $players->get($outgoingId)->preferredName();
                if ($incomingId === $playerId) { $lines[] = $highlight->minute() . "' YOU ENTER THE MATCH — " . $incoming . ' on for ' . $outgoing; }
                elseif ($outgoingId === $playerId) { $lines[] = $highlight->minute() . "' YOU LEAVE THE MATCH — " . $incoming . ' on for ' . $outgoing; }
                else { $lines[] = $highlight->minute() . "' SUBSTITUTION — " . $incoming . ' on for ' . $outgoing . ' (' . ($clubName ?? 'Club') . ')'; }
                continue;
            }
            $actor = $playerName === null ? ($clubName ?? 'Club') : (($controlled ? 'YOU — ' : '') . $playerName . ' (' . ($clubName ?? 'Club') . ')');
            $assistId = $data['assist_player_id'] ?? null;
            $assist = is_string($assistId) && $assistId !== '' ? $players->get($assistId)->preferredName() : null;
            $assistText = $assistId === $playerId ? 'YOU' : $assist;
            $suffix = $assistText === null ? '' : ' — assist: ' . $assistText;
            $lines[] = $highlight->minute() . "' " . $type . ' — ' . $actor . $suffix;
        }

        return $lines;
    }

    private function fixtureText(DatabaseInterface $database, GameMatch $match, string $controlledClubId): string
    {
        $clubs = $this->services->clubModule()->service()->repository($database);
        $competition = (new CompetitionRepository($database))->get($match->competitionId());
        $home = $clubs->get($match->homeClubId())->canonicalName();
        $away = $clubs->get($match->awayClubId())->canonicalName();
        $result = $match->result();
        $score = $result === null ? '' : ' ' . $result->homeGoals() . '-' . $result->awayGoals();

        return $match->scheduledDate()->toIsoString() . ': ' . $home . $score . ' ' . $away . ' (' . $competition->name() . ')';
    }

    /** @param array<string, mixed> $view */
    private function fixtureTextFromView(array $view): string
    {
        return (string) ($view['date'] ?? 'Date unavailable') . ': ' . (string) ($view['home_club'] ?? 'Home') . ' vs ' . (string) ($view['away_club'] ?? 'Away') . ' (' . (string) ($view['competition'] ?? 'Competition') . ')';
    }

    private function seasonId(array $summary): ?\Goal\Legacy\Modules\World\Domain\SeasonId
    {
        $current = $summary['current_season_id'] ?? null;
        if (is_string($current) && $current !== '') {
            return new \Goal\Legacy\Modules\World\Domain\SeasonId($current);
        }
        $history = is_array($summary['season_history'] ?? null) ? $summary['season_history'] : [];
        $current = $history === [] ? null : $history[array_key_last($history)];
        $id = is_array($current) ? ($current['season_id'] ?? null) : null;
        if (!is_string($id) || $id === '') {
            return null;
        }

        return new \Goal\Legacy\Modules\World\Domain\SeasonId($id);
    }
}
