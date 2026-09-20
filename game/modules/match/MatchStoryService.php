<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Persistence\ClubSquadRepository;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\Domain\PlayerSelection;
use Goal\Legacy\Modules\Match\Domain\SelectionStatus;
use Goal\Legacy\Modules\Match\Persistence\MatchHighlightRepository;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
use Goal\Legacy\Modules\Match\Persistence\MatchSubstitutionRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Match\Persistence\ControlledMatchPositionRepository;
use Goal\Legacy\Modules\Player\Domain\AvailabilityStatus;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\FootballSocialService;
use Goal\Legacy\Modules\Player\Persistence\PlayerDevelopmentRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\PlayerAvailabilityService;

/**
 * Read-only controlled-Match storytelling over canonical Match facts.
 * Ordinary World Matches do not receive additional rows or commentary.
 */
final class MatchStoryService
{
    private PlayerMatchRatingService $ratings;

    public function __construct(?PlayerMatchRatingService $ratings = null)
    {
        $this->ratings = $ratings ?? new PlayerMatchRatingService();
    }

    /** @return array<string, mixed> */
    public function playerStory(DatabaseInterface $database, GameMatch $match, PlayerId|string $playerId): array
    {
        $id = $playerId instanceof PlayerId ? $playerId : new PlayerId($playerId);
        $player = (new PlayerRepository($database))->get($id);
        $selection = $this->selection($database, $match, $id);
        $stat = $this->stat($database, $match, $id);
        $timeline = $this->timeline($database, $match);
        $substitutionOn = null;
        $substitutionOff = null;
        foreach ((new MatchSubstitutionRepository($database))->byMatch($match->id()) as $substitution) {
            if ($substitution->incomingPlayerId()->value() === $id->value()) { $substitutionOn = $substitution->minute(); }
            if ($substitution->outgoingPlayerId()->value() === $id->value()) { $substitutionOff = $substitution->minute(); }
        }
        $dismissalMinute = null;
        foreach ($timeline as $event) {
            if ($event['player_id'] === $id->value() && $event['type'] === 'red_card') {
                $dismissalMinute = $event['minute'];
                break;
            }
        }

        $availabilityStatus = AvailabilityStatus::Available;
        if ($this->tableExists($database, 'player_availability_state') && $this->tableExists($database, 'player_availability_sources') && $this->tableExists($database, 'player_injuries')) {
            $availabilityStatus = (new PlayerAvailabilityService())->assess($database, $id, $match->scheduledDate())->status();
        }
        $status = $selection?->status() ?? SelectionStatus::NotSelected;
        $state = $this->participationState($status, $stat, $availabilityStatus);
        $position = (new ControlledMatchPositionRepository($database, false))->position($match->id(), $id) ?? $player->primaryPosition();
        $ratingExplanation = $stat === null ? $this->ratings->explain(new PlayerMatchStat($match->id(), $id, $selection?->clubId() ?? $match->homeClubId(), false, false, 0, 0), $position) : $this->ratings->explain($stat, $position);
        $facts = $this->playerFacts($id, $stat, $timeline);
        $teamId = $stat?->clubId()->value() ?? $selection?->clubId()->value();
        $teamResult = $teamId === null ? null : $this->teamResult($match, $teamId);
        $scoreAtEntry = $substitutionOn === null ? null : $this->scoreBeforeMinute($timeline, $substitutionOn);
        $playerOfMatch = $this->playerOfMatch($database, $match);

        return [
            'player_id' => $id->value(),
            'player_name' => $player->preferredName(),
            'selection_status' => $status->value,
            'participation_state' => $state['state'],
            'participation_label' => $state['label'],
            'availability_reason' => $state['reason'],
            'position' => $position->value,
            'position_label' => $this->positionLabel($position),
            'club_id' => $teamId,
            'appeared' => $stat?->appeared() ?? false,
            'started' => $stat?->started() ?? false,
            'minutes' => $stat?->minutes() ?? 0,
            'substitution_on_minute' => $substitutionOn,
            'substitution_off_minute' => $substitutionOff,
            'substitution_minute' => $substitutionOn,
            'dismissal_minute' => $dismissalMinute,
            'score_at_entry' => $scoreAtEntry,
            'stats' => $this->statArray($stat),
            'rating' => $ratingExplanation['rating'],
            'rating_explanation' => $ratingExplanation,
            'performance_label' => $ratingExplanation['label'],
            'team_result' => $teamResult,
            'timeline' => $timeline,
            'player_highlight_facts' => $facts,
            'player_of_match' => $playerOfMatch !== null && $playerOfMatch['player_id'] === $id->value(),
            'player_of_match_result' => $playerOfMatch,
            'decisive_contribution' => $this->decisiveContribution($match, $id, $stat, $timeline, $position),
            'career_impact' => $this->careerImpact($database, $match, $id, $teamId),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function timeline(DatabaseInterface $database, GameMatch $match): array
    {
        $homeGoals = 0;
        $awayGoals = 0;
        $final = $match->result();
        $winnerClub = $final?->winnerClubSide() === 'home' ? $match->homeClubId()->value() : ($final?->winnerClubSide() === 'away' ? $match->awayClubId()->value() : null);
        $timeline = [];
        foreach ((new MatchHighlightRepository($database))->byMatch($match->id()) as $highlight) {
            $before = ['home' => $homeGoals, 'away' => $awayGoals];
            $type = $highlight->type();
            if ($type === 'goal') {
                if ($highlight->clubId()?->value() === $match->homeClubId()->value()) { ++$homeGoals; }
                elseif ($highlight->clubId()?->value() === $match->awayClubId()->value()) { ++$awayGoals; }
            }
            $after = ['home' => $homeGoals, 'away' => $awayGoals];
            $clubId = $highlight->clubId()?->value();
            $decisive = $type === 'goal' && $winnerClub !== null && $clubId === $winnerClub
                && $after[($clubId === $match->homeClubId()->value()) ? 'home' : 'away'] === ($clubId === $match->homeClubId()->value() ? $final?->homeGoals() : $final?->awayGoals());
            $timeline[] = [
                'sequence' => $highlight->sequence(),
                'minute' => $highlight->minute(),
                'type' => $type,
                'importance' => $decisive ? 'decisive' : ($type === 'red_card' || $type === 'goal' ? 'major' : ($type === 'yellow_card' || $type === 'substitution' ? 'notable' : 'routine')),
                'club_id' => $clubId,
                'player_id' => $highlight->playerId()?->value(),
                'assist_player_id' => ($highlight->data()['assist_player_id'] ?? null) === null ? null : (string) $highlight->data()['assist_player_id'],
                'data' => $highlight->data(),
                'score_before' => $before,
                'score_after' => $after,
            ];
        }

        return $timeline;
    }

    /** @return array{valid:bool,errors:list<string>} */
    public function integrity(DatabaseInterface $database, GameMatch $match): array
    {
        $errors = [];
        $stats = (new PlayerMatchStatRepository($database))->byMatch($match->id());
        $selections = (new MatchSelectionRepository($database))->byMatch($match->id());
        $timeline = $this->timeline($database, $match);
        $result = $match->result();
        $goalEvents = array_values(array_filter($timeline, static fn (array $event): bool => $event['type'] === 'goal'));
        if ($result !== null && count($goalEvents) !== $result->homeGoals() + $result->awayGoals()) { $errors[] = 'timeline_goal_count'; }
        if ($stats !== []) {
            $goals = ['home' => 0, 'away' => 0];
            $assists = ['home' => 0, 'away' => 0];
            foreach ($stats as $stat) {
                $side = $stat->clubId()->value() === $match->homeClubId()->value() ? 'home' : ($stat->clubId()->value() === $match->awayClubId()->value() ? 'away' : null);
                if ($side === null) { $errors[] = 'stat_unknown_club'; continue; }
                $goals[$side] += $stat->goals();
                $assists[$side] += $stat->assists();
                $selection = array_values(array_filter($selections, static fn (PlayerSelection $value): bool => $value->playerId()->value() === $stat->playerId()->value()))[0] ?? null;
                if ($selection?->status() === SelectionStatus::Unavailable) { $errors[] = 'unavailable_player_has_stat'; }
            }
            if ($result !== null && ($goals['home'] !== $result->homeGoals() || $goals['away'] !== $result->awayGoals())) { $errors[] = 'stat_score_mismatch'; }
            if ($assists['home'] > $goals['home'] || $assists['away'] > $goals['away']) { $errors[] = 'assist_goal_mismatch'; }
            foreach ($selections as $selection) {
                $stat = array_values(array_filter($stats, static fn (PlayerMatchStat $value): bool => $value->playerId()->value() === $selection->playerId()->value()))[0] ?? null;
                if ($selection->status() === SelectionStatus::Starter && ($stat === null || !$stat->started())) { $errors[] = 'starter_minutes_mismatch'; }
                if ($selection->status() === SelectionStatus::Unavailable && $stat !== null) { $errors[] = 'unavailable_selection_mismatch'; }
            }

            $substitutions = (new MatchSubstitutionRepository($database))->byMatch($match->id());
            foreach ($substitutions as $substitution) {
                $incoming = array_values(array_filter($stats, static fn (PlayerMatchStat $value): bool => $value->playerId()->value() === $substitution->incomingPlayerId()->value()))[0] ?? null;
                $outgoing = array_values(array_filter($stats, static fn (PlayerMatchStat $value): bool => $value->playerId()->value() === $substitution->outgoingPlayerId()->value()))[0] ?? null;
                if ($incoming === null || !$incoming->appeared() || $incoming->started()) { $errors[] = 'substitution_incoming_mismatch'; }
                if ($outgoing === null || !$outgoing->started() || $outgoing->minutes() > $substitution->minute()) { $errors[] = 'substitution_outgoing_mismatch'; }
                $dismissal = array_values(array_filter($timeline, static fn (array $event): bool => $event['type'] === 'red_card' && $event['player_id'] === $substitution->incomingPlayerId()->value()))[0]['minute'] ?? 90;
                if ($incoming !== null && $incoming->minutes() !== max(0, min(90, (int) $dismissal) - $substitution->minute())) { $errors[] = 'substitution_minutes_mismatch'; }
            }
        }

        return ['valid' => $errors === [], 'errors' => array_values(array_unique($errors))];
    }

    /** @return array{state:string,label:string,reason:?string} */
    private function participationState(SelectionStatus $status, ?PlayerMatchStat $stat, AvailabilityStatus $availability): array
    {
        if ($status === SelectionStatus::Unavailable || $availability === AvailabilityStatus::Unavailable) {
            return ['state' => 'unavailable', 'label' => 'Unavailable — injury or fitness', 'reason' => 'Unavailable'];
        }
        if ($stat?->appeared() && $stat->started()) { return ['state' => 'starter', 'label' => 'Starting XI', 'reason' => null]; }
        if ($stat?->appeared()) { return ['state' => 'substitute', 'label' => 'Substitute appearance', 'reason' => null]; }
        if ($status === SelectionStatus::Bench) { return ['state' => 'unused_substitute', 'label' => 'Unused substitute', 'reason' => null]; }
        return ['state' => 'not_selected', 'label' => 'Not selected', 'reason' => null];
    }

    private function selection(DatabaseInterface $database, GameMatch $match, PlayerId $playerId): ?PlayerSelection
    {
        foreach ((new MatchSelectionRepository($database))->byMatch($match->id()) as $selection) {
            if ($selection->playerId()->value() === $playerId->value()) { return $selection; }
        }

        return null;
    }

    private function stat(DatabaseInterface $database, GameMatch $match, PlayerId $playerId): ?PlayerMatchStat
    {
        foreach ((new PlayerMatchStatRepository($database))->byMatch($match->id()) as $stat) {
            if ($stat->playerId()->value() === $playerId->value()) { return $stat; }
        }

        return null;
    }

    /** @return array<string, int> */
    private function statArray(?PlayerMatchStat $stat): array
    {
        if ($stat === null) {
            return ['minutes' => 0, 'goals' => 0, 'assists' => 0, 'shots' => 0, 'shots_on_target' => 0, 'saves' => 0, 'clean_sheets' => 0, 'tackles' => 0, 'interceptions' => 0, 'blocks' => 0, 'passes_attempted' => 0, 'passes_completed' => 0, 'fouls_committed' => 0, 'yellow_cards' => 0, 'red_cards' => 0];
        }

        $values = $stat->toArray();
        unset($values['match_id'], $values['player_id'], $values['club_id'], $values['appeared'], $values['started'], $values['minutes']);
        return ['minutes' => $stat->minutes()] + array_map('intval', $values);
    }

    /** @return list<array<string, mixed>> */
    private function playerFacts(PlayerId $playerId, ?PlayerMatchStat $stat, array $timeline): array
    {
        $facts = [];
        foreach ($timeline as $event) {
            if ($event['type'] === 'substitution') {
                $data = (array) ($event['data'] ?? []);
                if (($data['incoming_player_id'] ?? $event['player_id']) === $playerId->value()) { $facts[] = ['kind' => 'substitution', 'minute' => $event['minute'], 'direction' => 'in']; }
                if (($data['outgoing_player_id'] ?? null) === $playerId->value()) { $facts[] = ['kind' => 'substitution', 'minute' => $event['minute'], 'direction' => 'out']; }
            } elseif ($event['player_id'] === $playerId->value()) { $facts[] = ['kind' => $event['type'], 'minute' => $event['minute'], 'assist_player_id' => $event['assist_player_id'], 'scorer_player_id' => $event['player_id'], 'action_foot' => (($event['data']['action_foot'] ?? null) !== null ? (string) $event['data']['action_foot'] : null)]; }
            elseif ($event['assist_player_id'] === $playerId->value()) { $facts[] = ['kind' => 'assist', 'minute' => $event['minute'], 'scorer_player_id' => $event['player_id'], 'action_foot' => (($event['data']['assist_foot'] ?? null) !== null ? (string) $event['data']['assist_foot'] : null)]; }
        }
        if ($stat !== null) {
            if ($stat->saves() > 0) { $facts[] = ['kind' => 'saves', 'count' => $stat->saves()]; }
            if ($stat->shotsOnTarget() > 0 && $stat->goals() === 0) { $facts[] = ['kind' => 'shots_on_target', 'count' => $stat->shotsOnTarget()]; }
            if (($stat->tackles() + $stat->interceptions() + $stat->blocks()) > 0) { $facts[] = ['kind' => 'defending', 'tackles' => $stat->tackles(), 'interceptions' => $stat->interceptions(), 'blocks' => $stat->blocks()]; }
            if ($stat->passesAttempted() > 0) { $facts[] = ['kind' => 'passing', 'completed' => $stat->passesCompleted(), 'attempted' => $stat->passesAttempted()]; }
            if ($stat->cleanSheets() > 0) { $facts[] = ['kind' => 'clean_sheet']; }
        }

        $unique = [];
        foreach ($facts as $fact) {
            $key = json_encode($fact, JSON_THROW_ON_ERROR);
            if (isset($unique[$key])) { continue; }
            $unique[$key] = true;
            if (count($unique) >= 8) { break; }
        }

        return array_map(static fn (string $key): array => json_decode($key, true, 512, JSON_THROW_ON_ERROR), array_keys($unique));
    }

    /** @return array<string, mixed>|null */
    private function playerOfMatch(DatabaseInterface $database, GameMatch $match): ?array
    {
        $stats = (new PlayerMatchStatRepository($database))->byMatch($match->id());
        if ($stats === []) { return null; }
        $players = new PlayerRepository($database);
        $ids = array_map(static fn (PlayerMatchStat $stat): PlayerId => $stat->playerId(), $stats);
        $byId = [];
        foreach ($players->byIds($ids) as $player) { $byId[$player->id()->value()] = $player; }
        $candidates = [];
        foreach ($stats as $stat) {
            if (!$stat->appeared() || !isset($byId[$stat->playerId()->value()])) { continue; }
            $position = (new ControlledMatchPositionRepository($database, false))->position($match->id(), $stat->playerId()) ?? $byId[$stat->playerId()->value()]->primaryPosition();
            $rating = $this->ratings->rate($stat, $position);
            if ($rating === null) { continue; }
            $candidates[] = ['player_id' => $stat->playerId()->value(), 'rating' => $rating, 'minutes' => $stat->minutes(), 'goals' => $stat->goals(), 'assists' => $stat->assists()];
        }
        usort($candidates, static fn (array $left, array $right): int => (($right['rating'] <=> $left['rating']) ?: ($right['minutes'] <=> $left['minutes']) ?: ($right['goals'] <=> $left['goals']) ?: ($right['assists'] <=> $left['assists']) ?: strcmp($left['player_id'], $right['player_id'])));

        return $candidates[0] ?? null;
    }

    /** @return array{home:int,away:int}|null */
    private function scoreBeforeMinute(array $timeline, int $minute): ?array
    {
        foreach ($timeline as $event) {
            if ($event['type'] === 'substitution' && $event['minute'] === $minute) { return $event['score_before']; }
        }

        return null;
    }

    private function teamResult(GameMatch $match, string $clubId): string
    {
        $result = $match->result();
        if ($result === null) { return 'scheduled'; }
        $home = $clubId === $match->homeClubId()->value();
        $for = $home ? $result->homeGoals() : $result->awayGoals();
        $against = $home ? $result->awayGoals() : $result->homeGoals();

        return $for === $against ? 'draw' : ($for > $against ? 'win' : 'loss');
    }

    /** @return array<string, mixed>|null */
    private function decisiveContribution(GameMatch $match, PlayerId $playerId, ?PlayerMatchStat $stat, array $timeline, PlayerPosition $position): ?array
    {
        if ($stat === null || !$stat->appeared() || $match->result() === null) { return null; }
        $teamResult = $this->teamResult($match, $stat->clubId()->value());
        $goals = array_values(array_filter($timeline, static fn (array $event): bool => $event['type'] === 'goal' && $event['player_id'] === $playerId->value()));
        $assists = array_values(array_filter($timeline, static fn (array $event): bool => $event['type'] === 'goal' && $event['assist_player_id'] === $playerId->value()));
        if ($teamResult === 'win' && $goals !== []) { return ['type' => 'goal', 'label' => count($goals) === 1 ? 'Scored for the winning side' : 'Scored multiple goals']; }
        if ($teamResult === 'win' && $assists !== []) { return ['type' => 'assist', 'label' => 'Created a goal for the winning side']; }
        if ($teamResult === 'draw' && ($goals !== [] || $assists !== [])) { return ['type' => 'equalizer_context', 'label' => 'Contributed in a drawn Match']; }
        if ($teamResult === 'win' && $stat->cleanSheets() > 0 && in_array($position, [PlayerPosition::Goalkeeper, PlayerPosition::CentreBack, PlayerPosition::LeftBack, PlayerPosition::RightBack], true)) { return ['type' => 'clean_sheet', 'label' => 'Helped secure a clean sheet']; }

        return null;
    }

    /** @return array<string, mixed> */
    private function careerImpact(DatabaseInterface $database, GameMatch $match, PlayerId $playerId, ?string $clubId): array
    {
        $items = [];
        $evaluations = $this->tableExists($database, 'career_match_evaluations') && $this->tableExists($database, 'player_form_summaries') ? (new \Goal\Legacy\Modules\Player\Persistence\CareerEvaluationRepository($database))->byPlayer($playerId) : [];
        $current = null;
        $previous = null;
        foreach ($evaluations as $row) {
            if ((string) ($row['match_id'] ?? '') === $match->id()->value()) { $current = $row; continue; }
            if ($current !== null) { $previous = $row; break; }
        }
        $form = 'unchanged';
        if ($current !== null && $previous !== null) { $form = ((int) $current['evaluation_score'] > (int) $previous['evaluation_score']) ? 'improved' : (((int) $current['evaluation_score'] < (int) $previous['evaluation_score']) ? 'declined' : 'held'); }
        elseif ($current !== null) { $form = 'new evidence'; }
        if ($current !== null) { $items[] = 'Form evidence ' . $form; }

        $development = $this->tableExists($database, 'player_development_state') && $this->tableExists($database, 'player_development_history') ? (new PlayerDevelopmentRepository($database))->bySource($playerId, 'match', $match->id()->value() . ':' . $playerId->value()) : null;
        if ($development !== null && $development->afterOverall() !== $development->beforeOverall()) { $items[] = 'Development progressed'; }

        $role = null;
        if ($clubId !== null) {
            foreach ((new ClubSquadRepository($database))->roleHistory($playerId, $match->seasonId()) as $entry) {
                if ($entry['club_id'] === $clubId && $entry['occurred_date'] === $match->scheduledDate()->toIsoString() && $entry['source'] === 'evaluation') { $items[] = 'Role changed to ' . $entry['role']; break; }
            }
            $memberships = (new ClubSquadRepository($database))->byPlayer($playerId, $match->seasonId());
            $role = $memberships[0]?->role()->value;
        }
        foreach ((new FootballSocialService())->history($database, $playerId, 20) as $entry) {
            if ((string) ($entry['event_date'] ?? '') === $match->scheduledDate()->toIsoString() && in_array((string) ($entry['category'] ?? ''), ['football', 'rivalry'], true)) { $items[] = (string) ($entry['headline'] ?? 'Football context updated'); break; }
        }

        return ['meaningful' => $items !== [], 'items' => array_values(array_unique($items)), 'form' => $form, 'development' => $development?->toArray(), 'role' => $role];
    }

    private function positionLabel(PlayerPosition $position): string
    {
        return match ($position) {
            PlayerPosition::Goalkeeper => 'Goalkeeper',
            PlayerPosition::CentreBack => 'Centre-back',
            PlayerPosition::LeftBack => 'Left-back',
            PlayerPosition::RightBack => 'Right-back',
            PlayerPosition::DefensiveMidfielder => 'Defensive midfielder',
            PlayerPosition::CentralMidfielder => 'Central midfielder',
            PlayerPosition::AttackingMidfielder => 'Attacking midfielder',
            PlayerPosition::LeftWinger => 'Left winger',
            PlayerPosition::RightWinger => 'Right winger',
            PlayerPosition::Striker => 'Striker',
        };
    }

    private function tableExists(DatabaseInterface $database, string $table): bool
    {
        $statement = $database->connection()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table");
        $statement->execute(['table' => $table]);

        return $statement->fetchColumn() !== false;
    }
}
