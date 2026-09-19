<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\MatchStoryService;
use Goal\Legacy\Modules\Match\Persistence\MatchHighlightRepository;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PDO;

/**
 * Controlled-career presentation state for the fictional PULSE platform.
 * Football systems own facts; Echo selects public moments; this service
 * stores bounded sources and deterministic presentation artifacts.
 */
final class PulseService
{
    private const SOURCES = 'pulse_feed_sources';
    private const POSTS = 'pulse_posts';
    private const AUDIENCE = 'pulse_player_states';
    private const RESPONSES = 'pulse_response_states';
    private const MAX_SOURCES = 200;

    /** @var array<string, list<string>> */
    private const TEMPLATES = [
        'fan' => [
            'match_goal' => ['That finish from {player} changed the {competition} night. {score}.', 'You could feel that goal coming. {player} delivered for {team}.', '{player} gave us a moment to remember. What a goal for {team}.'],
            'match_assist' => ['That pass from {player} opened the whole match. {team} deserved that moment.', '{player} saw the opening before anyone else. A huge assist in the {competition}.'],
            'match_decisive_goal' => ['Decisive when it mattered: {player} put {team} through. {score}.', 'Big players own big moments. {player} changed this knockout night.'],
            'match_major_contribution' => ['{player} shaped the result for {team}. That was a serious performance.', 'A result built on work like that from {player}.'],
            'match_strong_performance' => ['{player} was everywhere for {team} today. A performance worth noticing.', 'That was a proper {player} performance: composed, committed, effective.'],
            'match_result' => ['A big {competition} result for {team}: {score}.', '{team} got the job done tonight. {player} played their part.'],
            'match_red_card' => ['The red changed the night for {team}. {player} will have to answer for the moment.', 'A difficult night after {player} was dismissed. The team will regroup.'],
            'transfer_request' => ['Transfer talk is now public for {player}. Every next step matters.', 'A new chapter may be coming for {player}.'],
            'transfer' => ['Welcome to {team}, {player}. A fresh football chapter starts now.', '{player} has chosen a new challenge with {team}.'],
            'free_agent_signing' => ['A new home found: welcome to {team}, {player}.', '{player} keeps the Career moving with {team}.'],
            'award' => ['That recognition is deserved. {player} has earned {headline}.', 'A special individual honour for {player}: {headline}.'],
            'honour' => ['History made: {player} can call {headline} part of the Career now.', 'The medals matter. Congratulations to {player} on {headline}.'],
            'record' => ['Another page in the record book for {player}: {headline}.', '{player} keeps raising the standard with {headline}.'],
            'milestone' => ['A Career milestone for {player}: {headline}.', 'Small steps become a legacy. Well done, {player}.'],
            'retirement' => ['A full playing Career deserves respect. Thank you for the memories, {player}.', '{player} has closed a playing Career built one Season at a time.'],
            'injury' => ['Tough news for {player}. The supporters are behind the recovery.'],
            'return' => ['Good to see {player} back in the football conversation. Welcome back.'],
            'career_choice' => ['A football decision has shaped {player}\'s next chapter.', 'The Career keeps moving, one meaningful choice at a time.'],
        ],
        'media' => [
            'match_goal' => ['Match report reaction: {player}\'s goal changed {team}\'s {competition} result ({score}).', 'The talking point from {competition}: {player} delivered in front of goal.'],
            'match_assist' => ['A measured assist from {player} became a key part of {team}\'s {score} result.', '{player}\'s creative contribution deserves attention after the {competition}.'],
            'match_decisive_goal' => ['Decisive contribution: {player} sent {team} through with the moment that mattered.', 'The {competition} pressure produced a defining moment from {player}.'],
            'match_major_contribution' => ['{player} had a material influence on {team}\'s {score} result.', 'A performance with consequence from {player}; the context made it significant.'],
            'match_strong_performance' => ['A strong rating and real evidence put {player} among the day\'s stories.', '{player}\'s work without the ball and on it stood out for {team}.'],
            'match_result' => ['{team} leave the {competition} with a {score} result and a useful contribution from {player}.', 'A significant result for {team}; {player} was involved in the story.'],
            'match_red_card' => ['Discipline became the defining detail after {player}\'s dismissal.', '{player}\'s red card changed the match narrative for {team}.'],
            'transfer_request' => ['Transfer request confirmed: {player}\'s Club future is now a live Career question.', 'The market conversation around {player} has moved from private to public.'],
            'transfer' => ['Confirmed: {player} begins a new Club chapter with {team}.', 'A meaningful Career move sees {player} join {team}.'],
            'free_agent_signing' => ['Free-agent signing confirmed: {player} has joined {team}.', '{player} has found the next Club platform after free agency.'],
            'award' => ['Individual recognition for {player}: {headline}.', 'The Season evidence has produced a major award for {player}: {headline}.'],
            'honour' => ['A landmark achievement enters {player}\'s record: {headline}.', '{player}\'s Career now includes {headline}.'],
            'record' => ['Record watch: {player} has reached {headline}.', 'The numbers have made {headline} part of {player}\'s story.'],
            'milestone' => ['Milestone reached by {player}: {headline}.', 'A durable Career marker for {player}: {headline}.'],
            'retirement' => ['Playing Career complete: {player} retires with a record of football worth remembering.', 'The final chapter is complete for {player}; the evidence now belongs to Career history.'],
            'injury' => ['Availability update: {player} begins a recovery period after the recorded injury.'],
            'return' => ['Availability update: {player} has returned from the recorded injury.'],
            'career_choice' => ['A controlled Career decision has changed the context around {player}.'],
        ],
        'club' => [
            'match_goal' => ['A goal, a result, and a proud night for {team}. Well done, {player}.', '{team} celebrates the contribution that helped secure {score}.'],
            'match_assist' => ['The team effort showed again: {player} supplied the assist in {team}\'s {score}.', 'A selfless moment from {player} helped {team} move forward.'],
            'match_decisive_goal' => ['A defining {competition} moment for {team}. {player}, take a bow.', '{team} advance because {player} stood up when it mattered.'],
            'match_major_contribution' => ['The badge was represented well today. {player} made a difference for {team}.'],
            'match_strong_performance' => ['Professional, committed, and effective: a strong {team} display from {player}.'],
            'match_result' => ['Full-time: {team} {score} in the {competition}. Every contribution counted.'],
            'match_red_card' => ['We will review the moment and respond together. The team remains united around {player}.'],
            'transfer' => ['{team} is delighted to welcome {player} to the squad.'],
            'free_agent_signing' => ['{team} welcomes new signing {player} to the next chapter.'],
            'award' => ['Congratulations to {player} on {headline}. A proud day for {team}.'],
            'honour' => ['Our history grows with {player}: {headline}.'],
            'record' => ['Another Club-era marker for {player}: {headline}.'],
            'milestone' => ['A milestone worth celebrating for {player}: {headline}.'],
            'retirement' => ['The Club thanks {player} for a completed playing Career and the memories created here.'],
            'injury' => ['The Club is supporting {player} through the recorded injury.'],
            'return' => ['The Club welcomes {player} back after the recorded injury.'],
        ],
        'competition' => [
            'match_goal' => ['{player} added a decisive attacking moment to the {competition}.', 'The {competition} spotlight found {player} tonight.'],
            'match_decisive_goal' => ['{player} owns a defining {competition} moment as {team} advance.'],
            'match_major_contribution' => ['A significant {competition} performance from {player}.'],
            'match_strong_performance' => ['The {competition} record notes a strong showing from {player}.'],
            'match_result' => ['{team} record a {score} result in the {competition}.'],
            'award' => ['The {competition} recognises {player}: {headline}.'],
            'honour' => ['A new {competition} chapter for {player}: {headline}.'],
            'record' => ['The {competition} record now includes {headline} for {player}.'],
        ],
        'national' => [
            'match_goal' => ['A national-team moment for {player}: {score} in the {competition}.'],
            'match_assist' => ['{player} created a valuable moment for the national team in the {competition}.'],
            'match_major_contribution' => ['The national-team story includes a major contribution from {player}.'],
            'match_strong_performance' => ['A composed international performance from {player}.'],
            'match_result' => ['The national team finish the {competition} match {score}.'],
            'award' => ['International football celebrates {player}: {headline}.'],
            'honour' => ['A national achievement for {player}: {headline}.'],
            'milestone' => ['A new international milestone for {player}: {headline}.'],
            'injury' => ['The national-team picture will wait for {player} to recover.'],
            'return' => ['{player} is back in the international conversation after recovery.'],
        ],
        'teammate' => [
            'match_goal' => ['That is the {player} we see every day. Brilliant finish.'],
            'match_assist' => ['You made that chance, {player}. Great vision.'],
            'match_decisive_goal' => ['Big moment, {player}. We are through together.'],
            'match_major_contribution' => ['That was a proper team performance from {player}.'],
            'award' => ['Fully deserved, {player}. We know the work behind it.'],
            'honour' => ['What a memory for the group, {player}.'],
            'milestone' => ['Congratulations, {player}. More to come.'],
        ],
        'rival' => [
            'match_goal' => ['Good finish, {player}. We will remember the next duel.'],
            'match_decisive_goal' => ['You got the decisive moment today, {player}. The rivalry continues.'],
            'match_major_contribution' => ['Credit where it is due: {player} made the difference.'],
            'match_red_card' => ['A difficult moment for {player}; football will answer on the pitch.'],
        ],
        'player' => [
            'victory' => ['Proud of the team tonight. Thank you for the support.'],
            'defeat' => ['We take responsibility, learn, and keep working together.'],
            'transfer' => ['Thank you to the Club and supporters. I am ready for the next chapter.'],
            'red_card' => ['I accept responsibility for the dismissal. I will respond on the pitch.'],
            'achievement' => ['Honoured by this recognition. This belongs to the team and everyone who helped me.'],
        ],
    ];

    public function __construct(private readonly EchoService $echo = new EchoService()) {}

    public function initializeSchema(DatabaseInterface $database): void
    {
        SchemaInitializationGuard::run($database->connection(), self::class, function () use ($database): void {
            $connection = $database->connection();
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::SOURCES . ' (source_key TEXT PRIMARY KEY, player_id TEXT NOT NULL, occurred_date TEXT NOT NULL, kind TEXT NOT NULL, importance TEXT NOT NULL, context_json TEXT NOT NULL)');
            $connection->exec('CREATE INDEX IF NOT EXISTS idx_pulse_sources_player_date ON ' . self::SOURCES . ' (player_id, occurred_date DESC, source_key DESC)');
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::POSTS . ' (id TEXT PRIMARY KEY, player_id TEXT NOT NULL, source_key TEXT NOT NULL, actor_type TEXT NOT NULL, actor_id TEXT NOT NULL, actor_name TEXT NOT NULL, occurred_date TEXT NOT NULL, post_text TEXT NOT NULL, engagement INTEGER NOT NULL DEFAULT 0, UNIQUE (source_key, actor_type, actor_id))');
            $connection->exec('CREATE INDEX IF NOT EXISTS idx_pulse_posts_player_date ON ' . self::POSTS . ' (player_id, occurred_date DESC, id DESC)');
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::AUDIENCE . ' (player_id TEXT PRIMARY KEY, audience_score INTEGER NOT NULL DEFAULT 0, updated_date TEXT NOT NULL)');
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::RESPONSES . ' (source_key TEXT PRIMARY KEY, player_id TEXT NOT NULL, status TEXT NOT NULL, choices_json TEXT NOT NULL, selected_id TEXT NULL, response_text TEXT NULL, created_date TEXT NOT NULL, resolved_date TEXT NULL)');
            $connection->exec('CREATE INDEX IF NOT EXISTS idx_pulse_responses_player_status ON ' . self::RESPONSES . ' (player_id, status, created_date DESC)');
        });
    }

    public function initializePlayer(DatabaseInterface $database, PlayerId|string $playerId, SimulationDate $date, int $startingScore = 4): void
    {
        $this->initializeSchema($database);
        $statement = $database->connection()->prepare('INSERT OR IGNORE INTO ' . self::AUDIENCE . ' (player_id, audience_score, updated_date) VALUES (:player_id, :score, :date)');
        $statement->execute(['player_id' => $this->id($playerId), 'score' => max(0, min(100, $startingScore)), 'date' => $date->toIsoString()]);
    }

    /** @return array<string, mixed> */
    public function context(DatabaseInterface $database, PlayerId|string $playerId): array
    {
        $id = $this->id($playerId);
        $score = null;
        if ($this->available($database, self::AUDIENCE)) {
            $statement = $database->connection()->prepare('SELECT audience_score FROM ' . self::AUDIENCE . ' WHERE player_id = :player_id');
            $statement->execute(['player_id' => $id]);
            $value = $statement->fetchColumn();
            $score = $value === false ? null : (int) $value;
        }
        if ($score === null) {
            $score = $this->fallbackAudienceScore($database, $id);
        }
        $score = max(0, min(100, $score));

        return ['audience_score' => $score, 'audience_band' => $this->audienceBand($score), 'followers' => $this->followers($score), 'followers_label' => $this->followersLabel($this->followers($score))];
    }

    /** @return list<array<string, mixed>> */
    public function feed(DatabaseInterface $database, PlayerId|string $playerId, int $limit = 30): array
    {
        if (!$this->available($database, self::POSTS)) {
            return [];
        }
        $statement = $database->connection()->prepare('SELECT p.*, s.kind, s.importance, s.context_json FROM ' . self::POSTS . ' p JOIN ' . self::SOURCES . ' s ON s.source_key = p.source_key WHERE p.player_id = :player_id ORDER BY p.occurred_date DESC, p.id DESC LIMIT :limit');
        $statement->bindValue(':player_id', $this->id($playerId));
        $statement->bindValue(':limit', max(1, min(self::MAX_SOURCES, $limit)), PDO::PARAM_INT);
        $statement->execute();

        return array_map(static function (array $row): array {
            $context = json_decode((string) $row['context_json'], true);
            return ['id' => (string) $row['id'], 'source_key' => (string) $row['source_key'], 'date' => (string) $row['occurred_date'], 'actor_type' => (string) $row['actor_type'], 'actor_name' => (string) $row['actor_name'], 'text' => (string) $row['post_text'], 'engagement' => (int) $row['engagement'], 'kind' => (string) $row['kind'], 'importance' => (string) $row['importance'], 'context' => is_array($context) ? $context : []];
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function pendingResponse(DatabaseInterface $database, PlayerId|string $playerId): ?array
    {
        if (!$this->available($database, self::RESPONSES)) {
            return null;
        }
        $statement = $database->connection()->prepare('SELECT * FROM ' . self::RESPONSES . ' WHERE player_id = :player_id AND status = :status ORDER BY created_date ASC, source_key ASC LIMIT 1');
        $statement->execute(['player_id' => $this->id($playerId), 'status' => 'pending']);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $choices = json_decode((string) $row['choices_json'], true);

        return ['source_key' => (string) $row['source_key'], 'status' => (string) $row['status'], 'created_date' => (string) $row['created_date'], 'choices' => is_array($choices) ? $choices : []];
    }

    /** Resolve one curated response exactly once. */
    public function respond(DatabaseInterface $database, PlayerId|string $playerId, string $sourceKey, string $choiceId, SimulationDate $date): bool
    {
        if (!$this->available($database, self::RESPONSES)) {
            return false;
        }
        $id = $this->id($playerId);
        return $database->transaction(function () use ($database, $id, $sourceKey, $choiceId, $date): bool {
            $query = $database->connection()->prepare('SELECT * FROM ' . self::RESPONSES . ' WHERE source_key = :source_key AND player_id = :player_id');
            $query->execute(['source_key' => $sourceKey, 'player_id' => $id]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (string) $row['status'] !== 'pending') {
                return false;
            }
            $choices = json_decode((string) $row['choices_json'], true);
            $selected = null;
            foreach (is_array($choices) ? $choices : [] as $choice) {
                if (is_array($choice) && (string) ($choice['id'] ?? '') === $choiceId) { $selected = $choice; break; }
            }
            if (!is_array($selected)) {
                return false;
            }
            $text = (string) ($selected['text'] ?? 'I will let the football do the talking.');
            $update = $database->connection()->prepare('UPDATE ' . self::RESPONSES . ' SET status = :status, selected_id = :selected_id, response_text = :response_text, resolved_date = :resolved_date WHERE source_key = :source_key AND player_id = :player_id AND status = :pending');
            $update->execute(['status' => 'resolved', 'selected_id' => $choiceId, 'response_text' => $text, 'resolved_date' => $date->toIsoString(), 'source_key' => $sourceKey, 'player_id' => $id, 'pending' => 'pending']);
            if ($update->rowCount() === 0) { return false; }
            $playerName = $this->playerName($database, $id);
            $this->insertPost($database, $id, 'response|' . $sourceKey . '|' . $choiceId, $sourceKey, 'player', $id, $playerName, $date, $text, $this->engagement($database, $id, 'notable', 'player'));

            return true;
        });
    }

    public function recordMatch(DatabaseInterface $database, GameMatch $match, PlayerMatchStat $stat): void
    {
        $this->initializeSchema($database);
        $database->transaction(function () use ($database, $match, $stat): void {
            $this->recordMatchInTransaction($database, $match, $stat);
        });
    }

    public function recordMatchInTransaction(DatabaseInterface $database, GameMatch $match, PlayerMatchStat $stat): void
    {
        if (!$stat->appeared()) { return; }
        $story = (new MatchStoryService())->playerStory($database, $match, $stat->playerId());
        $result = $match->result();
        $home = $result?->homeGoals() ?? 0;
        $away = $result?->awayGoals() ?? 0;
        $for = $stat->clubId()->value() === $match->homeClubId()->value() ? $home : $away;
        $against = $stat->clubId()->value() === $match->homeClubId()->value() ? $away : $home;
        $competition = $this->competitionName($database, $match->competitionId()->value());
        $type = $this->competitionType($database, $match->competitionId()->value());
        $facts = ['appeared' => true, 'goals' => $stat->goals(), 'assists' => $stat->assists(), 'red_cards' => $stat->redCards(), 'rating' => $story['rating'], 'saves' => $stat->saves(), 'tackles' => $stat->tackles(), 'interceptions' => $stat->interceptions(), 'blocks' => $stat->blocks(), 'player_of_match' => ($story['player_of_match'] ?? false) === true, 'decisive' => is_array($story['decisive_contribution'] ?? null), 'important_match' => $type !== 'domestic_league' || $match->round() >= 3, 'result' => $for > $against ? 'win' : ($for === $against ? 'draw' : 'loss')];
        $route = $this->echo->match($facts);
        if ($route === null) { return; }
        $playerId = $stat->playerId()->value();
        $team = $this->clubName($database, $stat->clubId()->value());
        $opponentId = $stat->clubId()->value() === $match->homeClubId()->value() ? $match->awayClubId()->value() : $match->homeClubId()->value();
        $opponent = $this->clubName($database, $opponentId);
        $context = ['player' => $this->playerName($database, $playerId), 'team' => $team, 'opponent' => $opponent, 'competition' => $competition, 'competition_type' => $type, 'score' => $home . '-' . $away, 'result' => $facts['result'], 'goals' => $stat->goals(), 'assists' => $stat->assists(), 'rating' => $story['rating'], 'minutes' => $stat->minutes(), 'saves' => $stat->saves(), 'red_cards' => $stat->redCards(), 'source_match_id' => $match->id()->value()];
        $actors = [['type' => 'fan', 'id' => 'supporters:' . $stat->clubId()->value(), 'name' => 'Supporters', 'kind' => $route['kind']]];
        if (in_array($route['kind'], ['match_goal', 'match_assist', 'match_decisive_goal', 'match_major_contribution', 'match_strong_performance', 'match_red_card'], true)) {
            $actors[] = ['type' => 'media', 'id' => 'media:matchday-desk', 'name' => 'Matchday Desk', 'kind' => $route['kind']];
        }
        if ($route['importance'] !== 'routine') {
            $actors[] = ['type' => 'competition', 'id' => 'competition:' . $match->competitionId()->value(), 'name' => $competition, 'kind' => $route['kind']];
            $actors[] = ['type' => $type === 'international' ? 'national' : 'club', 'id' => $type === 'international' ? 'national:' . $stat->clubId()->value() : 'club:' . $stat->clubId()->value(), 'name' => $type === 'international' ? 'National Team' : $team, 'kind' => $route['kind']];
        }
        $related = $this->relatedPlayer($database, $match, $stat, $route['kind']);
        if ($related !== null) {
            $actors[] = ['type' => $related['type'], 'id' => 'player:' . $related['id'], 'name' => $related['name'], 'kind' => $route['kind']];
        }
        $this->recordSourceInTransaction($database, $playerId, 'match:' . $match->id()->value() . ':player:' . $playerId, $match->scheduledDate(), (string) $route['kind'], (string) $route['importance'], $context, $actors, $route['response'] === null ? null : $this->responseChoices((string) $route['response']));
    }

    public function recordTransfer(DatabaseInterface $database, PlayerId|string $playerId, ?string $oldClubId, ?string $newClubId, SimulationDate $date, string $kind = 'transfer'): void
    {
        $this->initializeSchema($database);
        $route = $this->echo->transfer($kind, $oldClubId === null || $oldClubId === '');
        if ($route['importance'] === 'routine') {
            return;
        }
        if ($route['kind'] === 'contract_event') {
            $route['kind'] = 'transfer';
        }
        $id = $this->id($playerId);
        $database->transaction(function () use ($database, $id, $oldClubId, $newClubId, $date, $route): void {
            $old = $oldClubId === null || $oldClubId === '' ? 'free agency' : $this->clubName($database, $oldClubId);
            $new = $newClubId === null || $newClubId === '' ? 'free agency' : $this->clubName($database, $newClubId);
            $context = ['player' => $this->playerName($database, $id), 'from_club' => $old, 'team' => $new, 'headline' => $route['kind'] === 'transfer_request' ? 'a transfer request' : 'a new Club chapter'];
            $actors = [['type' => 'media', 'id' => 'media:market-desk', 'name' => 'Market Desk', 'kind' => $route['kind']]];
            if ($newClubId !== null && $newClubId !== '') { $actors[] = ['type' => 'club', 'id' => 'club:' . $newClubId, 'name' => $new, 'kind' => $route['kind']]; }
            $this->recordSourceInTransaction($database, $id, 'transfer:' . $route['kind'] . ':' . $id . ':' . $date->toIsoString(), $date, (string) $route['kind'], (string) $route['importance'], $context, $actors, $route['response'] === null ? null : $this->responseChoices('transfer'));
        });
    }

    /** @param array<string, mixed> $facts */
    public function recordAchievement(DatabaseInterface $database, PlayerId|string $playerId, SimulationDate $date, string $source, string $headline, string $importance = 'major', ?string $clubId = null): void
    {
        $this->initializeSchema($database);
        $database->transaction(function () use ($database, $playerId, $date, $source, $headline, $importance, $clubId): void {
            $this->recordAchievementInTransaction($database, $playerId, $date, $source, $headline, $importance, $clubId);
        });
    }

    public function recordAchievementInTransaction(DatabaseInterface $database, PlayerId|string $playerId, SimulationDate $date, string $source, string $headline, string $importance = 'major', ?string $clubId = null): void
    {
        $id = $this->id($playerId);
        $route = $this->echo->achievement(['source' => $source, 'headline' => $headline, 'importance' => $importance]);
        $context = ['player' => $this->playerName($database, $id), 'headline' => $headline, 'team' => $clubId === null ? 'the national team' : $this->clubName($database, $clubId)];
        $international = str_contains(strtolower($source), 'international');
        $actors = [['type' => 'fan', 'id' => 'supporters:achievement', 'name' => 'Supporters', 'kind' => $route['kind']], ['type' => 'media', 'id' => 'media:football-desk', 'name' => 'Football Desk', 'kind' => $route['kind']]];
        if ($international) { $actors[] = ['type' => 'national', 'id' => 'national:achievement', 'name' => 'National Team', 'kind' => $route['kind']]; }
        if ($clubId !== null && $clubId !== '') { $actors[] = ['type' => 'club', 'id' => 'club:' . $clubId, 'name' => $this->clubName($database, $clubId), 'kind' => $route['kind']]; }
        $responseContext = $route['kind'] === 'retirement' ? 'retirement' : 'achievement';
        $this->recordSourceInTransaction($database, $id, 'achievement:' . $source, $date, (string) $route['kind'], (string) $route['importance'], $context, $actors, $this->responseChoices($responseContext));
    }

    /** @param array<string, mixed> $payload */
    public function recordAvailabilityChange(DatabaseInterface $database, array $payload, string $event): void
    {
        $playerId = (string) ($payload['player_id'] ?? '');
        $dateValue = (string) ($event === 'player.recovered' ? ($payload['actual_recovery_date'] ?? '') : ($payload['start_date'] ?? ''));
        $injuryId = (string) ($payload['id'] ?? '');
        if ($playerId === '' || $dateValue === '' || $injuryId === '') { return; }
        $route = $this->echo->availability($event);
        if ($route['importance'] === 'routine') { return; }
        $this->initializeSchema($database);
        $date = SimulationDate::fromIsoString($dateValue);
        $database->transaction(function () use ($database, $playerId, $date, $injuryId, $route, $payload, $event): void {
            $clubId = $this->currentClub($database, $playerId);
            $team = $clubId === null ? 'the football world' : $this->clubName($database, $clubId);
            $context = ['player' => $this->playerName($database, $playerId), 'team' => $team, 'headline' => $route['kind'] === 'injury' ? 'an injury' : 'a return from injury', 'injury' => (string) ($payload['category'] ?? 'recorded injury')];
            $actors = [['type' => 'fan', 'id' => 'supporters:' . ($clubId ?? 'football'), 'name' => 'Supporters', 'kind' => $route['kind']], ['type' => 'media', 'id' => 'media:availability-desk', 'name' => 'Football Desk', 'kind' => $route['kind']]];
            if ($clubId !== null) { $actors[] = ['type' => 'club', 'id' => 'club:' . $clubId, 'name' => $team, 'kind' => $route['kind']]; }
            $this->recordSourceInTransaction($database, $playerId, 'availability:' . $event . ':' . $injuryId, $date, (string) $route['kind'], (string) $route['importance'], $context, $actors, null);
        });
    }

    /** @param array<string, mixed> $choice */
    public function recordCareerChoiceInTransaction(DatabaseInterface $database, PlayerId|string $playerId, SimulationDate $date, string $source, string $category, bool $newsworthy, array $choice): void
    {
        $route = $this->echo->careerChoice($category, $newsworthy);
        if ($route['importance'] === 'routine' && !($choice['social']['history'] ?? false)) { return; }
        $id = $this->id($playerId);
        $context = ['player' => $this->playerName($database, $id), 'headline' => (string) ($choice['history'] ?? 'A Career choice changed the football context.')];
        $this->recordSourceInTransaction($database, $id, 'career-choice:' . $source, $date, 'career_choice', (string) $route['importance'], $context, [['type' => 'teammate', 'id' => 'teammates:career', 'name' => 'Teammates', 'kind' => 'career_choice']], null);
    }

    /** @return array<string, bool|int> */
    public function integrity(DatabaseInterface $database, PlayerId|string $playerId): array
    {
        if (!$this->available($database, self::SOURCES) || !$this->available($database, self::POSTS) || !$this->available($database, self::RESPONSES)) {
            return ['valid' => true, 'sources' => 0, 'posts' => 0, 'retention' => true, 'pending' => 0, 'invalid_actors' => 0];
        }
        $id = $this->id($playerId);
        $sources = $database->connection()->prepare('SELECT COUNT(*) FROM ' . self::SOURCES . ' WHERE player_id = :player_id'); $sources->execute(['player_id' => $id]);
        $posts = $database->connection()->prepare('SELECT COUNT(*) FROM ' . self::POSTS . ' WHERE player_id = :player_id'); $posts->execute(['player_id' => $id]);
        $pending = $database->connection()->prepare('SELECT COUNT(*) FROM ' . self::RESPONSES . ' WHERE player_id = :player_id AND status = :status'); $pending->execute(['player_id' => $id, 'status' => 'pending']);
        $orphan = $database->connection()->prepare('SELECT COUNT(*) FROM ' . self::POSTS . ' p LEFT JOIN ' . self::SOURCES . ' s ON s.source_key = p.source_key WHERE p.player_id = :player_id AND s.source_key IS NULL'); $orphan->execute(['player_id' => $id]);
        $sourceCount = (int) $sources->fetchColumn(); $postCount = (int) $posts->fetchColumn(); $pendingCount = (int) $pending->fetchColumn();
        $invalidActor = 0;
        if ($this->available($database, 'player_records')) {
            $actorCheck = $database->connection()->prepare("SELECT COUNT(*) FROM " . self::POSTS . " p LEFT JOIN player_records players ON players.id = CASE WHEN p.actor_type = 'player' THEN p.actor_id ELSE substr(p.actor_id, 8) END WHERE p.player_id = :player_id AND p.actor_type IN ('player', 'teammate', 'rival') AND players.id IS NULL");
            $actorCheck->execute(['player_id' => $id]);
            $invalidActor = (int) $actorCheck->fetchColumn();
        }

        return ['valid' => $sourceCount <= self::MAX_SOURCES && $pendingCount <= 1 && (int) $orphan->fetchColumn() === 0 && $invalidActor === 0, 'sources' => $sourceCount, 'posts' => $postCount, 'retention' => $sourceCount <= self::MAX_SOURCES, 'pending' => $pendingCount, 'invalid_actors' => $invalidActor];
    }

    /** @return array<string, int> */
    public function templateCounts(): array
    {
        $counts = [];
        foreach (self::TEMPLATES as $type => $groups) { $counts[$type] = array_sum(array_map('count', $groups)); }

        return $counts;
    }

    private function recordSourceInTransaction(DatabaseInterface $database, string $playerId, string $sourceKey, SimulationDate $date, string $kind, string $importance, array $context, array $actors, ?array $choices): void
    {
        $marker = $database->connection()->prepare('INSERT OR IGNORE INTO ' . self::SOURCES . ' (source_key, player_id, occurred_date, kind, importance, context_json) VALUES (:source_key, :player_id, :date, :kind, :importance, :context)');
        $marker->execute(['source_key' => $sourceKey, 'player_id' => $playerId, 'date' => $date->toIsoString(), 'kind' => $kind, 'importance' => $importance, 'context' => json_encode($context, JSON_THROW_ON_ERROR)]);
        if ($marker->rowCount() === 0) { return; }
        $audience = $this->audienceScore($database, $playerId);
        $this->writeAudience($database, $playerId, $this->nextAudience($audience, $importance, $kind), $date);
        foreach ($actors as $actor) {
            if (!is_array($actor)) { continue; }
            $this->insertPost($database, $playerId, 'post|' . $sourceKey . '|' . (string) ($actor['type'] ?? '') . '|' . (string) ($actor['id'] ?? ''), $sourceKey, (string) ($actor['type'] ?? 'fan'), (string) ($actor['id'] ?? 'unknown'), (string) ($actor['name'] ?? 'Football world'), $date, $this->render((string) ($actor['kind'] ?? $kind), (string) ($actor['type'] ?? 'fan'), $context, $sourceKey), $this->engagement($database, $playerId, $importance, (string) ($actor['type'] ?? 'fan')));
        }
        if ($choices !== null && $this->pendingResponse($database, $playerId) === null) {
            $statement = $database->connection()->prepare('INSERT OR IGNORE INTO ' . self::RESPONSES . ' (source_key, player_id, status, choices_json, created_date) VALUES (:source_key, :player_id, :status, :choices, :date)');
            $statement->execute(['source_key' => $sourceKey, 'player_id' => $playerId, 'status' => 'pending', 'choices' => json_encode($choices, JSON_THROW_ON_ERROR), 'date' => $date->toIsoString()]);
        }
        $this->prune($database, $playerId);
    }

    /** @param array<string, mixed> $context */
    private function insertPost(DatabaseInterface $database, string $playerId, string $id, string $sourceKey, string $actorType, string $actorId, string $actorName, SimulationDate $date, string $text, int $engagement): void
    {
        $statement = $database->connection()->prepare('INSERT OR IGNORE INTO ' . self::POSTS . ' (id, player_id, source_key, actor_type, actor_id, actor_name, occurred_date, post_text, engagement) VALUES (:id, :player_id, :source_key, :actor_type, :actor_id, :actor_name, :date, :text, :engagement)');
        $statement->execute(['id' => hash('sha256', $id), 'player_id' => $playerId, 'source_key' => $sourceKey, 'actor_type' => $actorType, 'actor_id' => $actorId, 'actor_name' => $actorName, 'date' => $date->toIsoString(), 'text' => $text, 'engagement' => max(0, $engagement)]);
    }

    /** @param array<string, mixed> $context */
    private function render(string $kind, string $actorType, array $context, string $sourceKey): string
    {
        $templates = self::TEMPLATES[$actorType] ?? self::TEMPLATES['fan'];
        $options = $templates[$kind] ?? $templates['match_major_contribution'] ?? $templates['career_choice'] ?? self::TEMPLATES['fan']['career_choice'];
        $index = hexdec(substr(hash('sha256', 'pulse-feed:v1|' . $sourceKey . '|' . $actorType . '|' . $kind), 0, 8)) % count($options);

        return strtr($options[$index], array_map(static fn (mixed $value): string => (string) $value, array_combine(array_map(static fn (string $key): string => '{' . $key . '}', array_keys($context)), array_values($context)) ?: []));
    }

    /** @return list<array{id:string,label:string,text:string}> */
    private function responseChoices(string $context): array
    {
        return match ($context) {
            'defeat' => [['id' => 'back_team', 'label' => 'Back the team', 'text' => 'We take the lesson together and keep moving.'], ['id' => 'responsibility', 'label' => 'Take responsibility', 'text' => 'I take responsibility and will work to be better next time.'], ['id' => 'silence', 'label' => 'Stay quiet', 'text' => '']],
            'red_card' => [['id' => 'responsibility', 'label' => 'Accept responsibility', 'text' => 'I accept responsibility for the dismissal.'], ['id' => 'back_team', 'label' => 'Back the team', 'text' => 'The team gave everything; we will respond together.'], ['id' => 'silence', 'label' => 'Say nothing', 'text' => '']],
            'transfer' => [['id' => 'thank_former_club', 'label' => 'Thank the former Club', 'text' => 'Thank you to everyone at my former Club for the memories.'], ['id' => 'next_chapter', 'label' => 'Embrace the next chapter', 'text' => 'Excited for the next chapter and ready to work.'], ['id' => 'professional', 'label' => 'Keep it professional', 'text' => 'Focused on football and the work ahead.'], ['id' => 'silence', 'label' => 'Say nothing', 'text' => '']],
            'retirement' => [['id' => 'thank_supporters', 'label' => 'Thank supporters', 'text' => 'Thank you to everyone who shared this playing Career with me.'], ['id' => 'thank_clubs', 'label' => 'Thank Clubs and teammates', 'text' => 'Thank you to every Club, teammate, and coach who shaped this journey.'], ['id' => 'reflect', 'label' => 'Reflect on the Career', 'text' => 'I will always be proud of the work, the people, and the football.'], ['id' => 'silence', 'label' => 'Keep it brief', 'text' => '']],
            default => [['id' => 'team_first', 'label' => 'Celebrate the team', 'text' => 'Proud of the team tonight. Thank you for the support.'], ['id' => 'supporter_first', 'label' => 'Thank supporters', 'text' => 'Thank you to the supporters for staying with us.'], ['id' => 'professional', 'label' => 'Stay focused', 'text' => 'Enjoy the moment, then back to work.'], ['id' => 'silence', 'label' => 'Say nothing', 'text' => '']],
        };
    }

    private function engagement(DatabaseInterface $database, string $playerId, string $importance, string $actorType): int
    {
        $base = 80 + ($this->audienceScore($database, $playerId) * 35) + match ($importance) { 'landmark' => 900, 'major' => 420, 'notable' => 150, default => 40 };
        $multiplier = match ($actorType) { 'media', 'competition', 'national' => 2, 'club' => 3, 'player' => 1, default => 1 };

        return $base * $multiplier + (hexdec(substr(hash('sha256', 'pulse-engagement:v1|' . $playerId . '|' . $importance . '|' . $actorType), 0, 4)) % 90);
    }

    private function audienceScore(DatabaseInterface $database, string $playerId): int
    {
        if (!$this->available($database, self::AUDIENCE)) { return 0; }
        $statement = $database->connection()->prepare('SELECT audience_score FROM ' . self::AUDIENCE . ' WHERE player_id = :player_id'); $statement->execute(['player_id' => $playerId]);
        $value = $statement->fetchColumn();

        return $value === false ? $this->fallbackAudienceScore($database, $playerId) : (int) $value;
    }

    private function writeAudience(DatabaseInterface $database, string $playerId, int $score, SimulationDate $date): void
    {
        $statement = $database->connection()->prepare('INSERT INTO ' . self::AUDIENCE . ' (player_id, audience_score, updated_date) VALUES (:player_id, :score, :date) ON CONFLICT(player_id) DO UPDATE SET audience_score = excluded.audience_score, updated_date = excluded.updated_date');
        $statement->execute(['player_id' => $playerId, 'score' => max(0, min(100, $score)), 'date' => $date->toIsoString()]);
    }

    private function nextAudience(int $score, string $importance, string $kind): int
    {
        $delta = match ($importance) { 'landmark' => 7, 'major' => 4, 'notable' => 2, default => 0 };
        if ($kind === 'injury') { return $score; }
        if (in_array($kind, ['match_goal', 'match_decisive_goal', 'award', 'honour', 'record'], true)) { ++$delta; }

        return min(100, $score + $delta);
    }

    private function fallbackAudienceScore(DatabaseInterface $database, string $playerId): int
    {
        if (!$this->available($database, 'player_social_states')) { return 0; }
        $statement = $database->connection()->prepare('SELECT public_profile FROM player_social_states WHERE player_id = :player_id'); $statement->execute(['player_id' => $playerId]);
        $value = $statement->fetchColumn();

        return $value === false ? 0 : max(0, min(100, intdiv((int) $value, 2)));
    }

    private function currentClub(DatabaseInterface $database, string $playerId): ?string
    {
        if ($this->available($database, 'player_social_states')) {
            $state = $database->connection()->prepare('SELECT current_club_id FROM player_social_states WHERE player_id = :player_id');
            $state->execute(['player_id' => $playerId]);
            $current = $state->fetchColumn();
            if ($current === false || $current === null || (string) $current === '') { return null; }

            return (string) $current;
        }
        if (!$this->available($database, 'club_squad_memberships')) { return null; }
        $statement = $database->connection()->prepare('SELECT club_id FROM club_squad_memberships WHERE player_id = :player_id ORDER BY season_id DESC LIMIT 1');
        $statement->execute(['player_id' => $playerId]);
        $value = $statement->fetchColumn();

        return $value === false || (string) $value === '' ? null : (string) $value;
    }

    private function audienceBand(int $score): string { return match (true) { $score < 15 => 'Local Following', $score < 35 => 'Growing Audience', $score < 60 => 'National Attention', $score < 82 => 'International Following', default => 'Global Star' }; }
    private function followers(int $score): int { return match (true) { $score < 15 => 120 + ($score * 35), $score < 35 => 700 + (($score - 15) * 140), $score < 60 => 3500 + (($score - 35) * 600), $score < 82 => 20000 + (($score - 60) * 1800), default => 65000 + (($score - 82) * 7000) }; }
    private function followersLabel(int $followers): string { return $followers >= 1000000 ? number_format($followers / 1000000, 1) . 'M' : ($followers >= 1000 ? number_format($followers / 1000, 1) . 'K' : number_format($followers)); }
    private function prune(DatabaseInterface $database, string $playerId): void
    {
        $old = $database->connection()->prepare('SELECT source_key FROM ' . self::SOURCES . ' WHERE player_id = :player_id ORDER BY occurred_date DESC, source_key DESC LIMIT -1 OFFSET ' . self::MAX_SOURCES); $old->execute(['player_id' => $playerId]); $keys = $old->fetchAll(PDO::FETCH_COLUMN);
        foreach ($keys as $key) {
            $database->connection()->prepare('DELETE FROM ' . self::POSTS . ' WHERE source_key = :source_key')->execute(['source_key' => $key]);
            $database->connection()->prepare('DELETE FROM ' . self::RESPONSES . ' WHERE source_key = :source_key')->execute(['source_key' => $key]);
            $database->connection()->prepare('DELETE FROM ' . self::SOURCES . ' WHERE source_key = :source_key')->execute(['source_key' => $key]);
        }
    }

    /** @return array{type:string,id:string,name:string}|null */
    private function relatedPlayer(DatabaseInterface $database, GameMatch $match, PlayerMatchStat $stat, string $kind): ?array
    {
        if (!in_array($kind, ['match_goal', 'match_assist', 'match_decisive_goal', 'match_major_contribution'], true)) { return null; }
        foreach ((new MatchHighlightRepository($database))->byMatch($match->id()) as $highlight) {
            $data = $highlight->data();
            $candidate = $highlight->playerId()?->value();
            if ($candidate === $stat->playerId()->value() && isset($data['assist_player_id'])) { $candidate = (string) $data['assist_player_id']; }
            if ($candidate === null || $candidate === $stat->playerId()->value()) { continue; }
            $sameClub = $highlight->clubId()?->value() === $stat->clubId()->value();
            if ($sameClub) { return ['type' => 'teammate', 'id' => $candidate, 'name' => $this->playerName($database, $candidate)]; }
            if ($kind === 'match_decisive_goal' || $kind === 'match_major_contribution') { return ['type' => 'rival', 'id' => $candidate, 'name' => $this->playerName($database, $candidate)]; }
        }

        return null;
    }

    private function playerName(DatabaseInterface $database, string $playerId): string
    {
        $statement = $database->connection()->prepare('SELECT preferred_name, first_name, last_name FROM player_records WHERE id = :id'); $statement->execute(['id' => $playerId]); $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? trim((string) (($row['preferred_name'] ?? '') ?: (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')))) : 'Player';
    }

    private function clubName(DatabaseInterface $database, string $clubId): string
    {
        $statement = $database->connection()->prepare('SELECT canonical_name FROM club_records WHERE id = :id'); $statement->execute(['id' => $clubId]); $value = $statement->fetchColumn();

        return $value === false ? $clubId : (string) $value;
    }

    private function competitionName(DatabaseInterface $database, string $competitionId): string
    {
        $statement = $database->connection()->prepare('SELECT name FROM competition_records WHERE id = :id'); $statement->execute(['id' => $competitionId]); $value = $statement->fetchColumn();

        return $value === false ? 'competition' : (string) $value;
    }

    private function competitionType(DatabaseInterface $database, string $competitionId): string
    {
        $statement = $database->connection()->prepare('SELECT type FROM competition_records WHERE id = :id'); $statement->execute(['id' => $competitionId]); $value = $statement->fetchColumn();

        return $value === false ? 'domestic_league' : (string) $value;
    }

    private function available(DatabaseInterface $database, string $table): bool
    {
        $statement = $database->connection()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table"); $statement->execute(['table' => $table]);

        return $statement->fetchColumn() !== false;
    }

    private function id(PlayerId|string $id): string { return $id instanceof PlayerId ? $id->value() : $id; }
}
