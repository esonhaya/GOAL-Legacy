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
    private const MAX_WORLD_MOVEMENTS = 18;
    private const MAX_MOVEMENTS_PER_CLUB = 1;
    private const MAX_CANDIDATES_PER_NEED = 48;

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

    /** @return array{free_agents_considered:int,free_agents_signed:int,npc_transfers:int,newgens_avoided:int,position_needs_met:int,unsuitable_candidates_rejected:int,duplicates:int,movement_budget:int,clubs_processed:int,candidates_evaluated:int,clubs_with_activity:int} */
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
        $usage = $this->usageByPlayer($database, $season);
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
            'movement_budget' => 0,
            'clubs_processed' => count($clubs),
            'candidates_evaluated' => 0,
            'clubs_with_activity' => 0,
        ];
        foreach ($clubs as $club) {
            foreach ($this->marketNeeds($club, $squadsByClub[$club->id()->value()] ?? [], $players) as $need) {
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
        $existingTransfers = [];
        $movedPlayers = [];
        $clubTransferCounts = [];
        foreach ($transferRepository->all() as $existingTransfer) {
            if ($existingTransfer->seasonId()->value() !== $season->id()->value() || $existingTransfer->status() !== TransferStatus::Completed) {
                continue;
            }
            $existingTransfers[$existingTransfer->id()->value()] = true;
            $movedPlayers[$existingTransfer->playerId()->value()] = true;
            $sourceId = $existingTransfer->sourceClubId()->value();
            $destinationId = $existingTransfer->destinationClubId()->value();
            $clubTransferCounts[$sourceId] = ($clubTransferCounts[$sourceId] ?? 0) + 1;
            $clubTransferCounts[$destinationId] = ($clubTransferCounts[$destinationId] ?? 0) + 1;
        }
        $needsByClub = [];
        $unresolvedNeeds = 0;
        foreach ($clubs as $club) {
            $needsByClub[$club->id()->value()] = $this->marketNeeds($club, $squadsByClub[$club->id()->value()] ?? [], $players);
            $unresolvedNeeds += count($needsByClub[$club->id()->value()]);
        }
        // Only a bounded share of unresolved structural demand enters the
        // autonomous window. Newgens and emergency repair remain responsible
        // for the rest, so vacancies cannot turn into market churn.
        $movementBudget = min(self::MAX_WORLD_MOVEMENTS, (int) ceil($unresolvedNeeds / 9) + min(2, intdiv(count($freeAgents), 20)));
        $summary['movement_budget'] = $movementBudget;
        $candidatePool = $this->candidatePool($clubs, $squadsByClub, $players, $activeContracts, $careerPlayers);
        $transfers = 0;
        foreach ($clubs as $destination) {
            if ($transfers >= $movementBudget) {
                break;
            }
            if (($clubTransferCounts[$destination->id()->value()] ?? 0) >= self::MAX_MOVEMENTS_PER_CLUB) {
                continue;
            }
            foreach ($needsByClub[$destination->id()->value()] ?? [] as $need) {
                if ($transfers >= $movementBudget) {
                    break 2;
                }
                $candidate = $this->bestContractedCandidate($destination, $need, $candidatePool, $squadsByClub, $players, $existingTransfers, $movedPlayers, $usage, $asOfDate, $summary['candidates_evaluated']);
                if ($candidate === null) {
                    continue;
                }
                $transfer = $this->moveContractedPlayer($database, $candidate['source'], $destination, $candidate['player'], $season, $asOfDate, $squadsByClub, $activeContracts, $players);
                $existingTransfers[$transfer->id()->value()] = true;
                $movedPlayers[$candidate['player']->id()->value()] = true;
                $sourceId = $candidate['source']->id()->value();
                $destinationId = $destination->id()->value();
                $clubTransferCounts[$sourceId] = ($clubTransferCounts[$sourceId] ?? 0) + 1;
                $clubTransferCounts[$destinationId] = ($clubTransferCounts[$destinationId] ?? 0) + 1;
                ++$transfers;
                ++$summary['npc_transfers'];
                ++$summary['position_needs_met'];
                break;
            }
        }

        $summary['clubs_with_activity'] = count(array_filter($clubTransferCounts, static fn (int $count): bool => $count > 0));

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

    /** @param list<ClubSquadMembership> $memberships @param array<string, Player> $players @return list<array{group:string,position:string}> */
    private function marketNeeds(Club $club, array $memberships, array $players): array
    {
        return $this->needs($club, $memberships, $players);
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

    /** @param array<string, list<array{source:Club,membership:ClubSquadMembership,player:Player}>> $candidatePool @param array<string, list<ClubSquadMembership>> $squadsByClub @param array<string, Player> $players @param array<string, bool> $existingTransfers @param array<string, bool> $movedPlayers @param array<string, array{appearances:int,starts:int,minutes:int}> $usage @return array{source:Club,player:Player}|null */
    private function bestContractedCandidate(Club $destination, array $need, array $candidatePool, array $squadsByClub, array $players, array $existingTransfers, array $movedPlayers, array $usage, SimulationDate $asOfDate, int &$candidatesEvaluated): ?array
    {
        $ranked = [];
        $needCandidates = 0;
        foreach ($candidatePool[$need['group']] ?? [] as $candidateEntry) {
            $source = $candidateEntry['source'];
            $membership = $candidateEntry['membership'];
            $player = $candidateEntry['player'];
            if ($source->id()->value() === $destination->id()->value()) {
                continue;
            }
            $sourceSquad = $squadsByClub[$source->id()->value()] ?? [];
            if (!$this->sourceCanRelease($sourceSquad, $need, $players)) {
                continue;
            }
            ++$candidatesEvaluated;
            ++$needCandidates;
            if ($needCandidates > self::MAX_CANDIDATES_PER_NEED) {
                break;
            }
            if (isset($movedPlayers[$player->id()->value()])) {
                continue;
            }
            $transferId = $this->transferId($player, $source, $destination, $membership->seasonId()->value());
            if (isset($existingTransfers[$transferId])) {
                continue;
            }
            if ($membership->role() === SquadRole::KeyPlayer && !$this->sourceHasSurplus($sourceSquad, $player, $players)) {
                continue;
            }
            if ($player->overallRating() < max(35, $destination->reputation() - 35)) {
                continue;
            }
            $pressure = $this->movementPressure($source, $player, $membership, $sourceSquad, $players, $usage, $asOfDate);
            if ($pressure < 35) {
                continue;
            }
            $willingness = $this->playerWillingness($source, $destination, $player, $membership, $need, $squadsByClub[$destination->id()->value()] ?? [], $players, $asOfDate);
            if ($willingness < 35) {
                continue;
            }
            $score = $this->fitScore($destination, $player, $need['position'], true, $asOfDate) + $pressure + $willingness - ($source->reputation() > $destination->reputation() + 25 ? 40 : 0);
            $ranked[] = [$score, $source, $player];
        }
        usort($ranked, static fn (array $left, array $right): int => ($right[0] <=> $left[0]) ?: strcmp($left[2]->id()->value(), $right[2]->id()->value()));

        return isset($ranked[0]) ? ['source' => $ranked[0][1], 'player' => $ranked[0][2]] : null;
    }

    /** @param list<Club> $clubs @param array<string, list<ClubSquadMembership>> $squadsByClub @param array<string, Player> $players @param array<string, Contract> $activeContracts @param array<string, bool> $careerPlayers @return array<string, list<array{source:Club,membership:ClubSquadMembership,player:Player}>> */
    private function candidatePool(array $clubs, array $squadsByClub, array $players, array $activeContracts, array $careerPlayers): array
    {
        $pool = [];
        foreach ($clubs as $source) {
            $sourceEntries = [];
            foreach ($squadsByClub[$source->id()->value()] ?? [] as $membership) {
                $player = $players[$membership->playerId()->value()] ?? null;
                if ($player === null || !isset($activeContracts[$player->id()->value()]) || $player->careerState() !== PlayerCareerState::Active || isset($careerPlayers[$player->id()->value()])) {
                    continue;
                }
                $sourceEntries[$this->positionGroup($player)][] = ['source' => $source, 'membership' => $membership, 'player' => $player];
            }
            foreach ($sourceEntries as $group => $entries) {
                usort($entries, static fn (array $left, array $right): int => ($right['player']->overallRating() <=> $left['player']->overallRating()) ?: strcmp($left['player']->id()->value(), $right['player']->id()->value()));
                foreach (array_slice($entries, 0, 2) as $entry) {
                    $pool[$group][] = $entry;
                }
            }
        }

        return $pool;
    }

    /** @param list<ClubSquadMembership> $sourceSquad @param array<string, Player> $players */
    private function sourceHasSurplus(array $sourceSquad, Player $candidate, array $players): bool
    {
        $sameGroup = 0;
        foreach ($sourceSquad as $membership) {
            $player = $players[$membership->playerId()->value()] ?? null;
            if ($player !== null && $this->positionGroup($player) === $this->positionGroup($candidate)) {
                ++$sameGroup;
            }
        }

        return $sameGroup > self::GROUP_IDEALS[$this->positionGroup($candidate)];
    }

    /** @param list<ClubSquadMembership> $sourceSquad @param array<string, Player> $players @param array<string, array{appearances:int,starts:int,minutes:int}> $usage */
    private function movementPressure(Club $source, Player $player, ClubSquadMembership $membership, array $sourceSquad, array $players, array $usage, SimulationDate $asOfDate): int
    {
        $group = $this->positionGroup($player);
        $sameGroup = [];
        foreach ($sourceSquad as $sourceMembership) {
            $other = $players[$sourceMembership->playerId()->value()] ?? null;
            if ($other !== null && $this->positionGroup($other) === $group) {
                $sameGroup[] = $other;
            }
        }
        $better = count(array_filter($sameGroup, static fn (Player $other): bool => $other->overallRating() > $player->overallRating()));
        $pressure = match ($membership->role()) {
            SquadRole::Prospect => 65,
            SquadRole::Rotation => 45,
            SquadRole::Regular => 20,
            SquadRole::KeyPlayer => 0,
        };
        $pressure += min(30, $better * 5);
        $pressure += max(0, $source->reputation() - $player->overallRating() - 8) * 2;
        $pressure += max(0, $player->overallRating() - $source->reputation()) * 2;
        $pressure += max(0, $player->potential() - $player->overallRating());
        if (isset($usage[$player->id()->value()])) {
            $minutes = $usage[$player->id()->value()]['minutes'];
            if ($minutes < 900) {
                $pressure += min(35, 10 + intdiv(900 - $minutes, 30));
            }
            if ($usage[$player->id()->value()]['appearances'] === 0) {
                $pressure += 12;
            }
        }
        if ($player->ageAt($asOfDate) >= 31) {
            $pressure += 10;
        }

        return $pressure;
    }

    /** @return array<string, array{appearances:int,starts:int,minutes:int}> */
    private function usageByPlayer(DatabaseInterface $database, Season $season): array
    {
        $tableExists = $database->connection()->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'match_player_stats'")->fetchColumn();
        if ($tableExists === false) {
            return [];
        }
        $statement = $database->connection()->prepare(
            'SELECT stats.player_id, SUM(stats.appeared) AS appearances, SUM(stats.started) AS starts, SUM(stats.minutes) AS minutes '
            . 'FROM match_player_stats stats JOIN match_records matches ON matches.id = stats.match_id '
            . 'JOIN season_records seasons ON seasons.id = matches.season_id '
            . 'WHERE seasons.start_date = (SELECT MAX(previous.start_date) FROM season_records previous WHERE previous.start_date < :start_date) '
            . 'AND stats.appeared = 1 GROUP BY stats.player_id'
        );
        $statement->execute(['start_date' => $season->startDate()->toIsoString()]);
        $usage = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $usage[(string) $row['player_id']] = ['appearances' => (int) $row['appearances'], 'starts' => (int) $row['starts'], 'minutes' => (int) $row['minutes']];
        }

        return $usage;
    }

    /** @param array{group:string,position:string} $need @param list<ClubSquadMembership> $destinationSquad @param array<string, Player> $players */
    private function playerWillingness(Club $source, Club $destination, Player $player, ClubSquadMembership $membership, array $need, array $destinationSquad, array $players, SimulationDate $asOfDate): int
    {
        $role = $this->roleFor($destination, $player, $destinationSquad, $players, $asOfDate);
        $score = $destination->reputation() - $source->reputation();
        $score += $role === SquadRole::Regular || $role === SquadRole::KeyPlayer ? 35 : 15;
        $score += $player->primaryPosition()->value === $need['position'] ? 15 : 0;
        $score += $membership->role() === SquadRole::Prospect || $membership->role() === SquadRole::Rotation ? 20 : 0;
        $score -= max(0, $source->reputation() - $destination->reputation() - 15);

        return $score;
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
