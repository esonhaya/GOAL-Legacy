<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Club;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Domain\Club;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Club\Persistence\ClubSquadRepository;
use Goal\Legacy\Modules\Competition\CompetitionService;
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Competition\Persistence\PlayerRegistrationRepository;
use Goal\Legacy\Modules\Contract\ContractService;
use Goal\Legacy\Modules\Contract\Domain\Contract;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Contract\Persistence\ContractRepository;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerCareerState;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\PlayerPopulationService;
use Goal\Legacy\Modules\Transfer\Domain\Transfer;
use Goal\Legacy\Modules\Transfer\Domain\TransferExecutionTerms;
use Goal\Legacy\Modules\Transfer\Domain\TransferId;
use Goal\Legacy\Modules\Transfer\Domain\TransferStatus;
use Goal\Legacy\Modules\Transfer\Persistence\TransferRepository;
use Goal\Legacy\Modules\Transfer\TransferService;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

/** Bounded Club-owned squad maintenance at Season boundaries. */
final class ClubRecruitmentService
{
    private const MAX_NPC_TRANSFERS = 8;

    /** @var array<string, list<string>> */
    private const GROUP_POSITIONS = [
        'goalkeeper' => ['GK'],
        'defensive' => ['CB', 'LB', 'RB'],
        'midfield' => ['DM', 'CM', 'AM'],
        'attacking' => ['LW', 'RW', 'ST'],
    ];

    /** @var array<string, int> */
    private const GROUP_MINIMUMS = [
        'goalkeeper' => 1,
        'defensive' => 4,
        'midfield' => 5,
        'attacking' => 3,
    ];

    /** @var array<string, int> */
    private const GROUP_IDEALS = [
        'goalkeeper' => 3,
        'defensive' => 7,
        'midfield' => 8,
        'attacking' => 7,
    ];

    public function __construct(
        private readonly ClubService $clubService,
        private readonly ContractService $contractService,
        private readonly CompetitionService $competitionService,
        private readonly TransferService $transferService,
    ) {
    }

    /** @return array{free_agents_considered:int,free_agents_signed:int,npc_transfers:int,newgens_avoided:int,position_needs_met:int,unsuitable_candidates_rejected:int,duplicates:int} */
    public function recruit(DatabaseInterface $database, Season $season, SimulationDate $asOfDate): array
    {
        $clubs = $this->clubService->repository($database)->all();
        usort($clubs, static fn (Club $left, Club $right): int => strcmp($left->id()->value(), $right->id()->value()));
        $players = [];
        foreach ((new PlayerRepository($database))->all() as $player) {
            $players[$player->id()->value()] = $player;
        }
        $contracts = new ContractRepository($database);
        $activeContracts = [];
        foreach ($contracts->all() as $contract) {
            if ($contract->status()->value === 'active') {
                $activeContracts[$contract->playerId()->value()] = $contract;
            }
        }
        $squadRepository = new ClubSquadRepository($database);
        $squadsByClub = [];
        $squadByPlayer = [];
        foreach ($squadRepository->all() as $membership) {
            if ($membership->seasonId()->value() !== $season->id()->value()) {
                continue;
            }
            $squadsByClub[$membership->clubId()->value()][] = $membership;
            $squadByPlayer[$membership->playerId()->value()] = $membership->clubId()->value();
        }
        $careerPlayers = array_fill_keys((new CareerPlayerRepository($database))->playerIds(), true);
        $freeAgents = [];
        foreach ($players as $playerId => $player) {
            if ($player->careerState() !== PlayerCareerState::Active || isset($careerPlayers[$playerId]) || isset($activeContracts[$playerId]) || isset($squadByPlayer[$playerId])) {
                continue;
            }
            $freeAgents[$playerId] = $player;
        }

        $summary = [
            'free_agents_considered' => 0,
            'free_agents_signed' => 0,
            'npc_transfers' => 0,
            'newgens_avoided' => 0,
            'position_needs_met' => 0,
            'unsuitable_candidates_rejected' => 0,
            'duplicates' => 0,
        ];
        foreach ($clubs as $club) {
            foreach ($this->needs($club, $squadsByClub[$club->id()->value()] ?? [], $players) as $need) {
                $candidate = $this->bestFreeAgent($club, $need, $freeAgents, $summary['free_agents_considered'], $asOfDate);
                if ($candidate === null) {
                    continue;
                }
                $player = $candidate;
                $this->signFreeAgent($database, $club, $player, $season, $asOfDate, $squadsByClub, $activeContracts, $players);
                unset($freeAgents[$player->id()->value()]);
                ++$summary['free_agents_signed'];
                ++$summary['newgens_avoided'];
                ++$summary['position_needs_met'];
            }
        }

        $transferRepository = new TransferRepository($database);
        $existingTransfers = array_fill_keys(array_map(static fn (Transfer $transfer): string => $transfer->id()->value(), $transferRepository->all()), true);
        $transfers = 0;
        foreach ($clubs as $destination) {
            if ($transfers >= self::MAX_NPC_TRANSFERS) {
                break;
            }
            foreach ($this->needs($destination, $squadsByClub[$destination->id()->value()] ?? [], $players) as $need) {
                if ($transfers >= self::MAX_NPC_TRANSFERS) {
                    break 2;
                }
                $candidate = $this->bestContractedCandidate($destination, $need, $clubs, $squadsByClub, $players, $activeContracts, $careerPlayers, $existingTransfers, $asOfDate);
                if ($candidate === null) {
                    continue;
                }
                $transfer = $this->moveContractedPlayer($database, $candidate['source'], $destination, $candidate['player'], $season, $asOfDate, $squadsByClub, $activeContracts, $players);
                $existingTransfers[$transfer->id()->value()] = true;
                ++$transfers;
                ++$summary['npc_transfers'];
                ++$summary['position_needs_met'];
            }
        }

        return $summary;
    }

    /** @param list<ClubSquadMembership> $memberships @param array<string, Player> $players @return list<array{group:string,position:string}> */
    private function needs(Club $club, array $memberships, array $players): array
    {
        $counts = array_fill_keys(array_keys(self::GROUP_MINIMUMS), 0);
        $positionCounts = [];
        foreach ($memberships as $membership) {
            $player = $players[$membership->playerId()->value()] ?? null;
            if ($player === null) {
                continue;
            }
            $group = $this->positionGroup($player);
            ++$counts[$group];
            $positionCounts[$player->primaryPosition()->value] = ($positionCounts[$player->primaryPosition()->value] ?? 0) + 1;
        }
        $needs = [];
        foreach (self::GROUP_MINIMUMS as $group => $minimum) {
            while ($counts[$group] < $minimum) {
                $needs[] = ['group' => $group, 'position' => $this->neededPosition($group, $positionCounts)];
                ++$counts[$group];
            }
        }
        while (count($memberships) + count($needs) < PlayerPopulationService::TARGET_SQUAD_SIZE) {
            $group = array_key_first(self::GROUP_IDEALS);
            $ratio = INF;
            foreach (self::GROUP_IDEALS as $candidate => $ideal) {
                $candidateRatio = $counts[$candidate] / $ideal;
                if ($candidateRatio < $ratio) {
                    $ratio = $candidateRatio;
                    $group = $candidate;
                }
            }
            $needs[] = ['group' => $group, 'position' => $this->neededPosition($group, $positionCounts)];
            ++$counts[$group];
            $neededPosition = $needs[count($needs) - 1]['position'];
            $positionCounts[$neededPosition] = ($positionCounts[$neededPosition] ?? 0) + 1;
        }

        return $needs;
    }

    /** @param array<string, int> $positionCounts */
    private function neededPosition(string $group, array $positionCounts): string
    {
        $position = self::GROUP_POSITIONS[$group][0];
        $count = PHP_INT_MAX;
        foreach (self::GROUP_POSITIONS[$group] as $candidate) {
            if (($positionCounts[$candidate] ?? 0) < $count) {
                $position = $candidate;
                $count = $positionCounts[$candidate] ?? 0;
            }
        }

        return $position;
    }

    /** @param array<string, Player> $freeAgents */
    private function bestFreeAgent(Club $club, array $need, array $freeAgents, int &$considered, SimulationDate $asOfDate): ?Player
    {
        $ranked = [];
        foreach ($freeAgents as $player) {
            if ($this->positionGroup($player) !== $need['group']) {
                continue;
            }
            ++$considered;
            if ($player->overallRating() < max(35, $club->reputation() - 35)) {
                continue;
            }
            $ranked[] = [$this->fitScore($club, $player, $need['position'], false, $asOfDate), $player];
        }
        usort($ranked, static fn (array $left, array $right): int => ($right[0] <=> $left[0]) ?: strcmp($left[1]->id()->value(), $right[1]->id()->value()));

        return $ranked[0][1] ?? null;
    }

    /** @param list<Club> $clubs @param array<string, list<ClubSquadMembership>> $squadsByClub @param array<string, Player> $players @param array<string, Contract> $activeContracts @param array<string, bool> $careerPlayers @param array<string, bool> $existingTransfers @return array{source:Club,player:Player}|null */
    private function bestContractedCandidate(Club $destination, array $need, array $clubs, array $squadsByClub, array $players, array $activeContracts, array $careerPlayers, array $existingTransfers, SimulationDate $asOfDate): ?array
    {
        $ranked = [];
        foreach ($clubs as $source) {
            if ($source->id()->value() === $destination->id()->value()) {
                continue;
            }
            $sourceSquad = $squadsByClub[$source->id()->value()] ?? [];
            if (!$this->sourceCanRelease($sourceSquad, $need, $players)) {
                continue;
            }
            foreach ($sourceSquad as $membership) {
                $player = $players[$membership->playerId()->value()] ?? null;
                $contract = $activeContracts[$membership->playerId()->value()] ?? null;
                if ($player === null || $contract === null || $player->careerState() !== PlayerCareerState::Active || isset($careerPlayers[$player->id()->value()]) || $membership->role() === SquadRole::KeyPlayer || $this->positionGroup($player) !== $need['group']) {
                    continue;
                }
                $transferId = $this->transferId($player, $source, $destination, $membership->seasonId()->value());
                if (isset($existingTransfers[$transferId])) {
                    continue;
                }
                if ($player->overallRating() < max(35, $destination->reputation() - 35)) {
                    continue;
                }
                $score = $this->fitScore($destination, $player, $need['position'], true, $asOfDate) + ($membership->role() === SquadRole::Prospect ? 50 : 0) - ($source->reputation() > $destination->reputation() + 25 ? 40 : 0);
                $ranked[] = [$score, $source, $player];
            }
        }
        usort($ranked, static fn (array $left, array $right): int => ($right[0] <=> $left[0]) ?: strcmp($left[2]->id()->value(), $right[2]->id()->value()));

        return isset($ranked[0]) ? ['source' => $ranked[0][1], 'player' => $ranked[0][2]] : null;
    }

    /** @param list<ClubSquadMembership> $sourceSquad @param array{group:string,position:string} $need @param array<string, Player> $players */
    private function sourceCanRelease(array $sourceSquad, array $need, array $players): bool
    {
        if (count($sourceSquad) <= 11) {
            return false;
        }
        $counts = array_fill_keys(array_keys(self::GROUP_MINIMUMS), 0);
        foreach ($sourceSquad as $membership) {
            $player = $players[$membership->playerId()->value()] ?? null;
            if ($player !== null) {
                ++$counts[$this->positionGroup($player)];
            }
        }
        return $counts[$need['group']] > self::GROUP_MINIMUMS[$need['group']];
    }

    private function fitScore(Club $club, Player $player, string $preferredPosition, bool $contracted, SimulationDate $asOfDate): int
    {
        $exact = $player->primaryPosition()->value === $preferredPosition ? 300 : 0;
        $level = 300 - min(300, abs($player->overallRating() - $club->reputation()) * 8);
        $upside = max(0, $player->potential() - $player->overallRating()) * 3;
        $age = max(0, $player->ageAt($asOfDate) - 30) * 3;

        return $exact + ($player->overallRating() * 10) + $level + $upside - $age + ($contracted ? 20 : 0);
    }

    /** @param array<string, list<ClubSquadMembership>> $squadsByClub @param array<string, Contract> $activeContracts @param array<string, Player> $players */
    private function signFreeAgent(DatabaseInterface $database, Club $club, Player $player, Season $season, SimulationDate $asOfDate, array &$squadsByClub, array &$activeContracts, array $players): void
    {
        $role = $this->roleFor($club, $player, $squadsByClub[$club->id()->value()] ?? [], $players, $asOfDate);
        $contractId = new ContractId('recruitment-free-' . substr(hash('sha256', $season->id()->value() . '|' . $club->id()->value() . '|' . $player->id()->value()), 0, 40));
        $contract = $this->contractService->create(new ContractCreationRequest($contractId, $player->id(), $club->id(), $season->startDate(), $season->endDate()->addDays(365), $this->wage($club, $player, $role), $asOfDate));
        $squad = new ClubSquadMembership($club->id(), $player->id(), $season->id(), $role);
        $registrations = new PlayerRegistrationRepository($database);
        $memberships = $this->clubService->membershipRepository($database)->byClub($club->id());
        $database->transaction(function () use ($database, $contract, $squad, $registrations, $memberships, $season, $club): void {
            $this->contractService->repository($database)->saveInTransaction($contract);
            $this->clubService->squadRepository($database)->save($squad);
            foreach ($memberships as $membership) {
                if ($membership->seasonId()->value() === $season->id()->value()) {
                    $registrations->registerInTransaction(new PlayerRegistration($season->id(), $membership->competitionId(), $club->id(), $squad->playerId()));
                }
            }
        });
        $squadsByClub[$club->id()->value()][] = $squad;
        $activeContracts[$player->id()->value()] = $contract;
    }

    /** @param array<string, list<ClubSquadMembership>> $squadsByClub @param array<string, Contract> $activeContracts @param array<string, Player> $players */
    private function moveContractedPlayer(DatabaseInterface $database, Club $source, Club $destination, Player $player, Season $season, SimulationDate $asOfDate, array &$squadsByClub, array &$activeContracts, array $players): Transfer
    {
        $transferId = new TransferId($this->transferId($player, $source, $destination, $season->id()->value()));
        $destinationRole = $this->roleFor($destination, $player, $squadsByClub[$destination->id()->value()] ?? [], $players, $asOfDate);
        $transfer = new Transfer($transferId, $player->id(), $source->id(), $destination->id(), $season->id(), max(0, $player->overallRating() * 10000), $asOfDate, TransferStatus::Agreed);
        $this->transferService->save($database, $transfer);
        $destinationContractId = new ContractId('recruitment-transfer-' . substr(hash('sha256', $season->id()->value() . '|' . $destination->id()->value() . '|' . $player->id()->value()), 0, 40));
        $completed = $this->transferService->execute($database, $transfer, new TransferExecutionTerms($destinationContractId, $season->endDate()->addDays(730), $this->wage($destination, $player, $destinationRole), $destinationRole));
        $sourcePlayerId = $player->id()->value();
        $squadsByClub[$source->id()->value()] = array_values(array_filter($squadsByClub[$source->id()->value()] ?? [], static fn (ClubSquadMembership $membership): bool => $membership->playerId()->value() !== $sourcePlayerId));
        $squadsByClub[$destination->id()->value()][] = new ClubSquadMembership($destination->id(), $player->id(), $season->id(), $destinationRole);
        $activeContracts[$sourcePlayerId] = $this->contractService->repository($database)->get($completed->destinationContractId());

        return $completed;
    }

    /** @param list<ClubSquadMembership> $memberships @param array<string, Player> $players */
    private function roleFor(Club $club, Player $player, array $memberships, array $players, SimulationDate $asOfDate): SquadRole
    {
        $group = $this->positionGroup($player);
        $rank = 1;
        foreach ($memberships as $membership) {
            $other = $players[$membership->playerId()->value()] ?? null;
            if ($other !== null && $this->positionGroup($other) === $group && $other->overallRating() > $player->overallRating()) {
                ++$rank;
            }
        }
        if ($rank <= 2 && $player->overallRating() >= $club->reputation() - 4) {
            return SquadRole::KeyPlayer;
        }
        if ($rank <= 5) {
            return SquadRole::Regular;
        }
        if ($player->ageAt($asOfDate) <= 23 || $player->potential() - $player->overallRating() >= 10) {
            return SquadRole::Prospect;
        }

        return SquadRole::Rotation;
    }

    private function wage(Club $club, Player $player, SquadRole $role): int
    {
        return max(50, ($club->reputation() * 10) + ($player->overallRating() * 5) + $role->weight());
    }

    private function transferId(Player $player, Club $source, Club $destination, string $seasonId): string
    {
        return 'npc-recruitment-' . substr(hash('sha256', $seasonId . '|' . $source->id()->value() . '|' . $destination->id()->value() . '|' . $player->id()->value()), 0, 40);
    }

    private function positionGroup(Player $player): string
    {
        foreach (self::GROUP_POSITIONS as $group => $positions) {
            if (in_array($player->primaryPosition()->value, $positions, true)) {
                return $group;
            }
        }

        return 'midfield';
    }
}
