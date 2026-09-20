<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Club;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Persistence\ClubMembershipRepository;
use Goal\Legacy\Modules\Club\Persistence\ClubSeasonObjectiveRepository;
use Goal\Legacy\Modules\Competition\Domain\Competition;
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
use Goal\Legacy\Modules\Competition\DomesticCupService;
use Goal\Legacy\Modules\Competition\EuropeanCompetitionService;
use Goal\Legacy\Modules\Competition\Persistence\CompetitionRepository;
use Goal\Legacy\Modules\Competition\PromotionRelegationService;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Match\StandingsService;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SeasonStatus;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

/**
 * Derives the controlled Career's Club Season stakes from canonical Club,
 * Competition, fixture and standings data. Player-role expectation remains
 * owned by Player\ClubExpectationService; this is a Club-level objective.
 */
final class ClubSeasonObjectiveService
{
    public const TITLE_CHALLENGE = 'title_challenge';
    public const EUROPEAN_QUALIFICATION = 'european_qualification';
    public const TOP_HALF = 'top_half';
    public const STABLE_SEASON = 'stable_season';
    public const AVOID_RELEGATION = 'avoid_relegation';
    public const PROMOTION_CHALLENGE = 'promotion_challenge';

    public const COMPETE = 'compete';
    public const REACH_LATER_ROUNDS = 'reach_later_rounds';
    public const SERIOUS_CONTENDER = 'serious_contender';

    private readonly PromotionRelegationService $promotionRelegation;

    public function __construct(private readonly ClubService $clubs)
    {
        $this->promotionRelegation = new PromotionRelegationService($clubs);
    }

    /**
     * @param array<string, mixed>|null $playerContribution
     * @return array<string, mixed>|null
     */
    public function context(
        DatabaseInterface $database,
        ClubId|string $clubId,
        SeasonId|string $seasonId,
        SimulationDate $date,
        ?array $playerContribution = null,
    ): ?array {
        $clubKey = $clubId instanceof ClubId ? $clubId->value() : $clubId;
        $seasonKey = $seasonId instanceof SeasonId ? $seasonId->value() : $seasonId;
        $clubs = $this->clubs->repository($database);
        $club = $clubs->get($clubKey);
        $seasons = new \Goal\Legacy\Modules\World\Persistence\SeasonRepository($database);
        $season = $seasons->get($seasonKey);
        $competitions = new CompetitionRepository($database);
        $memberships = array_values(array_filter(
            (new ClubMembershipRepository($database))->byClub($club->id()),
            static fn ($membership): bool => $membership->seasonId()->value() === $season->id()->value(),
        ));
        $records = [];
        foreach ($memberships as $membership) {
            $records[] = $competitions->get($membership->competitionId());
        }
        $league = $this->primaryLeague($records);
        if ($league === null) {
            return null;
        }

        $repository = new ClubSeasonObjectiveRepository($database);
        // The derived Club page has no Player key. A controlled row is looked
        // up only when a Player contribution is supplied.
        $stored = null;
        if (is_string($playerContribution['player_id'] ?? null)) {
            $stored = $repository->find((string) $playerContribution['player_id'], $season->id()->value(), $club->id()->value());
        } else {
            $stored = null;
        }

        $table = (new StandingsService($this->clubs))->table($database, $league->id(), $season->id());
        $rankByClub = [];
        foreach ($table as $index => $row) {
            $rankByClub[(string) $row['club_id']] = ['rank' => $index + 1] + $row;
        }
        $leagueRow = $rankByClub[$club->id()->value()] ?? null;
        $size = count($table);
        $objective = (string) ($stored['objective'] ?? $this->deriveLeagueObjective($club->reputation(), $league->tier()));
        $cupObjective = $stored['cup_objective'] ?? $this->deriveKnockoutObjective($club->reputation(), $records, CompetitionType::DomesticCup);
        $europeObjective = $stored['europe_objective'] ?? $this->deriveKnockoutObjective($club->reputation(), $records, CompetitionType::Continental);
        $complete = $season->status() === SeasonStatus::Completed || !$date->isBefore($season->endDate());
        $phase = $this->seasonPhase($database, $league, $season, $date, $complete);
        $rank = $leagueRow === null ? null : (int) $leagueRow['rank'];
        $progress = $this->progress($objective, $rank, $size, $complete, $league->nationId()->value());
        $importantFixtures = $this->importantFixtures($database, $club->id()->value(), $season, $date, $league, $objective, $rankByClub, $size);
        $pressure = $this->pressure($objective, $progress, $phase, count($importantFixtures), $complete);
        $cup = $this->knockoutContext($database, $club->id()->value(), $season, $records, CompetitionType::DomesticCup, $cupObjective);
        $europe = $this->knockoutContext($database, $club->id()->value(), $season, $records, CompetitionType::Continental, $europeObjective);
        $leagueContext = [
            'id' => $league->id()->value(),
            'name' => $league->name(),
            'tier' => $league->tier(),
            'position' => $rank,
            'size' => $size,
            'played' => $leagueRow === null ? 0 : (int) ($leagueRow['played'] ?? 0),
            'points' => $leagueRow === null ? 0 : (int) ($leagueRow['points'] ?? 0),
        ];

        return [
            'available' => true,
            'club' => ['id' => $club->id()->value(), 'name' => $club->canonicalName(), 'reputation' => $club->reputation()],
            'season' => ['id' => $season->id()->value(), 'label' => $season->label(), 'phase' => $phase],
            'expectation' => $objective,
            'cup_expectation' => $cupObjective,
            'europe_expectation' => $europeObjective,
            'cup' => $cup,
            'europe' => $europe,
            'league' => $leagueContext,
            'progress' => $progress,
            'pressure' => $pressure,
            'complete' => $complete,
            'title_race' => $this->titleRace($objective, $rank, $size, $complete),
            'european_qualification_race' => $this->europeanRace($rank, $size, $complete),
            'promotion_relegation' => $this->tierContext($league, $rank, $size, $complete),
            'important_fixtures' => $importantFixtures,
            'player_contribution' => $playerContribution === null ? null : $this->contribution($playerContribution, $objective, $progress),
            'outcome' => $stored['outcome'] ?? ($complete ? ($progress === 'unknown' ? 'unknown' : $this->outcome($progress)) : null),
        ];
    }

    /**
     * Resolve one compact outcome for each controlled Player/Club represented
     * in the completed Season. This runs at rollover, never during rendering.
     * @return array{resolved:int,skipped:int}
     */
    public function resolveControlledSeason(DatabaseInterface $database, Season $season, SimulationDate $date): array
    {
        $repository = new ClubSeasonObjectiveRepository($database);
        $squads = $this->clubs->squadRepository($database);
        $resolved = 0;
        $skipped = 0;
        foreach ((new CareerPlayerRepository($database))->playerIds() as $playerId) {
            $memberships = $squads->byPlayer($playerId, $season->id());
            $clubs = [];
            foreach ($memberships as $membership) {
                $clubs[$membership->clubId()->value()] = true;
            }
            foreach (array_keys($clubs) as $clubId) {
                $context = $this->context($database, $clubId, $season->id(), $date, ['player_id' => $playerId]);
                if ($context === null) {
                    ++$skipped;
                    continue;
                }
                $existing = $repository->find($playerId, $season->id()->value(), $clubId);
                if ($existing !== null && $existing['outcome'] !== null) {
                    ++$skipped;
                    continue;
                }
                $league = (array) ($context['league'] ?? []);
                $repository->saveOutcome(
                    $playerId,
                    $season->id()->value(),
                    $clubId,
                    (string) ($league['id'] ?? ''),
                    (string) ($context['expectation'] ?? self::STABLE_SEASON),
                    is_string($context['cup_expectation'] ?? null) ? $context['cup_expectation'] : null,
                    is_string($context['europe_expectation'] ?? null) ? $context['europe_expectation'] : null,
                    is_string($context['outcome'] ?? null) ? $context['outcome'] : null,
                    $date->toIsoString(),
                    [
                        'league_position' => $league['position'] ?? null,
                        'league_size' => $league['size'] ?? null,
                        'progress' => $context['progress'] ?? null,
                        'phase' => $context['season']['phase'] ?? null,
                    ],
                );
                ++$resolved;
            }
        }

        return ['resolved' => $resolved, 'skipped' => $skipped];
    }

    /** @return list<string> */
    public function integrity(DatabaseInterface $database): array
    {
        return (new ClubSeasonObjectiveRepository($database))->integrity();
    }

    /** @param list<Competition> $records */
    private function primaryLeague(array $records): ?Competition
    {
        $leagues = array_values(array_filter($records, static fn (Competition $competition): bool => $competition->type() === CompetitionType::DomesticLeague));
        usort($leagues, static fn (Competition $left, Competition $right): int => ($left->tier() <=> $right->tier()) ?: strcmp($left->id()->value(), $right->id()->value()));

        return $leagues[0] ?? null;
    }

    private function deriveLeagueObjective(int $reputation, int $tier): string
    {
        if ($tier <= 1) {
            return match (true) {
                $reputation >= 88 => self::TITLE_CHALLENGE,
                $reputation >= 70 => self::EUROPEAN_QUALIFICATION,
                $reputation >= 54 => self::TOP_HALF,
                default => self::AVOID_RELEGATION,
            };
        }

        return $reputation >= 68 ? self::PROMOTION_CHALLENGE : self::STABLE_SEASON;
    }

    /** @param list<Competition> $records */
    private function deriveKnockoutObjective(int $reputation, array $records, CompetitionType $type): ?string
    {
        foreach ($records as $competition) {
            if ($competition->type() !== $type) {
                continue;
            }

            return match (true) {
                $reputation >= 84 => self::SERIOUS_CONTENDER,
                $reputation >= 65 => self::REACH_LATER_ROUNDS,
                default => self::COMPETE,
            };
        }

        return null;
    }

    /** @param list<Competition> $records @return array<string, mixed>|null */
    private function knockoutContext(DatabaseInterface $database, string $clubId, Season $season, array $records, CompetitionType $type, ?string $objective): ?array
    {
        $competition = null;
        foreach ($records as $record) {
            if ($record->type() === $type) {
                $competition = $record;
                break;
            }
        }
        if ($competition === null || $objective === null) {
            return null;
        }

        $history = $type === CompetitionType::DomesticCup
            ? (new DomesticCupService($this->clubs))->historyForClub($database, $clubId)
            : (new EuropeanCompetitionService($this->clubs, new DomesticCupService($this->clubs)))->historyForClub($database, $clubId);
        foreach ($history as $row) {
            if (($row['season_id'] ?? null) !== $season->id()->value() || ($row['competition_id'] ?? null) !== $competition->id()->value()) {
                continue;
            }
            $status = (string) ($row['club_status'] ?? 'active');
            if ($status === 'eliminated') {
                $status = 'eliminated';
            } elseif (($row['winner_club_id'] ?? null) === $clubId) {
                $status = 'won';
            } elseif (($row['status'] ?? null) === 'completed') {
                $status = 'completed';
            } else {
                $status = 'in_progress';
            }

            return [
                'competition' => $competition->name(),
                'competition_id' => $competition->id()->value(),
                'expectation' => $objective,
                'status' => $status,
                'round' => $row['current_round'] ?? $row['current_stage'] ?? null,
            ];
        }

        return [
            'competition' => $competition->name(),
            'competition_id' => $competition->id()->value(),
            'expectation' => $objective,
            'status' => 'not_started',
            'round' => null,
        ];
    }

    private function seasonPhase(DatabaseInterface $database, Competition $league, Season $season, SimulationDate $date, bool $complete): string
    {
        if ($complete) {
            return 'complete';
        }
        $fixtures = (new MatchRepository($database))->byCompetition($league->id(), $season->id());
        $total = count($fixtures);
        $completed = count(array_filter($fixtures, static fn (GameMatch $match): bool => $match->status() === MatchStatus::Completed && !$match->scheduledDate()->isAfter($date)));
        $ratio = $total > 0 ? $completed / $total : $this->dateRatio($season, $date);

        return $ratio < 0.30 ? 'early_season' : ($ratio < 0.70 ? 'midseason' : 'run_in');
    }

    private function dateRatio(Season $season, SimulationDate $date): float
    {
        $duration = max(1, $season->startDate()->daysUntil($season->endDate()));
        return max(0.0, min(1.0, $season->startDate()->daysUntil($date) / $duration));
    }

    private function progress(string $objective, ?int $rank, int $size, bool $complete, string $nationId): string
    {
        if ($rank === null || $size === 0) {
            return 'unknown';
        }
        [$minimum, $maximum] = $this->targetRange($objective, $size, $nationId);
        if ($complete) {
            return $rank < $minimum ? 'exceeding' : (($rank <= $maximum) ? 'achieved' : 'failed');
        }
        if ($rank < $minimum) {
            return 'exceeding';
        }
        if ($rank <= $maximum) {
            return 'on_track';
        }
        return $rank <= $maximum + max(2, (int) ceil($size * 0.12)) ? 'under_pressure' : 'at_risk';
    }

    /** @return array{0:int,1:int} */
    private function targetRange(string $objective, int $size, string $nationId): array
    {
        $half = max(1, (int) ceil($size / 2));

        return match ($objective) {
            self::TITLE_CHALLENGE => [1, 1],
            self::EUROPEAN_QUALIFICATION => [1, min(3, $size)],
            self::TOP_HALF => [1, $half],
            self::AVOID_RELEGATION => [1, max(1, $size - $this->promotionRelegation->automaticExchangeCountForNation($nationId))],
            self::PROMOTION_CHALLENGE => [1, min(2, $size)],
            default => [1, max(1, (int) ceil($size * 0.70))],
        };
    }

    private function outcome(string $progress): string
    {
        return match ($progress) {
            'exceeding' => 'exceeded',
            'achieved', 'on_track' => 'achieved',
            default => 'missed',
        };
    }

    private function pressure(string $objective, string $progress, string $phase, int $importantFixtures, bool $complete): string
    {
        if ($complete) {
            return in_array($progress, ['achieved', 'exceeding'], true) ? 'low' : 'normal';
        }
        $pressure = match ($progress) {
            'at_risk' => 3,
            'under_pressure' => 2,
            'on_track' => in_array($objective, [self::TITLE_CHALLENGE, self::PROMOTION_CHALLENGE], true) ? 1 : 0,
            default => 0,
        };
        if ($phase === 'run_in' && $progress !== 'unknown') {
            ++$pressure;
        }
        if ($importantFixtures > 0) {
            ++$pressure;
        }

        return match (min(3, $pressure)) {
            3 => 'high',
            2 => 'building',
            1 => 'normal',
            default => 'low',
        };
    }

    private function titleRace(string $objective, ?int $rank, int $size, bool $complete): bool
    {
        return !$complete && $rank !== null && $rank <= min(4, $size) && $objective === self::TITLE_CHALLENGE;
    }

    private function europeanRace(?int $rank, int $size, bool $complete): bool
    {
        return !$complete && $rank !== null && $rank <= min(5, $size);
    }

    /** @return array{type:string,places:int|null,status:string} */
    private function tierContext(Competition $league, ?int $rank, int $size, bool $complete): array
    {
        $places = $this->promotionRelegation->automaticExchangeCountForNation($league->nationId()->value());
        if ($places === 0 || $rank === null) {
            return ['type' => 'none', 'places' => null, 'status' => 'not_applicable'];
        }
        if ($league->tier() === 2) {
            return ['type' => 'promotion', 'places' => $places, 'status' => $complete ? ($rank <= $places ? 'promoted' : 'not_promoted') : ($rank <= $places ? 'on_track' : ($rank <= $places + 2 ? 'under_pressure' : 'outside_places'))];
        }

        return ['type' => 'relegation', 'places' => $places, 'status' => $complete ? ($rank > $size - $places ? 'survived' : 'relegated') : ($rank > $size - $places ? 'safe' : 'in_danger')];
    }

    /** @param array<string, array<string, int|string>> $rankByClub @return list<array<string, mixed>> */
    private function importantFixtures(DatabaseInterface $database, string $clubId, Season $season, SimulationDate $date, Competition $league, string $objective, array $rankByClub, int $size): array
    {
        $matches = (new MatchRepository($database))->byClub(new ClubId($clubId), $season->id());
        $competitions = new CompetitionRepository($database);
        $clubs = $this->clubs->repository($database);
        $result = [];
        foreach ($matches as $match) {
            if ($match->status() !== MatchStatus::Scheduled || $match->scheduledDate()->isBefore($date)) {
                continue;
            }
            $competition = $competitions->get($match->competitionId());
            $opponentId = $match->homeClubId()->value() === $clubId ? $match->awayClubId()->value() : $match->homeClubId()->value();
            $reason = null;
            if ($competition->type() === CompetitionType::DomesticCup) {
                $reason = 'cup_knockout';
            } elseif ($competition->type() === CompetitionType::Continental && $match->round() >= 7) {
                $reason = 'european_knockout';
            } elseif ($competition->id()->value() === $league->id()->value()) {
                $rank = $rankByClub[$clubId]['rank'] ?? null;
                $opponentRank = $rankByClub[$opponentId]['rank'] ?? null;
                if ($rank !== null && $opponentRank !== null && $this->isLeagueRival($objective, (int) $rank, (int) $opponentRank, $size)) {
                    $reason = match ($objective) {
                        self::TITLE_CHALLENGE => 'title_rival',
                        self::PROMOTION_CHALLENGE => 'promotion_rival',
                        self::AVOID_RELEGATION => 'relegation_rival',
                        default => 'qualification_rival',
                    };
                }
            }
            if ($reason === null) {
                continue;
            }
            $result[] = [
                'match_id' => $match->id()->value(),
                'date' => $match->scheduledDate()->toIsoString(),
                'competition' => $competition->name(),
                'competition_id' => $competition->id()->value(),
                'opponent_club_id' => $opponentId,
                'opponent' => $clubs->get($opponentId)->canonicalName(),
                'reason' => $reason,
                'round' => $match->round(),
            ];
            if (count($result) >= 3) {
                break;
            }
        }

        return $result;
    }

    private function isLeagueRival(string $objective, int $rank, int $opponentRank, int $size): bool
    {
        if (abs($rank - $opponentRank) > 4) {
            return false;
        }
        $top = $objective === self::TITLE_CHALLENGE ? 4 : ($objective === self::PROMOTION_CHALLENGE ? 4 : ($objective === self::EUROPEAN_QUALIFICATION ? 5 : 0));
        if ($top > 0) {
            return $rank <= $top && $opponentRank <= $top;
        }
        if ($objective === self::AVOID_RELEGATION) {
            return $rank > $size - 5 && $opponentRank > $size - 5;
        }

        $half = max(1, (int) ceil($size / 2));

        return $rank <= $half && $opponentRank <= $half;
    }

    /** @param array<string, mixed> $player @return array<string, mixed> */
    private function contribution(array $player, string $objective, string $progress): array
    {
        $appearances = (int) ($player['appearances'] ?? 0);
        $minutes = (int) ($player['minutes'] ?? 0);
        $role = (string) ($player['role'] ?? '');
        $label = match (true) {
            $appearances === 0 && ($player['availability'] ?? 'available') === 'unavailable' => 'Limited by availability',
            $minutes >= 1800 || $role === 'key_player' => 'Important contributor',
            $appearances >= 8 || $minutes >= 700 => 'Contributing to the Season',
            $appearances > 0 => 'Developing contribution',
            default => 'Awaiting a larger opportunity',
        };

        return [
            'label' => $label,
            'appearances' => $appearances,
            'starts' => (int) ($player['starts'] ?? 0),
            'minutes' => $minutes,
            'goals' => (int) ($player['goals'] ?? 0),
            'assists' => (int) ($player['assists'] ?? 0),
            'average_match_rating' => $player['average_match_rating'] ?? null,
            'role' => $role,
            'objective_context' => $objective,
            'progress_context' => $progress,
        ];
    }
}
