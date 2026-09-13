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
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;

final class MatchSelectionService
{
    public function __construct(private readonly ClubService $clubService)
    {
    }

    /** @return list<PlayerSelection> */
    public function select(DatabaseInterface $database, GameMatch $match): array
    {
        $players = new PlayerRepository($database);
        $selections = [];
        foreach ([$match->homeClubId()->value(), $match->awayClubId()->value()] as $clubId) {
            $eligible = $this->eligiblePlayers($database, $match, $clubId, $players);
            $ranked = [];
            foreach ($eligible as $player) {
                $membership = $this->clubService->squadRepository($database)->byPlayer($player->id(), $match->seasonId());
                $role = array_values(array_filter($membership, static fn ($value): bool => $value->clubId()->value() === $clubId))[0]?->role() ?? SquadRole::Prospect;
                $ranked[] = ['player' => $player, 'score' => $role->weight() + ($player->overallRating() * 10) + $this->formBonus($database, $player->id()->value(), $match), 'tie' => hash('sha256', $match->id()->value() . '|' . $player->id()->value())];
            }
            usort($ranked, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp($a['tie'], $b['tie']));
            foreach ($ranked as $index => $entry) {
                $status = $index < 11 ? SelectionStatus::Starter : ($index < 18 ? SelectionStatus::Bench : SelectionStatus::NotSelected);
                $selections[] = new PlayerSelection($match->id(), $entry['player']->id(), new \Goal\Legacy\Modules\Club\Domain\ClubId($clubId), $status);
            }
        }

        usort($selections, static fn (PlayerSelection $a, PlayerSelection $b): int => ($a->clubId()->value() <=> $b->clubId()->value()) ?: strcmp($a->playerId()->value(), $b->playerId()->value()));

        return $selections;
    }

    /** @return list<Player> */
    public function eligiblePlayers(DatabaseInterface $database, GameMatch $match, string $clubId, PlayerRepository $players): array
    {
        new PlayerRegistrationRepository($database);
        new ContractRepository($database);
        $registeredStatement = $database->connection()->prepare('SELECT r.player_id FROM player_competition_registrations r INNER JOIN contract_records c ON c.player_id = r.player_id AND c.club_id = r.club_id WHERE r.season_id = :season_id AND r.competition_id = :competition_id AND r.club_id = :club_id AND c.status = :status ORDER BY r.player_id ASC');
        $registeredStatement->execute(['season_id' => $match->seasonId()->value(), 'competition_id' => $match->competitionId()->value(), 'club_id' => $clubId, 'status' => 'active']);
        $registered = array_fill_keys(array_map('strval', $registeredStatement->fetchAll(\PDO::FETCH_COLUMN)), true);
        $result = [];
        foreach ($this->clubService->squadRepository($database)->byClub($clubId, $match->seasonId()) as $squad) {
            if (isset($registered[$squad->playerId()->value()])) { $result[$squad->playerId()->value()] = $players->get($squad->playerId()); }
        }
        ksort($result, SORT_STRING);

        return array_values($result);
    }

    private function formBonus(DatabaseInterface $database, string $playerId, GameMatch $match): int
    {
        try {
            $statement = $database->connection()->prepare('SELECT COALESCE(AVG(evaluation_score), 0) FROM career_match_evaluations WHERE player_id = :player_id AND occurred_date < :date');
            $statement->execute(['player_id' => $playerId, 'date' => $match->scheduledDate()->toIsoString()]);
        } catch (\PDOException) { return 0; }
        return (int) round(((float) $statement->fetchColumn() - 60) * 2);
    }
}
