<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Match;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Competition\Persistence\PlayerRegistrationRepository;
use Goal\Legacy\Modules\Contract\Persistence\ContractRepository;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\PlayerSelection;
use Goal\Legacy\Modules\Match\Domain\SelectionStatus;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\AvailabilityStatus;
use Goal\Legacy\Modules\Player\PlayerAvailabilityService;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;

final class MatchSelectionService
{
    public function __construct(private readonly ClubService $clubService, private readonly ?PlayerAvailabilityService $availability = null)
    {
    }

    /** @return list<PlayerSelection> */
    public function select(DatabaseInterface $database, GameMatch $match): array
    {
        $players = new PlayerRepository($database);
        $selections = [];
        foreach ([$match->homeClubId()->value(), $match->awayClubId()->value()] as $clubId) {
            $squadMemberships = $this->clubService->squadRepository($database)->byClub($clubId, $match->seasonId());
            $eligible = $this->eligiblePlayers($database, $match, $clubId, $players, $squadMemberships);
            $roles = [];
            foreach ($squadMemberships as $membership) { $roles[$membership->playerId()->value()] = $membership->role(); }
            $ranked = [];
            foreach ($eligible as $player) {
                $assessment = ($this->availability ?? new PlayerAvailabilityService())->assess($database, $player->id(), $match->scheduledDate());
                if ($assessment->status() === AvailabilityStatus::Unavailable) {
                    $selections[] = new PlayerSelection($match->id(), $player->id(), new \Goal\Legacy\Modules\Club\Domain\ClubId($clubId), SelectionStatus::Unavailable);
                    continue;
                }
                $role = $roles[$player->id()->value()] ?? SquadRole::Prospect;
                $fatiguePenalty = $assessment->fatigue() * 4;
                $ranked[] = ['player' => $player, 'score' => $role->weight() + ($player->overallRating() * 10) + $this->formBonus($database, $player->id()->value(), $match) - $fatiguePenalty, 'tie' => hash('sha256', $match->id()->value() . '|' . $player->id()->value()), 'group' => $this->positionGroup($player)];
            }
            usort($ranked, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp($a['tie'], $b['tie']));
            $starters = $this->positionAwareStarters($ranked);
            $starterIds = array_fill_keys(array_map(static fn (array $entry): string => $entry['player']->id()->value(), $starters), true);
            $remaining = array_values(array_filter($ranked, static fn (array $entry): bool => !isset($starterIds[$entry['player']->id()->value()])));
            $bench = $this->positionAwareBench($remaining);
            $benchIds = array_fill_keys(array_map(static fn (array $entry): string => $entry['player']->id()->value(), $bench), true);
            foreach ($ranked as $entry) {
                $playerId = $entry['player']->id()->value();
                $status = isset($starterIds[$playerId]) ? SelectionStatus::Starter : (isset($benchIds[$playerId]) ? SelectionStatus::Bench : SelectionStatus::NotSelected);
                $selections[] = new PlayerSelection($match->id(), $entry['player']->id(), new \Goal\Legacy\Modules\Club\Domain\ClubId($clubId), $status);
            }
        }

        usort($selections, static fn (PlayerSelection $a, PlayerSelection $b): int => ($a->clubId()->value() <=> $b->clubId()->value()) ?: strcmp($a->playerId()->value(), $b->playerId()->value()));

        return $selections;
    }

    /** @param list<array{player:Player,score:int,tie:string,group:string}> $ranked @return list<array{player:Player,score:int,tie:string,group:string}> */
    private function positionAwareStarters(array $ranked): array
    {
        $quotas = ['goalkeeper' => 1, 'defensive' => 4, 'midfield' => 3, 'attacking' => 3];
        $selected = [];
        $selectedIds = [];
        foreach ($quotas as $group => $quota) {
            $groupCount = 0;
            foreach ($ranked as $entry) {
                $playerId = $entry['player']->id()->value();
                if ($entry['group'] !== $group || isset($selectedIds[$playerId])) {
                    continue;
                }
                $selected[] = $entry;
                $selectedIds[$playerId] = true;
                ++$groupCount;
                if ($groupCount >= $quota) {
                    break;
                }
            }
        }
        foreach ($ranked as $entry) {
            if (count($selected) >= 11) {
                break;
            }
            if (!isset($selectedIds[$entry['player']->id()->value()])) {
                $selected[] = $entry;
                $selectedIds[$entry['player']->id()->value()] = true;
            }
        }

        return $selected;
    }

    /** @param list<array{player:Player,score:int,tie:string,group:string}> $remaining @return list<array{player:Player,score:int,tie:string,group:string}> */
    private function positionAwareBench(array $remaining): array
    {
        $selected = [];
        $selectedIds = [];
        foreach (['goalkeeper', 'defensive', 'midfield', 'attacking'] as $group) {
            foreach ($remaining as $entry) {
                $playerId = $entry['player']->id()->value();
                if ($entry['group'] === $group && !isset($selectedIds[$playerId])) {
                    $selected[] = $entry;
                    $selectedIds[$playerId] = true;
                    break;
                }
            }
        }
        foreach ($remaining as $entry) {
            if (count($selected) >= 7) {
                break;
            }
            if (!isset($selectedIds[$entry['player']->id()->value()])) {
                $selected[] = $entry;
                $selectedIds[$entry['player']->id()->value()] = true;
            }
        }

        return $selected;
    }

    private function positionGroup(Player $player): string
    {
        return match ($player->primaryPosition()->value) {
            'GK' => 'goalkeeper',
            'CB', 'LB', 'RB' => 'defensive',
            'DM', 'CM', 'AM' => 'midfield',
            'LW', 'RW', 'ST' => 'attacking',
            default => 'midfield',
        };
    }

    /** @return list<Player> */
    /** @param list<\Goal\Legacy\Modules\Club\Domain\ClubSquadMembership>|null $squadMemberships */
    public function eligiblePlayers(DatabaseInterface $database, GameMatch $match, string $clubId, PlayerRepository $players, ?array $squadMemberships = null): array
    {
        new PlayerRegistrationRepository($database);
        new ContractRepository($database);
        $registeredStatement = $database->connection()->prepare("SELECT r.player_id FROM player_competition_registrations r INNER JOIN contract_records c ON c.player_id = r.player_id AND c.club_id = r.club_id INNER JOIN player_records p ON p.id = r.player_id WHERE r.season_id = :season_id AND r.competition_id = :competition_id AND r.club_id = :club_id AND c.status = :status AND p.career_state = 'active' ORDER BY r.player_id ASC");
        $registeredStatement->execute(['season_id' => $match->seasonId()->value(), 'competition_id' => $match->competitionId()->value(), 'club_id' => $clubId, 'status' => 'active']);
        $registered = array_fill_keys(array_map('strval', $registeredStatement->fetchAll(\PDO::FETCH_COLUMN)), true);
        $squadIds = [];
        foreach ($squadMemberships ?? $this->clubService->squadRepository($database)->byClub($clubId, $match->seasonId()) as $squad) {
            if (isset($registered[$squad->playerId()->value()])) { $squadIds[] = $squad->playerId(); }
        }
        $result = [];
        foreach ($players->byIds($squadIds) as $player) { $result[$player->id()->value()] = $player; }
        ksort($result, SORT_STRING);

        return array_values($result);
    }

    private function formBonus(DatabaseInterface $database, string $playerId, GameMatch $match): int
    {
        try {
            $summary = $database->connection()->prepare('SELECT COALESCE(SUM(evaluation_count), 0) AS evaluation_count, COALESCE(SUM(evaluation_total), 0) AS evaluation_total FROM player_form_summaries WHERE player_id = :player_id');
            $summary->execute(['player_id' => $playerId]);
            $archived = $summary->fetch(\PDO::FETCH_ASSOC) ?: ['evaluation_count' => 0, 'evaluation_total' => 0];
            $current = $database->connection()->prepare('SELECT COUNT(*) AS evaluation_count, COALESCE(SUM(evaluation_score), 0) AS evaluation_total FROM career_match_evaluations WHERE player_id = :player_id AND occurred_date < :date');
            $current->execute(['player_id' => $playerId, 'date' => $match->scheduledDate()->toIsoString()]);
            $live = $current->fetch(\PDO::FETCH_ASSOC) ?: ['evaluation_count' => 0, 'evaluation_total' => 0];
            $count = (int) $archived['evaluation_count'] + (int) $live['evaluation_count'];
            $total = (int) $archived['evaluation_total'] + (int) $live['evaluation_total'];
            $average = $count === 0 ? 0.0 : $total / $count;
        } catch (\PDOException) {
            $statement = $database->connection()->prepare('SELECT COALESCE(AVG(evaluation_score), 0) FROM career_match_evaluations WHERE player_id = :player_id AND occurred_date < :date');
            $statement->execute(['player_id' => $playerId, 'date' => $match->scheduledDate()->toIsoString()]);
            $average = (float) $statement->fetchColumn();
        }

        return (int) round(($average - 60) * 2);
    }
}
