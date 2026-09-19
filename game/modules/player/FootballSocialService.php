<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\Domain\SimulationFidelity;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Competition\Domain\CompetitionType;
use Goal\Legacy\Modules\Competition\Persistence\CompetitionRepository;
use Goal\Legacy\Modules\Player\Domain\CareerEvent;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PDO;

/**
 * Bounded, controlled-career social context. Football systems remain the
 * source of truth; this service only records the public consequence of facts
 * already consumed by the Career.
 */
final class FootballSocialService
{
    private const STATE = 'player_social_states';
    private const CLUB = 'player_social_club_context';
    private const RELATIONSHIPS = 'player_social_relationships';
    private const HISTORY = 'player_social_history';
    private const SOURCES = 'player_social_sources';

    public function __construct(private readonly ?ClubService $clubs = null, private readonly ?PulseService $pulse = null) {}

    public function initializeSchema(DatabaseInterface $database): void
    {
        SchemaInitializationGuard::run($database->connection(), self::class, function () use ($database): void {
            $connection = $database->connection();
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::STATE . ' (player_id TEXT PRIMARY KEY, public_profile INTEGER NOT NULL DEFAULT 5, international_profile INTEGER NOT NULL DEFAULT 0, current_club_id TEXT NULL, club_standing INTEGER NOT NULL DEFAULT 10, supporter_score INTEGER NOT NULL DEFAULT 0, supporter_sentiment TEXT NOT NULL DEFAULT \'neutral\', manager_score INTEGER NOT NULL DEFAULT 50, manager_relationship TEXT NOT NULL DEFAULT \'professional\', manager_club_id TEXT NULL, updated_date TEXT NOT NULL)');
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::CLUB . ' (player_id TEXT NOT NULL, club_id TEXT NOT NULL, club_standing INTEGER NOT NULL DEFAULT 10, supporter_score INTEGER NOT NULL DEFAULT 0, supporter_sentiment TEXT NOT NULL DEFAULT \'neutral\', manager_score INTEGER NOT NULL DEFAULT 50, manager_relationship TEXT NOT NULL DEFAULT \'professional\', first_date TEXT NOT NULL, last_date TEXT NOT NULL, former INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (player_id, club_id))');
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::RELATIONSHIPS . ' (id TEXT PRIMARY KEY, player_id TEXT NOT NULL, subject_player_id TEXT NOT NULL, relationship_type TEXT NOT NULL, affinity INTEGER NOT NULL DEFAULT 0, context TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, source_key TEXT NOT NULL UNIQUE, created_date TEXT NOT NULL, updated_date TEXT NOT NULL)');
            $connection->exec('CREATE INDEX IF NOT EXISTS idx_social_relationship_player ON ' . self::RELATIONSHIPS . ' (player_id, active, updated_date)');
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::HISTORY . ' (id TEXT PRIMARY KEY, player_id TEXT NOT NULL, event_date TEXT NOT NULL, source_key TEXT NOT NULL UNIQUE, category TEXT NOT NULL, importance TEXT NOT NULL, headline TEXT NOT NULL, details TEXT NOT NULL, club_id TEXT NULL, subject_player_id TEXT NULL, visible INTEGER NOT NULL DEFAULT 1)');
            $connection->exec('CREATE INDEX IF NOT EXISTS idx_social_history_player ON ' . self::HISTORY . ' (player_id, visible, event_date DESC)');
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::SOURCES . ' (player_id TEXT NOT NULL, source_key TEXT NOT NULL, occurred_date TEXT NOT NULL, PRIMARY KEY (player_id, source_key))');
        });
        $this->pulse?->initializeSchema($database);
    }

    /** @return array<string, mixed> */
    public function context(DatabaseInterface $database, PlayerId|string $playerId): array
    {
        $id = $this->id($playerId);
        if (!$this->available($database, self::STATE)) {
            return $this->defaultContext();
        }
        $statement = $database->connection()->prepare('SELECT * FROM ' . self::STATE . ' WHERE player_id = :player_id');
        $statement->execute(['player_id' => $id->value()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return $this->defaultContext();
        }
        return $this->contextFromRow($row);
    }

    /** @return array<string, mixed> */
    public function ensureState(DatabaseInterface $database, PlayerId|string $playerId, SimulationDate $date, ?string $clubId = null, ?string $role = null): array
    {
        $id = $this->id($playerId);
        $this->initializeSchema($database);
        $existing = $this->context($database, $id);
        if ($this->stateExists($database, $id)) {
            return $existing;
        }
        $profile = match ($role) {
            'key_player' => 28,
            'regular' => 20,
            'rotation' => 12,
            default => 7,
        };
        $this->writeState($database, $id->value(), [
            'public_profile' => $profile,
            'international_profile' => 0,
            'current_club_id' => $clubId,
            'club_standing' => $role === 'key_player' ? 25 : 10,
            'supporter_score' => 0,
            'supporter_sentiment' => 'neutral',
            'manager_score' => 50,
            'manager_relationship' => 'professional',
            'manager_club_id' => $clubId,
            'updated_date' => $date->toIsoString(),
        ]);
        if ($clubId !== null && $clubId !== '') {
            $this->writeClubContext($database, $id->value(), $clubId, $date, 10, 0, 'neutral', 50, 'professional', false);
        }
        return $this->context($database, $id);
    }

    public function initializeCareer(DatabaseInterface $database, Player $player, string $clubId, string $role, SimulationDate $date): void
    {
        $this->ensureState($database, $player->id(), $date, $clubId, $role);
        $this->pulse?->initializePlayer($database, $player->id(), $date, match ($role) { 'key_player' => 28, 'regular' => 20, 'rotation' => 12, default => 7 });
    }

    public function recordMatch(DatabaseInterface $database, GameMatch $match, SimulationFidelity $fidelity): void
    {
        if ($fidelity !== SimulationFidelity::Player) {
            return;
        }
        $this->initializeSchema($database);
        $competition = (new CompetitionRepository($database))->get($match->competitionId());
        $type = $competition->type();
        $important = $type !== CompetitionType::DomesticLeague || $match->round() >= 3;
        $controlled = (new CareerPlayerRepository($database))->playerIds();
        if ($controlled === []) {
            return;
        }
        $statement = $database->connection()->prepare('SELECT * FROM match_player_stats WHERE match_id = :match_id AND player_id = :player_id');
        foreach ($controlled as $playerId) {
            $statement->execute(['match_id' => $match->id()->value(), 'player_id' => $playerId]);
            $stat = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($stat)) {
                continue;
            }
            $date = $match->scheduledDate();
            $source = 'match|' . $match->id()->value();
            $database->transaction(function () use ($database, $playerId, $source, $date, $stat, $match, $type, $important): void {
                $marker = $database->connection()->prepare('INSERT OR IGNORE INTO ' . self::SOURCES . ' (player_id, source_key, occurred_date) VALUES (:player_id, :source_key, :occurred_date)');
                $marker->execute(['player_id' => $playerId, 'source_key' => $source, 'occurred_date' => $date->toIsoString()]);
                if ($marker->rowCount() === 0) {
                    return;
                }
                $state = $this->ensureState($database, $playerId, $date, (string) $stat['club_id']);
                $rating = $this->evidenceRating($stat);
                $result = $match->result();
                $clubId = (string) $stat['club_id'];
                $for = $clubId === $match->homeClubId()->value() ? ($result?->homeGoals() ?? 0) : ($result?->awayGoals() ?? 0);
                $against = $clubId === $match->homeClubId()->value() ? ($result?->awayGoals() ?? 0) : ($result?->homeGoals() ?? 0);
                $delta = ($stat['started'] ? 1 : 0) + ($rating >= 7.5 ? 2 : 0) + ((int) $stat['goals'] * ($important ? 2 : 1)) + ((int) $stat['assists']);
                if ((int) $stat['red_cards'] > 0) { $delta -= 3; }
                if ((int) $stat['appeared'] === 1 && $rating < 5.5) { $delta--; }
                $profileDelta = min(5, max(-3, $delta + ($important ? 1 : 0)));
                $international = $type === CompetitionType::International;
                $public = $this->clamp((int) $state['public_profile'] + $profileDelta);
                $internationalProfile = $this->clamp((int) $state['international_profile'] + ($international ? max(1, $profileDelta) : 0));
                $standing = $international ? (int) $state['club_standing'] : $this->clamp((int) $state['club_standing'] + ($for > $against ? 1 : 0) + ($important && $rating >= 7.5 ? 2 : 0) - ((int) $stat['red_cards'] * 2));
                $supporter = $international ? (int) $state['supporter_score'] : $this->clampSigned((int) $state['supporter_score'] + ($for > $against ? 3 : ($for === $against ? 0 : -2)) + ($rating >= 7.5 ? 3 : 0) + ((int) $stat['goals'] * 2) - ((int) $stat['red_cards'] * 4));
                $manager = $international ? (int) $state['manager_score'] : $this->clamp((int) $state['manager_score'] + ($stat['started'] ? 2 : 0) + ($rating >= 7.0 ? 2 : 0) - ((int) $stat['red_cards'] * 3));
                $currentClubId = $international ? ($state['current_club_id'] === null ? null : (string) $state['current_club_id']) : $clubId;
                $this->writeState($database, $playerId, [
                    'public_profile' => $public, 'international_profile' => $internationalProfile,
                    'current_club_id' => $currentClubId, 'club_standing' => $standing,
                    'supporter_score' => $supporter, 'supporter_sentiment' => $this->sentiment($supporter),
                    'manager_score' => $manager, 'manager_relationship' => $this->managerRelationship($manager),
                    'manager_club_id' => $international ? $state['manager_club_id'] : $clubId, 'updated_date' => $date->toIsoString(),
                ]);
                if (!$international) { $this->writeClubContext($database, $playerId, $clubId, $date, $standing, $supporter, $this->sentiment($supporter), $manager, $this->managerRelationship($manager), false); }
                if ($important || (int) $stat['goals'] > 0 || $rating >= 8.0 || (int) $stat['red_cards'] > 0) {
                    $label = $international ? 'International' : match ($type) { CompetitionType::Continental => 'European', CompetitionType::DomesticCup => 'Cup', default => 'League' };
                    $headline = (int) $stat['goals'] > 0 ? $label . ' contribution draws attention' : ($rating >= 8.0 ? $label . ' performance earns praise' : $label . ' Match shapes the Career');
                    $this->writeHistory($database, $playerId, 'match-landmark|' . $match->id()->value(), $date, 'football', $important ? 'major' : 'notable', $headline, 'A canonical Match outcome changed the controlled Player social context.', $international ? null : $clubId);
                }
                if ($important && $type !== CompetitionType::DomesticLeague) {
                    $opponentClub = $clubId === $match->homeClubId()->value() ? $match->awayClubId()->value() : $match->homeClubId()->value();
                    $opponent = $database->connection()->prepare('SELECT player_id FROM match_player_stats WHERE match_id = :match_id AND club_id = :club_id AND appeared = 1 ORDER BY goals DESC, red_cards DESC, player_id ASC LIMIT 1');
                    $opponent->execute(['match_id' => $match->id()->value(), 'club_id' => $opponentClub]);
                    $opponentId = $opponent->fetchColumn();
                    if ($opponentId !== false) {
                        $this->addRelationship($database, $playerId, (string) $opponentId, 'rival', 'A meaningful knockout or international meeting created a football rivalry.', $date, 'rivalry|' . $match->id()->value());
                        $this->writeHistory($database, $playerId, 'rivalry-started|' . $match->id()->value(), $date, 'rivalry', 'major', 'A meaningful football rivalry begins', 'A major Match created a recurring football context.', null, (string) $opponentId);
                    }
                }
                $canonicalStat = array_values(array_filter((new PlayerMatchStatRepository($database))->byMatch($match->id()), static fn (PlayerMatchStat $candidate): bool => $candidate->playerId()->value() === $playerId))[0] ?? null;
                if ($canonicalStat !== null) {
                    $this->pulse?->recordMatchInTransaction($database, $match, $canonicalStat);
                }
            });
        }
    }

    /** Apply an idempotent social consequence while Career Event resolution is in its transaction. */
    public function applyCareerChoice(DatabaseInterface $database, CareerEvent $event, array $choice, SimulationDate $date): void
    {
        $this->initializeSchema($database);
        $playerId = $event->playerId()->value();
        $source = 'choice|' . $event->id() . '|' . (string) ($choice['id'] ?? '');
        $marker = $database->connection()->prepare('INSERT OR IGNORE INTO ' . self::SOURCES . ' (player_id, source_key, occurred_date) VALUES (:player_id, :source_key, :occurred_date)');
        $marker->execute(['player_id' => $playerId, 'source_key' => $source, 'occurred_date' => $date->toIsoString()]);
        if ($marker->rowCount() === 0) { return; }
        $state = $this->ensureState($database, $playerId, $date, (string) ($event->context()['club_id'] ?? ''));
        $social = is_array($choice['social'] ?? null) ? $choice['social'] : [];
        $this->writeState($database, $playerId, [
            'public_profile' => $this->clamp((int) $state['public_profile'] + (int) ($social['public_profile'] ?? 0)),
            'international_profile' => $this->clamp((int) $state['international_profile'] + (int) ($social['international_profile'] ?? 0)),
            'current_club_id' => $state['current_club_id'],
            'club_standing' => $this->clamp((int) $state['club_standing'] + (int) ($social['club_standing'] ?? 0)),
            'supporter_score' => $this->clampSigned((int) $state['supporter_score'] + (int) ($social['supporter_score'] ?? 0)),
            'supporter_sentiment' => $this->sentiment($this->clampSigned((int) $state['supporter_score'] + (int) ($social['supporter_score'] ?? 0))),
            'manager_score' => $this->clamp((int) $state['manager_score'] + (int) ($social['manager_score'] ?? 0)),
            'manager_relationship' => $this->managerRelationship($this->clamp((int) $state['manager_score'] + (int) ($social['manager_score'] ?? 0))),
            'manager_club_id' => $state['manager_club_id'], 'updated_date' => $date->toIsoString(),
        ]);
        $type = (string) ($social['relationship_type'] ?? '');
        if ($type !== '') {
            $subject = $this->findTeammate($database, $playerId, (string) ($state['current_club_id'] ?? ''), $type === 'competitor');
            if ($subject !== null) {
                $this->addRelationship($database, $playerId, $subject, $type, (string) ($social['relationship_context'] ?? 'A meaningful Career moment created this football relationship.'), $date, $source);
            }
        }
        if (($social['history'] ?? false) === true) {
            $this->writeHistory($database, $playerId, 'choice-history|' . $event->id(), $date, 'social', 'notable', (string) ($choice['history'] ?? 'A meaningful football relationship took shape.'), 'A Career decision changed the Player social context.', (string) ($state['current_club_id'] ?? '') ?: null);
        }
        $this->pulse?->recordCareerChoiceInTransaction($database, $event->playerId(), $date, $source, $event->category(), (bool) ($event->context()['newsworthy'] ?? false), $choice);
    }

    /** @param list<array{event:string,payload:array<string,mixed>}> $changes */
    public function recordAvailabilityChanges(DatabaseInterface $database, array $changes): void
    {
        if ($this->pulse === null || $changes === []) { return; }
        $controlled = array_fill_keys((new CareerPlayerRepository($database))->playerIds(), true);
        foreach ($changes as $change) {
            $payload = $change['payload'] ?? [];
            if (!is_array($payload) || !isset($controlled[(string) ($payload['player_id'] ?? '')])) { continue; }
            $this->pulse->recordAvailabilityChange($database, $payload, (string) ($change['event'] ?? ''));
        }
    }

    public function recordTransferRequest(DatabaseInterface $database, PlayerId|string $playerId, SimulationDate $date): void
    {
        $this->adjust($database, $playerId, $date, 'transfer-request|' . $date->toIsoString(), -4, -12, -10, 'Transfer request changes the Club conversation.');
        $this->pulse?->recordTransfer($database, $playerId, null, null, $date, 'request');
    }

    public function recordTransferWithdrawal(DatabaseInterface $database, PlayerId|string $playerId, SimulationDate $date): void
    {
        $this->adjust($database, $playerId, $date, 'transfer-withdrawal|' . $date->toIsoString(), 1, 8, 5, 'Withdrawing a transfer request begins to repair the Club relationship.');
        $this->pulse?->recordTransfer($database, $playerId, null, null, $date, 'withdrawal');
    }

    public function recordTransfer(DatabaseInterface $database, PlayerId|string $playerId, ?string $oldClubId, ?string $newClubId, SimulationDate $date): void
    {
        if ($oldClubId !== null && $oldClubId !== '' && $oldClubId === $newClubId) {
            $this->adjust($database, $playerId, $date, 'contract-stay|' . $newClubId . '|' . $date->toIsoString(), 1, 5, 5, 'A Contract decision keeps the Club relationship moving forward.');
            $this->pulse?->recordTransfer($database, $playerId, $oldClubId, $newClubId, $date, 'contract');
            return;
        }
        $id = $this->id($playerId); $state = $this->ensureState($database, $id, $date, $newClubId);
        $unattached = $newClubId === null || $newClubId === '';
        $this->writeState($database, $id->value(), ['public_profile' => $this->clamp((int) $state['public_profile'] + ($unattached ? 0 : 2)), 'international_profile' => (int) $state['international_profile'], 'current_club_id' => $unattached ? null : $newClubId, 'club_standing' => $unattached ? 0 : 10, 'supporter_score' => $unattached ? (int) $state['supporter_score'] : 0, 'supporter_sentiment' => $this->sentiment($unattached ? (int) $state['supporter_score'] : 0), 'manager_score' => $unattached ? 0 : 50, 'manager_relationship' => $unattached ? 'none' : 'professional', 'manager_club_id' => $unattached ? null : $newClubId, 'updated_date' => $date->toIsoString()]);
        if ($oldClubId !== null && $oldClubId !== '') { $this->markFormer($database, $id->value(), $oldClubId); }
        if ($newClubId !== null && $newClubId !== '') { $this->writeClubContext($database, $id->value(), $newClubId, $date, 10, 0, 'neutral', 50, 'professional', false); }
        $this->writeHistory($database, $id->value(), 'transfer-social|' . ($newClubId ?? 'free-agent') . '|' . $date->toIsoString(), $date, 'social', 'notable', 'A new Club chapter changes the public conversation', 'Transfer context is preserved without deleting prior relationships.', $newClubId);
        $this->pulse?->recordTransfer($database, $id, $oldClubId, $newClubId, $date);
    }

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
        $marker = $database->connection()->prepare('INSERT OR IGNORE INTO ' . self::SOURCES . ' (player_id, source_key, occurred_date) VALUES (:player_id, :source_key, :occurred_date)');
        $marker->execute(['player_id' => $id->value(), 'source_key' => $source, 'occurred_date' => $date->toIsoString()]);
        if ($marker->rowCount() === 0) {
            return;
        }
        $state = $this->ensureState($database, $id, $date, $clubId);
        $publicDelta = $importance === 'landmark' ? 5 : ($importance === 'major' ? 3 : 1);
        $supporterDelta = $clubId === null ? 0 : ($importance === 'landmark' ? 4 : ($importance === 'major' ? 2 : 1));
        $managerDelta = $clubId === null ? 0 : ($importance === 'landmark' ? 3 : ($importance === 'major' ? 1 : 0));
        $nextSupporter = $this->clampSigned((int) $state['supporter_score'] + $supporterDelta);
        $nextManager = $this->clamp((int) $state['manager_score'] + $managerDelta);
        $this->writeState($database, $id->value(), [
            'public_profile' => $this->clamp((int) $state['public_profile'] + $publicDelta),
            'international_profile' => (int) $state['international_profile'],
            'current_club_id' => $state['current_club_id'],
            'club_standing' => $this->clamp((int) $state['club_standing'] + $managerDelta),
            'supporter_score' => $nextSupporter,
            'supporter_sentiment' => $this->sentiment($nextSupporter),
            'manager_score' => $nextManager,
            'manager_relationship' => $this->managerRelationship($nextManager),
            'manager_club_id' => $state['manager_club_id'],
            'updated_date' => $date->toIsoString(),
        ]);
        $this->writeHistory($database, $id->value(), $source, $date, 'achievement', $importance, $headline, 'A canonical football achievement changed the public Career context.', $clubId ?? ($state['current_club_id'] === null ? null : (string) $state['current_club_id']));
        $this->pulse?->recordAchievementInTransaction($database, $id, $date, $source, $headline, $importance, $clubId ?? ($state['current_club_id'] === null ? null : (string) $state['current_club_id']));
    }

    /** @return list<array<string, mixed>> */
    public function relationships(DatabaseInterface $database, PlayerId|string $playerId): array
    {
        if (!$this->available($database, self::RELATIONSHIPS)) { return []; }
        $id = $this->id($playerId);
        $query = $database->connection()->prepare('SELECT relationships.*, players.first_name, players.last_name, players.preferred_name, players.primary_position FROM ' . self::RELATIONSHIPS . ' relationships JOIN player_records players ON players.id = relationships.subject_player_id WHERE relationships.player_id = :player_id AND relationships.active = 1 ORDER BY relationships.updated_date DESC, relationships.id ASC LIMIT 8');
        $query->execute(['player_id' => $id->value()]);
        return array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'player_id' => (string) $row['subject_player_id'], 'name' => trim((string) ($row['preferred_name'] ?: ($row['first_name'] . ' ' . $row['last_name']))), 'position' => (string) $row['primary_position'], 'type' => (string) $row['relationship_type'], 'affinity' => (int) $row['affinity'], 'context' => (string) $row['context'], 'updated_date' => (string) $row['updated_date']], $query->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    public function history(DatabaseInterface $database, PlayerId|string $playerId, int $limit = 20): array
    {
        if (!$this->available($database, self::HISTORY)) { return []; }
        $query = $database->connection()->prepare('SELECT event_date, category, importance, headline, details, club_id, subject_player_id FROM ' . self::HISTORY . ' WHERE player_id = :player_id AND visible = 1 ORDER BY event_date DESC, id DESC LIMIT :limit');
        $query->bindValue(':player_id', $this->id($playerId)->value()); $query->bindValue(':limit', max(1, $limit), PDO::PARAM_INT); $query->execute();
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function matchContext(DatabaseInterface $database, GameMatch $match, PlayerId|string $playerId): ?array
    {
        if (!$this->available($database, self::RELATIONSHIPS)) { return null; }
        $controlled = $this->id($playerId)->value();
        $opponentClub = $match->homeClubId()->value();
        $membership = $database->connection()->prepare('SELECT club_id FROM club_squad_memberships WHERE player_id = :player_id ORDER BY season_id DESC LIMIT 1');
        $membership->execute(['player_id' => $controlled]); $club = $membership->fetchColumn();
        if ($club === false) { return null; }
        $opponentClub = (string) $club === $match->homeClubId()->value() ? $match->awayClubId()->value() : $match->homeClubId()->value();
        $query = $database->connection()->prepare('SELECT r.subject_player_id, p.preferred_name, p.first_name, p.last_name FROM ' . self::RELATIONSHIPS . ' r JOIN player_records p ON p.id = r.subject_player_id JOIN club_squad_memberships s ON s.player_id = r.subject_player_id WHERE r.player_id = :player_id AND r.relationship_type = :type AND r.active = 1 AND s.club_id = :club_id ORDER BY r.updated_date DESC LIMIT 1');
        $query->execute(['player_id' => $controlled, 'type' => 'rival', 'club_id' => $opponentClub]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? ['player_id' => (string) $row['subject_player_id'], 'name' => trim((string) ($row['preferred_name'] ?: ($row['first_name'] . ' ' . $row['last_name']))), 'label' => 'Facing rival'] : null;
    }

    /** @return array<string, mixed> */
    private function defaultContext(): array { return ['public_profile' => 5, 'public_profile_label' => 'Unknown', 'international_profile' => 0, 'international_profile_label' => 'Unknown', 'current_club_id' => null, 'club_standing' => 10, 'club_standing_label' => 'New Arrival', 'supporter_score' => 0, 'supporter_sentiment' => 'Neutral', 'manager_score' => 0, 'manager_relationship' => 'No active Club manager', 'manager_club_id' => null, 'manager_identity' => null]; }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function contextFromRow(array $row): array { $profile = (int) $row['public_profile']; $international = (int) $row['international_profile']; $standing = (int) $row['club_standing']; $supporter = (int) $row['supporter_score']; $manager = (int) $row['manager_score']; $managerActive = $row['manager_club_id'] !== null && (string) $row['manager_club_id'] !== ''; return ['public_profile' => $profile, 'public_profile_label' => $this->profileLabel($profile), 'international_profile' => $international, 'international_profile_label' => $this->profileLabel($international), 'current_club_id' => $row['current_club_id'] === null ? null : (string) $row['current_club_id'], 'club_standing' => $standing, 'club_standing_label' => $this->standingLabel($standing), 'supporter_score' => $supporter, 'supporter_sentiment' => ucfirst((string) $row['supporter_sentiment']), 'manager_score' => $manager, 'manager_relationship' => $managerActive ? $this->managerRelationship($manager) : 'No active Club manager', 'manager_club_id' => $row['manager_club_id'] === null ? null : (string) $row['manager_club_id'], 'manager_identity' => $managerActive ? 'Club manager context' : null]; }
    private function writeState(DatabaseInterface $database, string $playerId, array $values): void { $statement = $database->connection()->prepare('INSERT INTO ' . self::STATE . ' (player_id, public_profile, international_profile, current_club_id, club_standing, supporter_score, supporter_sentiment, manager_score, manager_relationship, manager_club_id, updated_date) VALUES (:player_id, :public_profile, :international_profile, :current_club_id, :club_standing, :supporter_score, :supporter_sentiment, :manager_score, :manager_relationship, :manager_club_id, :updated_date) ON CONFLICT(player_id) DO UPDATE SET public_profile=excluded.public_profile, international_profile=excluded.international_profile, current_club_id=excluded.current_club_id, club_standing=excluded.club_standing, supporter_score=excluded.supporter_score, supporter_sentiment=excluded.supporter_sentiment, manager_score=excluded.manager_score, manager_relationship=excluded.manager_relationship, manager_club_id=excluded.manager_club_id, updated_date=excluded.updated_date'); $values['player_id'] = $playerId; $statement->execute($values); }
    private function writeClubContext(DatabaseInterface $database, string $playerId, string $clubId, SimulationDate $date, int $standing, int $supporter, string $sentiment, int $manager, string $relationship, bool $former): void { $statement = $database->connection()->prepare('INSERT INTO ' . self::CLUB . ' (player_id, club_id, club_standing, supporter_score, supporter_sentiment, manager_score, manager_relationship, first_date, last_date, former) VALUES (:player_id, :club_id, :club_standing, :supporter_score, :supporter_sentiment, :manager_score, :manager_relationship, :date, :date, :former) ON CONFLICT(player_id, club_id) DO UPDATE SET club_standing=excluded.club_standing, supporter_score=excluded.supporter_score, supporter_sentiment=excluded.supporter_sentiment, manager_score=excluded.manager_score, manager_relationship=excluded.manager_relationship, last_date=excluded.last_date, former=excluded.former'); $statement->execute(['player_id' => $playerId, 'club_id' => $clubId, 'club_standing' => $standing, 'supporter_score' => $supporter, 'supporter_sentiment' => $sentiment, 'manager_score' => $manager, 'manager_relationship' => $relationship, 'date' => $date->toIsoString(), 'former' => $former ? 1 : 0]); }
    private function writeHistory(DatabaseInterface $database, string $playerId, string $source, SimulationDate $date, string $category, string $importance, string $headline, string $details, ?string $clubId = null, ?string $subjectPlayerId = null): void { $statement = $database->connection()->prepare('INSERT OR IGNORE INTO ' . self::HISTORY . ' (id, player_id, event_date, source_key, category, importance, headline, details, club_id, subject_player_id, visible) VALUES (:id, :player_id, :event_date, :source_key, :category, :importance, :headline, :details, :club_id, :subject_player_id, 1)'); $statement->execute(['id' => hash('sha256', $source . '|' . $playerId), 'player_id' => $playerId, 'event_date' => $date->toIsoString(), 'source_key' => $source . '|' . $playerId, 'category' => $category, 'importance' => $importance, 'headline' => $headline, 'details' => $details, 'club_id' => $clubId, 'subject_player_id' => $subjectPlayerId]); }
    private function adjust(DatabaseInterface $database, PlayerId|string $playerId, SimulationDate $date, string $source, int $public, int $supporter, int $manager, string $headline): void { $id = $this->id($playerId); $this->initializeSchema($database); $database->transaction(function () use ($database, $id, $date, $source, $public, $supporter, $manager, $headline): void { $marker = $database->connection()->prepare('INSERT OR IGNORE INTO ' . self::SOURCES . ' (player_id, source_key, occurred_date) VALUES (:player_id, :source_key, :occurred_date)'); $marker->execute(['player_id' => $id->value(), 'source_key' => $source, 'occurred_date' => $date->toIsoString()]); if ($marker->rowCount() === 0) { return; } $state = $this->ensureState($database, $id, $date); $nextSupporter = $this->clampSigned((int) $state['supporter_score'] + $supporter); $nextManager = $this->clamp((int) $state['manager_score'] + $manager); $this->writeState($database, $id->value(), ['public_profile' => $this->clamp((int) $state['public_profile'] + $public), 'international_profile' => (int) $state['international_profile'], 'current_club_id' => $state['current_club_id'], 'club_standing' => $this->clamp((int) $state['club_standing'] + $manager), 'supporter_score' => $nextSupporter, 'supporter_sentiment' => $this->sentiment($nextSupporter), 'manager_score' => $nextManager, 'manager_relationship' => $this->managerRelationship($nextManager), 'manager_club_id' => $state['manager_club_id'], 'updated_date' => $date->toIsoString()]); $this->writeHistory($database, $id->value(), $source, $date, 'social', 'notable', $headline, 'A transfer decision changed the football social context.', $state['current_club_id'] !== null ? (string) $state['current_club_id'] : null); }); }
    private function addRelationship(DatabaseInterface $database, string $playerId, string $subject, string $type, string $context, SimulationDate $date, string $source): void { $count = (int) $database->connection()->query('SELECT COUNT(*) FROM ' . self::RELATIONSHIPS . ' WHERE player_id = ' . $database->connection()->quote($playerId) . ' AND active = 1')->fetchColumn(); if ($count >= 8) { $database->connection()->exec('UPDATE ' . self::RELATIONSHIPS . ' SET active = 0 WHERE id = (SELECT id FROM ' . self::RELATIONSHIPS . ' WHERE player_id = ' . $database->connection()->quote($playerId) . ' AND active = 1 ORDER BY updated_date ASC, id ASC LIMIT 1)'); } $statement = $database->connection()->prepare('INSERT INTO ' . self::RELATIONSHIPS . ' (id, player_id, subject_player_id, relationship_type, affinity, context, active, source_key, created_date, updated_date) VALUES (:id, :player_id, :subject_player_id, :type, :affinity, :context, 1, :source_key, :date, :date) ON CONFLICT(id) DO UPDATE SET affinity=excluded.affinity, context=excluded.context, updated_date=excluded.updated_date, active=1, source_key=excluded.source_key'); $statement->execute(['id' => hash('sha256', $playerId . '|' . $subject . '|' . $type), 'player_id' => $playerId, 'subject_player_id' => $subject, 'type' => $type, 'affinity' => $type === 'competitor' ? -15 : 30, 'context' => $context, 'source_key' => $source . '|' . $subject, 'date' => $date->toIsoString()]); }
    private function findTeammate(DatabaseInterface $database, string $playerId, string $clubId, bool $samePosition): ?string { if ($clubId === '') { return null; } $sql = 'SELECT squads.player_id FROM club_squad_memberships squads JOIN player_records players ON players.id = squads.player_id WHERE squads.club_id = :club_id AND squads.player_id <> :player_id'; if ($samePosition) { $sql .= ' AND players.primary_position = (SELECT primary_position FROM player_records WHERE id = :player_id_position)'; } $sql .= ' ORDER BY players.pace + players.shooting + players.passing + players.dribbling + players.defending + players.physicality DESC, squads.player_id ASC LIMIT 1'; $statement = $database->connection()->prepare($sql); $parameters = ['club_id' => $clubId, 'player_id' => $playerId]; if ($samePosition) { $parameters['player_id_position'] = $playerId; } $statement->execute($parameters); $value = $statement->fetchColumn(); return $value === false ? null : (string) $value; }
    private function markFormer(DatabaseInterface $database, string $playerId, string $clubId): void { $database->connection()->prepare('UPDATE ' . self::CLUB . ' SET former = 1 WHERE player_id = :player_id AND club_id = :club_id')->execute(['player_id' => $playerId, 'club_id' => $clubId]); }
    private function stateExists(DatabaseInterface $database, PlayerId $id): bool { $statement = $database->connection()->prepare('SELECT 1 FROM ' . self::STATE . ' WHERE player_id = :player_id'); $statement->execute(['player_id' => $id->value()]); return $statement->fetchColumn() !== false; }
    private function available(DatabaseInterface $database, string $table): bool { $statement = $database->connection()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table"); $statement->execute(['table' => $table]); return $statement->fetchColumn() !== false; }
    private function id(PlayerId|string $id): PlayerId { return $id instanceof PlayerId ? $id : new PlayerId($id); }
    private function clamp(int $value): int { return max(0, min(100, $value)); }
    private function clampSigned(int $value): int { return max(-100, min(100, $value)); }
    private function evidenceRating(array $stat): float { return round(min(10, max(0, 4.5 + ((int) $stat['minutes'] / 90 * 1.5) + ((int) $stat['goals'] * 1.5) + ((int) $stat['assists'] * .8) - ((int) $stat['yellow_cards'] * .2) - ((int) $stat['red_cards'] * .9))), 1); }
    private function profileLabel(int $value): string { return match (true) { $value < 15 => 'Unknown', $value < 30 => 'Prospect', $value < 50 => 'Recognized', $value < 70 => 'Established', $value < 90 => 'Star', default => 'Elite' }; }
    private function standingLabel(int $value): string { return match (true) { $value < 20 => 'New Arrival', $value < 40 => 'Recognized', $value < 70 => 'Trusted', default => 'Club Favorite' }; }
    private function sentiment(int $value): string { return match (true) { $value <= -50 => 'frustrated', $value <= -15 => 'skeptical', $value < 20 => 'neutral', $value < 60 => 'supportive', default => 'adored' }; }
    private function managerRelationship(int $value): string { return match (true) { $value < 20 => 'Strained', $value < 40 => 'Uncertain', $value < 65 => 'Professional', $value < 85 => 'Trusted', default => 'Key Relationship' }; }
}
