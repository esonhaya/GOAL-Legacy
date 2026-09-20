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
use Goal\Legacy\Modules\Transfer\Persistence\TransferRepository;
use Goal\Legacy\Modules\Transfer\Domain\TransferStatus;
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
                $importance = $this->milestoneImportance((string) $milestone['metric'], (int) $milestone['threshold']);
                if (in_array($importance, ['major', 'landmark'], true)) {
                    $this->achievementEventInTransaction($database, $events, (string) $milestone['player_id'], (string) $milestone['source_key'], $season->id(), $awardedDate, 'milestone', (string) $milestone['metric'], (string) $milestone['label'], 'A Career milestone was reached through canonical football statistics.', $importance, null);
                }
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
        $traits = (new PlayerTraitService())->derive($database, $player);
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
        $honours = $legacy->honoursForPlayer($playerId);
        $records = $legacy->recordsForPlayer($playerId);
        $milestones = $legacy->milestonesForPlayer($playerId);
        $memory = $this->historicalMemory($database, $playerId, $stats, $international, $honours, $awards, $records, $milestones);

        return [
            'career_span' => $span,
            'retirement' => $retirement,
            'clubs' => $clubs,
            'club_stats' => $stats,
            'international_stats' => $international,
            'honours' => $honours,
            'awards' => $awards,
            'records' => $records,
            'milestones' => $milestones,
            'career_landmarks' => $memory['career_landmarks'],
            'career_timeline' => $memory['career_timeline'],
            'personal_bests' => $memory['personal_bests'],
            'defining_seasons' => $memory['defining_seasons'],
            'next_milestone' => $this->nextMilestone($stats, $international),
            'traits' => $traits,
            'legacy_score' => null,
        ];
    }

    /**
     * A compact, read-only view used by Career Home. It deliberately returns
     * nothing until a milestone is close enough to be useful context.
     * @param array<string, mixed> $clubStats @param array<string, mixed> $internationalStats
     * @return array<string, mixed>|null
     */
    public function nextMilestone(array $clubStats, array $internationalStats = []): ?array
    {
        $candidates = [];
        foreach ([
            ['metric' => 'club_appearances', 'label' => 'Club appearances', 'current' => (int) ($clubStats['appearances'] ?? 0), 'thresholds' => [50, 100, 200, 300, 500], 'near' => 5],
            ['metric' => 'club_goals', 'label' => 'Club goals', 'current' => (int) ($clubStats['goals'] ?? 0), 'thresholds' => [25, 50, 100, 200, 300], 'near' => 3],
            ['metric' => 'club_assists', 'label' => 'Club assists', 'current' => (int) ($clubStats['assists'] ?? 0), 'thresholds' => [25, 50, 100, 200], 'near' => 3],
            ['metric' => 'international_caps', 'label' => 'international caps', 'current' => (int) ($internationalStats['caps'] ?? 0), 'thresholds' => [10, 25, 50, 100], 'near' => 2],
        ] as $candidate) {
            foreach ($candidate['thresholds'] as $threshold) {
                $remaining = $threshold - $candidate['current'];
                if ($remaining > 0 && $remaining <= $candidate['near']) {
                    $candidates[] = $candidate + ['threshold' => $threshold, 'remaining' => $remaining];
                    break;
                }
            }
        }
        usort($candidates, static fn (array $left, array $right): int => ((int) $left['remaining'] <=> (int) $right['remaining']) ?: ((int) $left['threshold'] <=> (int) $right['threshold']) ?: strcmp((string) $left['metric'], (string) $right['metric']));

        return $candidates[0] ?? null;
    }

    /**
     * Resolve landmark callouts after a completed Match without persisting or
     * replaying history. Matchday uses this only for the controlled Player.
     * @return list<array<string, mixed>>
     */
    public function matchLandmarks(DatabaseInterface $database, \Goal\Legacy\Modules\Match\Domain\GameMatch $match, string $playerId): array
    {
        $stat = null;
        foreach ((new PlayerMatchStatRepository($database))->byMatch($match->id()) as $candidate) {
            if ($candidate->playerId()->value() === $playerId && $candidate->appeared()) { $stat = $candidate; break; }
        }
        if ($stat === null) { return []; }
        $competition = (new CompetitionRepository($database))->get($match->competitionId());
        $international = $competition->type() === CompetitionType::International;
        $after = $international ? $this->internationalStatsReadOnly($database, $playerId) : (new PlayerCareerStatisticsService())->careerDetailed($database, $playerId);
        $before = $after;
        foreach (['appearances', 'caps', 'goals', 'assists', 'starts'] as $field) {
            if (array_key_exists($field, $before)) { $before[$field] = max(0, (int) $before[$field] - ($field === 'caps' || $field === 'appearances' || $field === 'starts' ? 1 : ($field === 'goals' ? $stat->goals() : $stat->assists()))); }
        }
        $facts = [];
        $date = $match->scheduledDate()->toIsoString();
        if ($international) {
            if ((int) ($before['caps'] ?? 0) === 0) { $facts[] = $this->liveLandmark('first-international-cap|' . $playerId . '|' . $match->id()->value(), 'International debut', 'The first senior international appearance.', $date, 'major'); }
            if ($stat->goals() > 0 && (int) ($before['goals'] ?? 0) === 0) { $facts[] = $this->liveLandmark('first-international-goal|' . $playerId . '|' . $match->id()->value(), 'First international goal', 'The first senior international goal.', $date, 'major'); }
            $facts = array_merge($facts, $this->thresholdCallouts('international_caps', (int) ($before['caps'] ?? 0), (int) ($after['caps'] ?? 0), $date, $playerId, $match->id()->value()));
            $facts = array_merge($facts, $this->thresholdCallouts('international_goals', (int) ($before['goals'] ?? 0), (int) ($after['goals'] ?? 0), $date, $playerId, $match->id()->value()));
        } else {
            if ((int) ($before['appearances'] ?? 0) === 0) { $facts[] = $this->liveLandmark('first-appearance|' . $playerId . '|' . $match->id()->value(), 'Senior debut', 'The first senior Club appearance.', $date, 'major'); }
            if ($stat->started() && (int) ($before['starts'] ?? 0) === 0) { $facts[] = $this->liveLandmark('first-start|' . $playerId . '|' . $match->id()->value(), 'First senior start', 'The first senior starting appearance.', $date, 'notable'); }
            if ($stat->goals() > 0 && (int) ($before['goals'] ?? 0) === 0) { $facts[] = $this->liveLandmark('first-goal|' . $playerId . '|' . $match->id()->value(), 'First senior goal', 'The first senior Club goal.', $date, 'major'); }
            if ($stat->assists() > 0 && (int) ($before['assists'] ?? 0) === 0) { $facts[] = $this->liveLandmark('first-assist|' . $playerId . '|' . $match->id()->value(), 'First senior assist', 'The first senior Club assist.', $date, 'notable'); }
            $facts = array_merge($facts, $this->thresholdCallouts('club_appearances', (int) ($before['appearances'] ?? 0), (int) ($after['appearances'] ?? 0), $date, $playerId, $match->id()->value()));
            $facts = array_merge($facts, $this->thresholdCallouts('club_goals', (int) ($before['goals'] ?? 0), (int) ($after['goals'] ?? 0), $date, $playerId, $match->id()->value()));
            $facts = array_merge($facts, $this->thresholdCallouts('club_assists', (int) ($before['assists'] ?? 0), (int) ($after['assists'] ?? 0), $date, $playerId, $match->id()->value()));
        }

        return array_slice($facts, 0, 3);
    }

    /** @param array<string, mixed> $stats @param array<string, mixed> $international @param list<array<string, mixed>> $honours @param list<array<string, mixed>> $awards @param list<array<string, mixed>> $records @param list<array<string, mixed>> $milestones @return array<string, mixed> */
    private function historicalMemory(DatabaseInterface $database, string $playerId, array $stats, array $international, array $honours, array $awards, array $records, array $milestones): array
    {
        $timeline = $this->firstMatchLandmarks($database, $playerId);
        foreach ((new CareerEventRepository($database))->resolvedForPlayer(new PlayerId($playerId), 250) as $event) {
            $milestone = match ($event->definition()) {
                'first_call_up' => ['source_key' => $event->sourceKey(), 'date' => $event->date()->toIsoString(), 'title' => 'First senior international call-up', 'description' => $event->description(), 'kind' => 'international', 'importance' => 'major', 'season_id' => $event->seasonId()->value()],
                'first_cap' => ['source_key' => $event->sourceKey(), 'date' => $event->date()->toIsoString(), 'title' => 'International debut', 'description' => $event->description(), 'kind' => 'international', 'importance' => 'major', 'season_id' => $event->seasonId()->value()],
                'first_international_goal' => ['source_key' => $event->sourceKey(), 'date' => $event->date()->toIsoString(), 'title' => 'First international goal', 'description' => $event->description(), 'kind' => 'international', 'importance' => 'major', 'season_id' => $event->seasonId()->value()],
                default => null,
            };
            if ($milestone !== null) { $timeline[] = $milestone; }
        }
        foreach ($milestones as $milestone) {
            $timeline[] = [
                'source_key' => (string) ($milestone['source_key'] ?? ''),
                'date' => (string) ($milestone['occurred_date'] ?? ''),
                'title' => (string) ($milestone['label'] ?? 'Career milestone'),
                'description' => 'Reached through canonical football statistics.',
                'kind' => 'milestone',
                'importance' => $this->milestoneImportance((string) ($milestone['metric'] ?? ''), (int) ($milestone['threshold'] ?? 0)),
                'season_id' => (string) ($milestone['season_id'] ?? ''),
                'metric' => (string) ($milestone['metric'] ?? ''),
                'threshold' => (int) ($milestone['threshold'] ?? 0),
                'evidence' => $milestone['evidence'] ?? [],
            ];
        }
        foreach ($honours as $honour) {
            $timeline[] = ['source_key' => (string) ($honour['source_key'] ?? ''), 'date' => (string) ($honour['earned_date'] ?? ''), 'title' => (string) ($honour['label'] ?? 'Honour'), 'description' => 'Honour recorded from the canonical competition result and Player participation.', 'kind' => 'honour', 'importance' => 'landmark', 'season_id' => (string) ($honour['season_id'] ?? '')];
        }
        foreach ($awards as $award) {
            $label = $this->awardLabel((string) ($award['award_type'] ?? 'award'));
            $competition = (string) (($award['evidence']['competition_name'] ?? '') ?: 'the recorded competition');
            $timeline[] = ['source_key' => (string) ($award['source_key'] ?? ''), 'date' => (string) ($award['award_date'] ?? ''), 'title' => $label . ' — ' . $competition, 'description' => 'Individual award recorded from the canonical completed-Season aggregate.', 'kind' => 'award', 'importance' => 'major', 'season_id' => (string) ($award['season_id'] ?? '')];
        }
        foreach ((new TransferRepository($database))->byPlayer($playerId) as $transfer) {
            if ($transfer->status() !== TransferStatus::Completed) { continue; }
            $from = $this->clubs->repository($database)->get($transfer->sourceClubId())->canonicalName();
            $to = $this->clubs->repository($database)->get($transfer->destinationClubId())->canonicalName();
            $seenDestination = false;
            foreach ($timeline as $item) {
                if (($item['kind'] ?? null) === 'transfer' && ($item['destination_club_id'] ?? null) === $transfer->destinationClubId()->value()) { $seenDestination = true; break; }
            }
            $timeline[] = ['source_key' => 'transfer|' . $transfer->id()->value(), 'date' => $transfer->effectiveDate()->toIsoString(), 'title' => $seenDestination ? 'Return to ' . $to : ($this->hasCompletedTransfer($database, $playerId, $transfer->id()->value()) ? 'Transfer to ' . $to : 'First transfer to ' . $to), 'description' => 'Completed Club movement recorded by the Career movement system.', 'kind' => 'transfer', 'importance' => $seenDestination ? 'major' : 'notable', 'season_id' => $transfer->seasonId()->value(), 'from_club_id' => $transfer->sourceClubId()->value(), 'destination_club_id' => $transfer->destinationClubId()->value(), 'clubs' => $from . ' -> ' . $to];
        }

        $personalBests = [];
        $defining = [];
        foreach ($records as $record) {
            $metric = (string) ($record['metric'] ?? '');
            if (!in_array($metric, ['best_season_goals', 'best_season_assists', 'most_season_appearances', 'best_season_rating'], true)) { continue; }
            $personalBests[] = ['metric' => $metric, 'label' => $this->recordLabel($metric), 'value' => $record['value'] ?? null, 'season_id' => $record['season_id'] ?? null, 'club_id' => $record['club_id'] ?? null, 'updated_date' => $record['updated_date'] ?? null, 'evidence' => $record['evidence'] ?? []];
            $seasonId = (string) ($record['season_id'] ?? '');
            if ($seasonId !== '') { $defining[$seasonId][] = $this->recordLabel($metric); }
            $timeline[] = ['source_key' => 'record|' . $playerId . '|' . $metric, 'date' => (string) ($record['updated_date'] ?? ''), 'title' => $this->recordLabel($metric) . ' — ' . $this->formatValue($record['value'] ?? null), 'description' => 'Personal best held from a canonical completed-Season aggregate.', 'kind' => 'record', 'importance' => 'major', 'season_id' => $seasonId];
        }
        foreach ($honours as $honour) { $seasonId = (string) ($honour['season_id'] ?? ''); if ($seasonId !== '') { $defining[$seasonId][] = (string) ($honour['label'] ?? 'Major honour'); } }
        foreach ($awards as $award) { $seasonId = (string) ($award['season_id'] ?? ''); if ($seasonId !== '') { $defining[$seasonId][] = $this->awardLabel((string) ($award['award_type'] ?? 'award')); } }
        $definingSeasons = [];
        foreach ($defining as $seasonId => $reasons) { $definingSeasons[] = ['season_id' => $seasonId, 'reasons' => array_values(array_unique($reasons))]; }
        usort($definingSeasons, static fn (array $left, array $right): int => strcmp((string) $left['season_id'], (string) $right['season_id']));

        $unique = [];
        foreach ($timeline as $item) {
            $key = (string) ($item['source_key'] ?? '');
            if ($key === '' || isset($unique[$key])) { continue; }
            $unique[$key] = $item;
        }
        $timeline = array_values($unique);
        $importance = ['routine' => 0, 'notable' => 1, 'major' => 2, 'landmark' => 3];
        usort($timeline, static fn (array $left, array $right): int => strcmp((string) ($left['date'] ?? ''), (string) ($right['date'] ?? '')) ?: (($importance[(string) ($right['importance'] ?? 'routine')] ?? 0) <=> ($importance[(string) ($left['importance'] ?? 'routine')] ?? 0)) ?: strcmp((string) ($left['source_key'] ?? ''), (string) ($right['source_key'] ?? '')));
        $timeline = array_slice($timeline, 0, 30);
        $landmarks = array_values(array_filter($timeline, static fn (array $item): bool => (string) ($item['importance'] ?? 'routine') !== 'routine'));

        return ['career_timeline' => $timeline, 'career_landmarks' => array_slice($landmarks, 0, 20), 'personal_bests' => $personalBests, 'defining_seasons' => array_slice($definingSeasons, 0, 8)];
    }

    /** @return list<array<string, mixed>> */
    private function firstMatchLandmarks(DatabaseInterface $database, string $playerId): array
    {
        $statement = $database->connection()->prepare('SELECT stats.match_id, stats.club_id, stats.started, stats.goals, stats.assists, matches.season_id, matches.scheduled_date, matches.competition_id, competitions.name AS competition_name, competitions.type AS competition_type FROM match_player_stats stats JOIN match_records matches ON matches.id = stats.match_id JOIN competition_records competitions ON competitions.id = matches.competition_id WHERE stats.player_id = :player_id AND stats.appeared = 1 AND matches.status = :status ORDER BY matches.scheduled_date ASC, stats.match_id ASC');
        $statement->execute(['player_id' => $playerId, 'status' => 'completed']);
        $first = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ((string) ($row['competition_type'] ?? '') === CompetitionType::International->value) { continue; }
            $base = ['date' => (string) $row['scheduled_date'], 'season_id' => (string) $row['season_id'], 'competition' => (string) $row['competition_name'], 'match_id' => (string) $row['match_id'], 'club_id' => (string) $row['club_id']];
            foreach ([
                'first_senior_appearance' => true,
                'first_senior_start' => (int) ($row['started'] ?? 0) === 1,
                'first_senior_goal' => (int) ($row['goals'] ?? 0) > 0,
                'first_senior_assist' => (int) ($row['assists'] ?? 0) > 0,
                'first_european_appearance' => (string) ($row['competition_type'] ?? '') === CompetitionType::Continental->value,
                'first_european_goal' => (string) ($row['competition_type'] ?? '') === CompetitionType::Continental->value && (int) ($row['goals'] ?? 0) > 0,
            ] as $key => $qualifies) {
                if ($qualifies && !isset($first[$key])) {
                    $club = $this->clubs->repository($database)->get(new \Goal\Legacy\Modules\Club\Domain\ClubId((string) $row['club_id']))->canonicalName();
                    $first[$key] = $base + ['club' => $club, 'source_key' => 'match-first|' . $playerId . '|' . $key, 'kind' => 'first', 'importance' => in_array($key, ['first_senior_goal', 'first_european_goal'], true) ? 'major' : 'notable', 'title' => $this->firstLabel($key), 'description' => 'First established from a completed canonical Match record.'];
                }
            }
        }

        return array_values($first);
    }

    private function firstLabel(string $key): string
    {
        return match ($key) { 'first_senior_appearance' => 'Senior debut', 'first_senior_start' => 'First senior start', 'first_senior_goal' => 'First senior goal', 'first_senior_assist' => 'First senior assist', 'first_european_appearance' => 'First European appearance', 'first_european_goal' => 'First European goal', default => 'Career first' };
    }

    private function recordLabel(string $metric): string
    {
        return match ($metric) { 'best_season_goals' => 'Most goals in a Season', 'best_season_assists' => 'Most assists in a Season', 'most_season_appearances' => 'Most appearances in a Season', 'best_season_rating' => 'Best average-rated Season', default => $this->milestoneLabel($metric, 0) };
    }

    private function milestoneImportance(string $metric, int $threshold): string
    {
        return match ($metric) {
            'club_appearances' => $threshold >= 200 ? 'landmark' : ($threshold >= 100 ? 'major' : 'notable'),
            'club_goals' => $threshold >= 100 ? 'landmark' : ($threshold >= 50 ? 'major' : 'notable'),
            'club_assists' => $threshold >= 100 ? 'landmark' : ($threshold >= 50 ? 'major' : 'notable'),
            'international_caps' => $threshold >= 50 ? 'landmark' : ($threshold >= 25 ? 'major' : 'notable'),
            'international_goals' => $threshold >= 25 ? 'landmark' : ($threshold >= 10 ? 'major' : 'notable'),
            default => 'notable',
        };
    }

    /** @return list<array<string, mixed>> */
    private function thresholdCallouts(string $metric, int $before, int $after, string $date, string $playerId, string $matchId): array
    {
        $thresholds = match ($metric) { 'club_appearances' => [50, 100, 200, 300, 500], 'club_goals' => [25, 50, 100, 200, 300], 'club_assists' => [25, 50, 100, 200], 'international_caps' => [10, 25, 50, 100], 'international_goals' => [1, 10, 25, 50], default => [] };
        $facts = [];
        foreach ($thresholds as $threshold) { if ($before < $threshold && $after >= $threshold) { $facts[] = $this->liveLandmark($metric . '|' . $threshold . '|' . $playerId . '|' . $matchId, $this->milestoneLabel($metric, $threshold), 'A meaningful Career threshold was reached in this completed Match.', $date, $this->milestoneImportance($metric, $threshold), ['metric' => $metric, 'threshold' => $threshold]); } }

        return $facts;
    }

    /** @param array<string, mixed> $evidence @return array<string, mixed> */
    private function liveLandmark(string $sourceKey, string $title, string $description, string $date, string $importance, array $evidence = []): array
    {
        return ['source_key' => 'live|' . $sourceKey, 'date' => $date, 'title' => $title, 'description' => $description, 'kind' => 'match_landmark', 'importance' => $importance, 'evidence' => $evidence];
    }

    private function hasCompletedTransfer(DatabaseInterface $database, string $playerId, string $currentTransferId): bool
    {
        $count = 0;
        foreach ((new TransferRepository($database))->byPlayer($playerId) as $transfer) { if ($transfer->id()->value() === $currentTransferId) { break; } if ($transfer->status() === TransferStatus::Completed) { ++$count; } }

        return $count > 0;
    }

    private function formatValue(mixed $value): string
    {
        return $value === null ? 'recorded' : (string) ((float) $value === (int) $value ? (int) $value : $value);
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
                'club_appearances' => [50, 100, 200, 300, 500],
                'club_goals' => [25, 50, 100, 200, 300],
                'club_assists' => [25, 50, 100, 200],
                'international_caps' => [10, 25, 50, 100],
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
