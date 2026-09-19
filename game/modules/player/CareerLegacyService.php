<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Competition\DomesticCupService;
use Goal\Legacy\Modules\Competition\EuropeanCompetitionService;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
use Goal\Legacy\Modules\Competition\Persistence\CompetitionRepository;
use Goal\Legacy\Modules\International\InternationalCompetitionService;
use Goal\Legacy\Modules\International\NationalTeamService;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Match\PlayerMatchRatingService;
use Goal\Legacy\Modules\Match\StandingsService;
use Goal\Legacy\Modules\Player\Domain\CareerEvent;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Persistence\CareerEventRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerLegacyRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRetirementRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Persistence\PlayerCompetitionStatisticsRepository;

/**
 * Resolves a small, descriptive Career legacy from canonical football facts.
 * Awards use one compact competition aggregate per Player; no NPC Match detail
 * is retained or generated for this service.
 */
final class CareerLegacyService
{
    private const MIN_AWARD_APPEARANCES = 5;

    public function __construct(
        private readonly ClubService $clubs,
        private readonly NationalTeamService $nationalTeams,
        private readonly InternationalCompetitionService $international,
        private readonly ?FootballSocialService $social = null,
    ) {
    }

    /** @return array{awards:int,honours:int,records:int,milestones:int} */
    public function resolveCompletedSeason(DatabaseInterface $database, Season $season, ?SimulationDate $awardedDate = null): array
    {
        $awardedDate ??= $season->endDate();
        $legacy = new CareerLegacyRepository($database);
        new PlayerCompetitionStatisticsRepository($database);
        $events = new CareerEventRepository($database);
        $this->social?->initializeSchema($database);
        $controlled = (new CareerPlayerRepository($database))->playerIds();
        $controlledSet = array_fill_keys($controlled, true);
        $awards = $this->seasonAwards($database, $season);
        $honours = $this->seasonHonours($database, $season, $controlled);
        $changes = $this->controlledChanges($database, $season, $controlled);
        $result = ['awards' => 0, 'honours' => 0, 'records' => 0, 'milestones' => 0];

        $database->transaction(function () use ($database, $legacy, $events, $season, $awardedDate, $controlledSet, $awards, $honours, $changes, &$result): void {
            foreach ($awards as $award) {
                if ($legacy->saveAwardInTransaction($award)) {
                    ++$result['awards'];
                }
                $playerId = (string) $award['winner_player_id'];
                if (!isset($controlledSet[$playerId])) {
                    continue;
                }
                $this->achievementEventInTransaction($database, $events, $playerId, (string) $award['source_key'], $season->id(), $awardedDate, 'award', (string) $award['award_type'], $this->awardHeadline($award), $this->awardDescription($award), 'major', (string) $award['club_id']);
            }
            foreach ($honours as $honour) {
                if ($legacy->saveHonourInTransaction($honour)) {
                    ++$result['honours'];
                }
                $this->achievementEventInTransaction($database, $events, (string) $honour['player_id'], (string) $honour['source_key'], $season->id(), $awardedDate, 'honour', (string) $honour['honour_type'], (string) $honour['label'], 'Earned through registered participation and an appearance for the winning team.', 'landmark', (string) $honour['holder_id']);
            }
            foreach ($changes['records'] as $change) {
                $legacy->saveRecordInTransaction($change['row']);
                ++$result['records'];
                $this->achievementEventInTransaction($database, $events, (string) $change['row']['player_id'], 'record|' . (string) $change['row']['player_id'] . '|' . (string) $change['row']['metric'] . '|' . $season->id()->value() . '|' . (string) $change['row']['value'], $season->id(), $awardedDate, 'record', (string) $change['row']['metric'], (string) $change['label'], 'A new personal Career best was established from the completed Season aggregate.', 'major', ($change['row']['club_id'] ?? null) ?: null);
            }
            foreach ($changes['milestones'] as $milestone) {
                if (!$legacy->saveMilestoneInTransaction($milestone)) {
                    continue;
                }
                ++$result['milestones'];
                $this->achievementEventInTransaction($database, $events, (string) $milestone['player_id'], (string) $milestone['source_key'], $season->id(), $awardedDate, 'milestone', (string) $milestone['metric'], (string) $milestone['label'], 'A Career milestone was reached through canonical football statistics.', 'notable', null);
            }
        });

        return $result;
    }

    /** @return array<string, mixed> */
    public function summary(DatabaseInterface $database, string $playerId): array
    {
        $legacy = new CareerLegacyRepository($database, false);
        $players = new PlayerRepository($database);
        $player = $players->get($playerId);
        $stats = (new PlayerCareerStatisticsService())->careerDetailed($database, $playerId);
        $international = $this->internationalStatsReadOnly($database, $playerId);
        $clubs = [];
        $clubRows = $database->connection()->prepare('SELECT club_id FROM club_squad_memberships WHERE player_id = :player_id GROUP BY club_id ORDER BY MIN(season_id) ASC, club_id ASC');
        $clubRows->execute(['player_id' => $playerId]);
        foreach ($clubRows->fetchAll(\PDO::FETCH_COLUMN) as $clubId) {
            $club = $this->clubs->repository($database)->get(new \Goal\Legacy\Modules\Club\Domain\ClubId((string) $clubId));
            $clubs[] = ['id' => (string) $clubId, 'name' => $club->canonicalName()];
        }
        $seasonRows = $database->connection()->prepare('SELECT season_id FROM club_squad_memberships WHERE player_id = :player_id ORDER BY season_id ASC');
        $seasonRows->execute(['player_id' => $playerId]);
        $seasons = array_values(array_unique(array_map('strval', $seasonRows->fetchAll(\PDO::FETCH_COLUMN))));
        $span = ['start' => null, 'latest' => null];
        $retirement = (new PlayerRetirementRepository($database, false))->get($playerId);
        if ($seasons !== []) {
            $seasonRepository = new \Goal\Legacy\Modules\World\Persistence\SeasonRepository($database);
            $span['start'] = $seasonRepository->get($seasons[0])->label();
            $span['latest'] = $seasonRepository->get($seasons[array_key_last($seasons)])->label();
            $span['seasons_played'] = count($seasons);
            if ($retirement !== null && $retirement['retirement_season_id'] !== '' && $seasonRepository->exists((string) $retirement['retirement_season_id'])) {
                $span['retirement'] = $seasonRepository->get((string) $retirement['retirement_season_id'])->label();
            }
        } else {
            $span['seasons_played'] = 0;
        }
        $awards = $legacy->awardsForPlayer($playerId);
        foreach ($awards as &$award) {
            $award['winner_name'] = $player->preferredName();
        }
        unset($award);

        return [
            'career_span' => $span,
            'retirement' => $retirement,
            'clubs' => $clubs,
            'club_stats' => $stats,
            'international_stats' => $international,
            'honours' => $legacy->honoursForPlayer($playerId),
            'awards' => $awards,
            'records' => $legacy->recordsForPlayer($playerId),
            'milestones' => $legacy->milestonesForPlayer($playerId),
            'legacy_score' => null,
        ];
    }

    /** @return list<string> */
    public function integrity(DatabaseInterface $database): array
    {
        $legacy = new CareerLegacyRepository($database, false);
        if (!$legacy->available()) {
            return [];
        }
        $errors = [];
        foreach ([
            ['career_awards', 'winner_player_id'],
            ['career_honours', 'player_id'],
            ['career_legacy_records', 'player_id'],
            ['career_legacy_milestones', 'player_id'],
        ] as [$table, $column]) {
            $count = (int) $database->connection()->query('SELECT COUNT(*) FROM ' . $table . ' rows LEFT JOIN player_records players ON players.id = rows.' . $column . ' WHERE players.id IS NULL')->fetchColumn();
            if ($count > 0) { $errors[] = $table . ': invalid Player references=' . $count; }
        }
        $duplicateMilestones = (int) $database->connection()->query('SELECT COUNT(*) FROM (SELECT player_id, metric, threshold, COUNT(*) c FROM career_legacy_milestones GROUP BY player_id, metric, threshold HAVING c > 1)')->fetchColumn();
        if ($duplicateMilestones > 0) { $errors[] = 'career_legacy_milestones: duplicate threshold rows=' . $duplicateMilestones; }
        $duplicateAwards = (int) $database->connection()->query('SELECT COUNT(*) FROM (SELECT season_id, competition_id, award_type, COUNT(*) c FROM career_awards GROUP BY season_id, competition_id, award_type HAVING c > 1)')->fetchColumn();
        if ($duplicateAwards > 0) { $errors[] = 'career_awards: duplicate winner rows=' . $duplicateAwards; }
        if ($this->tableExists($database, 'competition_records')) {
            $invalidCompetitions = (int) $database->connection()->query('SELECT COUNT(*) FROM career_honours honours LEFT JOIN competition_records competitions ON competitions.id = honours.competition_id AND competitions.season_id = honours.season_id WHERE competitions.id IS NULL')->fetchColumn();
            if ($invalidCompetitions > 0) { $errors[] = 'career_honours: invalid competition history=' . $invalidCompetitions; }
        }
        $honours = $database->connection()->query('SELECT honour_type, evidence_json FROM career_honours')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($honours as $honour) {
            $evidence = json_decode((string) ($honour['evidence_json'] ?? ''), true);
            $participation = $honour['honour_type'] === 'world_championship_winner' ? ($evidence['caps'] ?? 0) : ($evidence['appearances'] ?? 0);
            if ((int) $participation < 1) { $errors[] = 'career_honours: missing participation evidence'; break; }
        }
        foreach ($database->connection()->query('SELECT player_id, metric, value, evidence_json FROM career_legacy_records')->fetchAll(\PDO::FETCH_ASSOC) as $record) {
            $metric = (string) $record['metric'];
            $canonical = match ($metric) {
                'club_appearances' => (new PlayerCareerStatisticsService())->career($database, (string) $record['player_id'])['appearances'],
                'club_goals' => (new PlayerCareerStatisticsService())->career($database, (string) $record['player_id'])['goals'],
                'club_assists' => (new PlayerCareerStatisticsService())->careerDetailed($database, (string) $record['player_id'])['assists'],
                'international_caps' => $this->internationalStatsReadOnly($database, (string) $record['player_id'])['caps'],
                'international_goals' => $this->internationalStatsReadOnly($database, (string) $record['player_id'])['goals'],
                default => null,
            };
            if ($canonical !== null && abs((float) $record['value'] - (float) $canonical) > 0.01) {
                $errors[] = 'career_legacy_records: value inconsistent with canonical aggregate=' . $metric;
            }
        }

        return $errors;
    }

    /** @return list<array<string, mixed>> */
    private function seasonAwards(DatabaseInterface $database, Season $season): array
    {
        $awards = [];
        $competitions = new CompetitionRepository($database);
        foreach (array_filter($competitions->bySeason($season->id()), static fn ($competition): bool => $competition->type() === CompetitionType::DomesticLeague) as $competition) {
            $candidates = array_values(array_filter($this->competitionAggregates($database, $season->id(), $competition->id()->value()), static fn (array $row): bool => (int) $row['appearances'] >= self::MIN_AWARD_APPEARANCES && (int) $row['rated_appearances'] > 0));
            if ($candidates === []) { continue; }
            $playerOfSeason = $this->bestRated($candidates);
            $awards[] = $this->awardRow($season, $competition->id()->value(), $competition->name(), 'PLAYER_OF_THE_SEASON', $playerOfSeason);
            $young = array_values(array_filter($candidates, function (array $row) use ($database, $season): bool {
                $player = (new PlayerRepository($database))->get((string) $row['player_id']);
                return $player->ageAt($season->endDate()) <= 21;
            }));
            if ($young !== []) {
                $awards[] = $this->awardRow($season, $competition->id()->value(), $competition->name(), 'YOUNG_PLAYER_OF_THE_SEASON', $this->bestRated($young));
            }
            $scorer = $this->bestBy($candidates, 'goals', ['assists', 'appearances', 'minutes']);
            if ((int) $scorer['goals'] > 0) {
                $awards[] = $this->awardRow($season, $competition->id()->value(), $competition->name(), 'TOP_SCORER', $scorer);
            }
            $creator = $this->bestBy($candidates, 'assists', ['goals', 'appearances', 'minutes']);
            if ((int) $creator['assists'] > 0) {
                $awards[] = $this->awardRow($season, $competition->id()->value(), $competition->name(), 'TOP_ASSIST_PROVIDER', $creator);
            }
        }

        return $awards;
    }

    /** @param list<string> $controlled @return list<array<string, mixed>> */
    private function seasonHonours(DatabaseInterface $database, Season $season, array $controlled): array
    {
        $honours = [];
        $controlledSet = array_fill_keys($controlled, true);
        $competitions = new CompetitionRepository($database);
        $standings = new StandingsService($this->clubs);
        $domesticCups = new DomesticCupService($this->clubs);
        $europe = new EuropeanCompetitionService($this->clubs, $domesticCups);
        foreach ($competitions->bySeason($season->id()) as $competition) {
            $winner = null;
            $type = $competition->type();
            if ($type === CompetitionType::DomesticLeague) {
                $table = $standings->table($database, new CompetitionId($competition->id()->value()), $season->id());
                $winner = isset($table[0]['club_id'], $table[0]['played']) && (int) $table[0]['played'] > 0
                    ? (string) $table[0]['club_id']
                    : null;
            } elseif ($type === CompetitionType::DomesticCup) {
                $winner = $domesticCups->winnerClubId($database, $competition->id()->value(), $season->id());
            } elseif ($type === CompetitionType::Continental) {
                $winner = $europe->winner($database, $competition->id()->value(), $season->id());
            }
            if ($winner === null) { continue; }
            $rows = $this->competitionAggregates($database, $season->id(), $competition->id()->value());
            foreach ($rows as $row) {
                $playerId = (string) $row['player_id'];
                if (!isset($controlledSet[$playerId]) || (string) $row['club_id'] !== $winner || (int) $row['appearances'] < 1) { continue; }
                $honourType = match ($type) {
                    CompetitionType::DomesticLeague => 'league_champion',
                    CompetitionType::DomesticCup => 'domestic_cup_winner',
                    CompetitionType::Continental => $competition->tier() === 1 ? 'europe_tier_1_winner' : 'europe_tier_2_winner',
                    default => null,
                };
                if ($honourType === null) { continue; }
                $honours[] = $this->honourRow($season, $competition->id()->value(), $honourType, $winner, $this->honourLabel($honourType, $competition->name()), $playerId, ['appearances' => $row['appearances'], 'club_id' => $winner]);
            }
        }
        $history = $this->international->history($database);
        $players = new PlayerRepository($database);
        foreach ($history as $row) {
            if ((string) ($row['season_id'] ?? '') !== $season->id()->value()) { continue; }
            if ((string) ($row['competition_id'] ?? '') !== InternationalCompetitionService::WORLD_CHAMPIONSHIP) { continue; }
            $winner = (string) ($row['winner_team_id'] ?? '');
            if ($winner === '') { continue; }
            foreach ($controlled as $playerId) {
                $player = $players->get($playerId);
                if ($this->nationalTeams->teamId($player->primaryNationId()->value()) !== $winner) { continue; }
                $stats = $this->nationalTeams->playerStats($database, $playerId, $season->id(), (string) ($row['competition_id'] ?? ''));
                if ((int) ($stats['caps'] ?? 0) < 1) { continue; }
                $honours[] = $this->honourRow($season, (string) $row['competition_id'], 'world_championship_winner', $winner, 'World Championship Winner', $playerId, ['caps' => $stats['caps'], 'goals' => $stats['goals']]);
            }
        }

        return $honours;
    }

    /** @param list<string> $controlled @return array{records:list<array{row:array<string,mixed>,label:string}>,milestones:list<array<string,mixed>>} */
    private function controlledChanges(DatabaseInterface $database, Season $season, array $controlled): array
    {
        $legacy = new CareerLegacyRepository($database, false);
        $records = [];
        $milestones = [];
        foreach ($controlled as $playerId) {
            $club = (new PlayerCareerStatisticsService())->careerDetailed($database, $playerId);
            $seasonStats = (new PlayerCareerStatisticsService())->seasonDetailed($database, $playerId, $season->id());
            $international = $this->nationalTeams->playerStats($database, $playerId);
            $clubId = $this->seasonClub($database, $playerId, $season->id());
            $candidates = [
                'club_appearances' => [(float) $club['appearances'], 'Career Club appearances', null],
                'club_goals' => [(float) $club['goals'], 'Career Club goals', null],
                'club_assists' => [(float) $club['assists'], 'Career Club assists', null],
                'international_caps' => [(float) ($international['caps'] ?? 0), 'International caps', null],
                'international_goals' => [(float) ($international['goals'] ?? 0), 'International goals', null],
                'best_season_goals' => [(float) $seasonStats['goals'], 'Best scoring Season', $clubId],
                'best_season_assists' => [(float) $seasonStats['assists'], 'Best assist Season', $clubId],
                'most_season_appearances' => [(float) $seasonStats['appearances'], 'Most appearances in a Season', $clubId],
            ];
            if ((int) $seasonStats['appearances'] >= self::MIN_AWARD_APPEARANCES && $seasonStats['average_match_rating'] !== null) {
                $candidates['best_season_rating'] = [(float) $seasonStats['average_match_rating'], 'Best average-rated Season', $clubId];
            }
            foreach ($candidates as $metric => [$value, $label, $metricClub]) {
                if ($value <= 0) { continue; }
                $previous = $legacy->record($playerId, $metric);
                if ($previous !== null && (float) $previous['value'] >= $value) { continue; }
                $row = ['player_id' => $playerId, 'metric' => $metric, 'value' => $value, 'season_id' => $season->id()->value(), 'club_id' => $metricClub, 'updated_date' => $season->endDate()->toIsoString(), 'evidence' => ['season_id' => $season->id()->value(), 'value' => $value]];
                $records[] = ['row' => $row, 'label' => $label];
            }
            foreach ([
                'club_appearances' => [50, 100, 200],
                'club_goals' => [25, 50, 100],
                'club_assists' => [25, 50],
                'international_caps' => [10, 25, 50],
                'international_goals' => [1, 10, 25, 50],
            ] as $metric => $thresholds) {
                $value = (float) ($candidates[$metric][0] ?? 0);
                foreach ($thresholds as $threshold) {
                    if ($value < $threshold) { continue; }
                    $label = $this->milestoneLabel($metric, $threshold);
                    $milestones[] = ['source_key' => 'milestone|' . $playerId . '|' . $metric . '|' . $threshold, 'player_id' => $playerId, 'season_id' => $season->id()->value(), 'metric' => $metric, 'threshold' => $threshold, 'label' => $label, 'occurred_date' => $season->endDate()->toIsoString(), 'evidence' => ['value' => $value]];
                }
            }
        }

        return ['records' => $records, 'milestones' => $milestones];
    }

    /** @return array<string, int|float|null> */
    private function internationalStatsReadOnly(DatabaseInterface $database, string $playerId): array
    {
        $exists = $database->connection()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'international_player_statistics'");
        $exists->execute();
        if ($exists->fetchColumn() === false) {
            return ['caps' => 0, 'starts' => 0, 'minutes' => 0, 'goals' => 0, 'assists' => 0, 'yellow_cards' => 0, 'red_cards' => 0, 'rated_appearances' => 0, 'average_rating' => null];
        }
        $statement = $database->connection()->prepare('SELECT COALESCE(SUM(caps), 0) caps, COALESCE(SUM(starts), 0) starts, COALESCE(SUM(minutes), 0) minutes, COALESCE(SUM(goals), 0) goals, COALESCE(SUM(assists), 0) assists, COALESCE(SUM(yellow_cards), 0) yellow_cards, COALESCE(SUM(red_cards), 0) red_cards, COALESCE(SUM(rated_appearances), 0) rated_appearances, COALESCE(SUM(rating_total), 0) rating_total FROM international_player_statistics WHERE player_id = :player_id');
        $statement->execute(['player_id' => $playerId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC) ?: [];
        $rated = (int) ($row['rated_appearances'] ?? 0);

        return ['caps' => (int) ($row['caps'] ?? 0), 'starts' => (int) ($row['starts'] ?? 0), 'minutes' => (int) ($row['minutes'] ?? 0), 'goals' => (int) ($row['goals'] ?? 0), 'assists' => (int) ($row['assists'] ?? 0), 'yellow_cards' => (int) ($row['yellow_cards'] ?? 0), 'red_cards' => (int) ($row['red_cards'] ?? 0), 'rated_appearances' => $rated, 'average_rating' => $rated === 0 ? null : round((float) ($row['rating_total'] ?? 0) / $rated, 2)];
    }

    private function tableExists(DatabaseInterface $database, string $table): bool
    {
        $statement = $database->connection()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table");
        $statement->execute(['table' => $table]);

        return $statement->fetchColumn() !== false;
    }

    /** @return list<array<string, mixed>> */
    private function competitionAggregates(DatabaseInterface $database, SeasonId $seasonId, string $competitionId): array
    {
        $result = [];
        $detailKeys = [];
        $matches = new MatchRepository($database);
        $players = new PlayerRepository($database);
        $ratings = new PlayerMatchRatingService();
        foreach ((new PlayerMatchStatRepository($database))->completedSeasonRatingEvidence($seasonId) as $evidence) {
            $stat = $evidence['stat'];
            $match = $matches->get($stat->matchId());
            if ($match->competitionId()->value() !== $competitionId) { continue; }
            $key = $stat->playerId()->value() . '|' . $stat->clubId()->value();
            $result[$key] ??= $this->emptyAggregate($stat->playerId()->value(), $stat->clubId()->value(), $players->get($stat->playerId())->primaryPosition()->value);
            $this->addStat($result[$key], $stat);
            $rating = $ratings->rate($stat, $players->get($stat->playerId())->primaryPosition());
            if ($rating !== null) { ++$result[$key]['rated_appearances']; $result[$key]['rating_total'] += $rating; }
            $detailKeys[$key] = true;
        }
        foreach ((new PlayerCompetitionStatisticsRepository($database, false))->byCompetitionSeason($competitionId, $seasonId) as $row) {
            $key = (string) $row['player_id'] . '|' . (string) $row['club_id'];
            if (isset($detailKeys[$key])) { continue; }
            $row['position'] = $players->get((string) $row['player_id'])->primaryPosition()->value;
            $result[$key] = $row;
        }
        foreach ($result as &$row) {
            $row['average_match_rating'] = (int) $row['rated_appearances'] > 0 ? round((float) $row['rating_total'] / (int) $row['rated_appearances'], 2) : null;
        }
        unset($row);

        return array_values($result);
    }

    /** @return array<string, int|float|string|null> */
    private function emptyAggregate(string $playerId, string $clubId, string $position): array
    {
        return ['player_id' => $playerId, 'club_id' => $clubId, 'position' => $position, 'appearances' => 0, 'starts' => 0, 'minutes' => 0, 'goals' => 0, 'assists' => 0, 'shots' => 0, 'shots_on_target' => 0, 'saves' => 0, 'clean_sheets' => 0, 'tackles' => 0, 'interceptions' => 0, 'blocks' => 0, 'passes_attempted' => 0, 'passes_completed' => 0, 'fouls_committed' => 0, 'yellow_cards' => 0, 'red_cards' => 0, 'rated_appearances' => 0, 'rating_total' => 0.0, 'average_match_rating' => null];
    }

    /** @param array<string, int|float|string|null> $aggregate */
    private function addStat(array &$aggregate, \Goal\Legacy\Modules\Match\Domain\PlayerMatchStat $stat): void
    {
        ++$aggregate['appearances'];
        $aggregate['starts'] += $stat->started() ? 1 : 0;
        $aggregate['minutes'] += $stat->minutes();
        foreach (['goals' => 'goals', 'assists' => 'assists', 'shots' => 'shots', 'shots_on_target' => 'shotsOnTarget', 'saves' => 'saves', 'clean_sheets' => 'cleanSheets', 'tackles' => 'tackles', 'interceptions' => 'interceptions', 'blocks' => 'blocks', 'passes_attempted' => 'passesAttempted', 'passes_completed' => 'passesCompleted', 'fouls_committed' => 'foulsCommitted', 'yellow_cards' => 'yellowCards', 'red_cards' => 'redCards'] as $key => $method) { $aggregate[$key] += $stat->{$method}(); }
    }

    /** @param list<array<string, int|float|string|null>> $rows @return array<string, int|float|string|null> */
    private function bestRated(array $rows): array
    {
        usort($rows, static fn (array $left, array $right): int => ((float) ($right['average_match_rating'] ?? 0) <=> (float) ($left['average_match_rating'] ?? 0)) ?: ((int) $right['rated_appearances'] <=> (int) $left['rated_appearances']) ?: ((int) $right['appearances'] <=> (int) $left['appearances']) ?: ((int) $right['minutes'] <=> (int) $left['minutes']) ?: ((int) $right['goals'] <=> (int) $left['goals']) ?: ((int) $right['assists'] <=> (int) $left['assists']) ?: strcmp((string) $left['player_id'], (string) $right['player_id']));

        return $rows[0];
    }

    /** @param list<array<string, int|float|string|null>> $rows @param list<string> $secondary @return array<string, int|float|string|null> */
    private function bestBy(array $rows, string $primary, array $secondary): array
    {
        usort($rows, static function (array $left, array $right) use ($primary, $secondary): int {
            $comparison = (int) ($right[$primary] ?? 0) <=> (int) ($left[$primary] ?? 0);
            foreach ($secondary as $field) { if ($comparison !== 0) { break; } $comparison = (int) ($right[$field] ?? 0) <=> (int) ($left[$field] ?? 0); }
            return $comparison !== 0 ? $comparison : strcmp((string) $left['player_id'], (string) $right['player_id']);
        });

        return $rows[0];
    }

    /** @param array<string, int|float|string|null> $row @return array<string, mixed> */
    private function awardRow(Season $season, string $competitionId, string $competitionName, string $awardType, array $row): array
    {
        return ['source_key' => 'award|' . $season->id()->value() . '|' . $competitionId . '|' . $awardType . '|' . $row['player_id'], 'season_id' => $season->id()->value(), 'competition_id' => $competitionId, 'scope' => 'domestic_league', 'award_type' => $awardType, 'winner_player_id' => $row['player_id'], 'club_id' => $row['club_id'], 'award_date' => $season->endDate()->toIsoString(), 'evidence' => ['competition_name' => $competitionName, 'appearances' => $row['appearances'], 'minutes' => $row['minutes'], 'goals' => $row['goals'], 'assists' => $row['assists'], 'average_match_rating' => $row['average_match_rating'], 'position' => $row['position']]];
    }

    /** @param array<string, mixed> $evidence @return array<string, mixed> */
    private function honourRow(Season $season, string $competitionId, string $honourType, string $holderId, string $label, string $playerId, array $evidence): array
    {
        return ['source_key' => 'honour|' . $playerId . '|' . $season->id()->value() . '|' . $competitionId . '|' . $honourType, 'player_id' => $playerId, 'season_id' => $season->id()->value(), 'competition_id' => $competitionId, 'honour_type' => $honourType, 'holder_id' => $holderId, 'label' => $label, 'earned_date' => $season->endDate()->toIsoString(), 'evidence' => $evidence];
    }

    /** @param array<string, mixed> $award */
    private function awardHeadline(array $award): string { return $this->awardLabel((string) $award['award_type']) . ' — ' . (string) (($award['evidence']['competition_name'] ?? 'League') . ' award'); }
    /** @param array<string, mixed> $award */
    private function awardDescription(array $award): string { return 'Won the ' . strtolower($this->awardLabel((string) $award['award_type'])) . ' after a completed Season of canonical League evidence.'; }
    private function awardLabel(string $type): string { return match ($type) { 'PLAYER_OF_THE_SEASON' => 'Player of the Season', 'YOUNG_PLAYER_OF_THE_SEASON' => 'Young Player of the Season', 'TOP_SCORER' => 'Top Scorer', 'TOP_ASSIST_PROVIDER' => 'Top Assist Provider', default => ucwords(strtolower(str_replace('_', ' ', $type))), }; }
    private function honourLabel(string $type, string $competition): string { return match ($type) { 'league_champion' => 'League Champion — ' . $competition, 'domestic_cup_winner' => 'Domestic Cup Winner — ' . $competition, 'europe_tier_1_winner' => 'Europe Tier 1 Winner — ' . $competition, 'europe_tier_2_winner' => 'Europe Tier 2 Winner — ' . $competition, default => $type, }; }
    private function milestoneLabel(string $metric, int $threshold): string { $unit = match ($metric) { 'club_appearances' => 'Club appearances', 'club_goals' => 'Club goals', 'club_assists' => 'Club assists', 'international_caps' => 'international caps', 'international_goals' => 'international goals', default => $metric, }; return ($threshold === 1 ? 'First ' : $threshold . ' ') . $unit; }

    private function seasonClub(DatabaseInterface $database, string $playerId, SeasonId $seasonId): ?string
    {
        $statement = $database->connection()->prepare('SELECT club_id FROM club_squad_memberships WHERE player_id = :player_id AND season_id = :season_id ORDER BY club_id ASC LIMIT 1');
        $statement->execute(['player_id' => $playerId, 'season_id' => $seasonId->value()]);
        $club = $statement->fetchColumn();

        return $club === false ? null : (string) $club;
    }

    private function achievementEventInTransaction(DatabaseInterface $database, CareerEventRepository $events, string $playerId, string $source, SeasonId $seasonId, SimulationDate $date, string $category, string $definition, string $title, string $description, string $importance, ?string $clubId): void
    {
        $eventSource = 'legacy|' . $source;
        $event = CareerEvent::pending(hash('sha256', $eventSource), new PlayerId($playerId), $seasonId, $date, $eventSource, $category, $definition, $title, $description, [], ['historyworthy' => true, 'newsworthy' => true, 'importance' => $importance, 'legacy_type' => $category, 'club_id' => $clubId])->resolved('record', ['history' => $description, 'legacy_type' => $category]);
        $events->saveInTransaction($event);
        $this->social?->recordAchievementInTransaction($database, $playerId, $date, $eventSource, $title, $importance, $clubId);
    }
}
