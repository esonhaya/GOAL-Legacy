<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Competition\CompetitionService;
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Contract\ContractService;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\CareerStartRequest;
use Goal\Legacy\Modules\Player\Domain\DevelopmentProfile;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\PlayerException;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Domain\PlayerFoot;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

/**
 * The concise Youth Camp bridge: create one canonical Player, offer real
 * Club placements, then atomically attach normal career records.
 */
final class YouthCareerStartService
{
    public function __construct(
        private readonly PlayerService $players,
        private readonly ClubService $clubs,
        private readonly CompetitionService $competitions,
        private readonly ContractService $contracts,
        private readonly ?\Goal\Legacy\Modules\Player\Finance\PlayerFinanceService $finance = null,
    ) {
    }

    public function createProspect(CareerStartRequest $request): Player
    {
        $profile = DevelopmentProfile::fromInput($request->archetype);
        $position = PlayerPosition::fromInput($request->position);
        $name = preg_replace('/\s+/u', ' ', trim($request->name)) ?? trim($request->name);
        $parts = preg_split('/\s+/u', $name) ?: [];
        $first = array_shift($parts) ?? '';
        $last = $parts === [] ? $first : implode(' ', $parts);
        $potential = $this->potential($profile, $request->seed);
        $attributes = $this->attributes($position, $profile, $potential, $request->careerId, $request->seed);

        return $this->players->create(new PlayerCreationRequest(
            $request->careerId . '-player',
            $first,
            $last,
            $name,
            '2006-01-01',
            $request->nationId,
            [],
            $request->nationId,
            [$request->nationId],
            $request->heightCm,
            $request->weightKg,
            $position->value,
            $potential,
            $profile->value,
            $request->seed,
            $attributes,
            $request->preferredFoot === null ? null : PlayerFoot::fromInput($request->preferredFoot),
        ));
    }

    /** @return list<array{club_id:string,club:string,nation_id:string,competition_id:string,competition:string,tier:int,role:string,context:string}> */
    public function opportunities(DatabaseInterface $database, Player $player, Season $season): array
    {
        $clubRepository = $this->clubs->repository($database);
        $competitionRepository = $this->competitions->repository($database);
        $squads = $this->clubs->squadRepository($database);
        $playerRepository = $this->players->repository($database);
        $candidates = [];
        foreach ($this->clubs->membershipRepository($database)->bySeason($season->id()) as $membership) {
            $club = $clubRepository->get($membership->clubId());
            $competition = $competitionRepository->get($membership->competitionId());
            if ($competition->tier() > 2 || ($competition->tier() === 1 && !$this->firstTierEligible($player, $club->reputation()))) {
                continue;
            }
            $samePosition = [];
            foreach ($squads->byClub($club->id(), $season->id()) as $squad) {
                $candidate = $playerRepository->get($squad->playerId());
                if ($candidate->primaryPosition() === $player->primaryPosition()) {
                    $samePosition[] = $candidate->overallRating();
                }
            }
            $average = $samePosition === [] ? 0 : intdiv(array_sum($samePosition), count($samePosition));
            $role = $player->overallRating() >= $average - 3 ? SquadRole::Rotation : SquadRole::Prospect;
            $fit = (20 - min(20, count($samePosition) * 4)) + (10 - min(10, abs($player->overallRating() - $average))) + ($competition->tier() === 2 ? 18 : 0);
            $tie = $this->number($player->id()->value() . '|' . $club->id()->value(), 1000);
            $candidates[] = ['fit' => $fit, 'tie' => $tie, 'club_id' => $club->id()->value(), 'club' => $club->canonicalName(), 'nation_id' => $club->nationId()->value(), 'competition_id' => $competition->id()->value(), 'competition' => $competition->name(), 'tier' => $competition->tier(), 'role' => $role->value, 'context' => $role === SquadRole::Rotation ? 'Immediate squad competition' : 'Development-focused squad opportunity'];
        }
        usort($candidates, static fn (array $left, array $right): int => ($right['fit'] <=> $left['fit']) ?: ($right['tie'] <=> $left['tie']) ?: strcmp($left['club_id'], $right['club_id']));

        return array_map(static fn (array $row): array => array_diff_key($row, ['fit' => true, 'tie' => true]), array_slice($candidates, 0, 3));
    }

    /** @param list<array{club_id:string,competition_id:string,role:string}> $opportunities */
    public function accept(DatabaseInterface $database, Player $player, CareerId $careerId, Season $season, SimulationDate $startDate, array $opportunities, string $clubId): void
    {
        $selected = array_values(array_filter($opportunities, static fn (array $opportunity): bool => $opportunity['club_id'] === $clubId))[0] ?? null;
        if ($selected === null) {
            throw new PlayerException('Selected Club is not one of this Youth Camp opportunities.');
        }
        $careerRepository = $this->players->careerRepository($database);
        if ($careerRepository->exists($careerId)) {
            $existing = $careerRepository->get($careerId);
            if ($existing->playerId()->value() === $player->id()->value()) {
                return;
            }
            throw new PlayerException('Career start is already completed for another Player.');
        }
        $club = new ClubId($selected['club_id']);
        $role = SquadRole::from($selected['role']);
        $membership = new ClubSquadMembership($club, $player->id(), $season->id(), $role);
        $contract = $this->contracts->create(new ContractCreationRequest(
            new ContractId($careerId->value() . '-initial-contract'),
            $player->id(),
            $club,
            $startDate,
            SimulationDate::fromIsoString(($startDate->year() + 2) . '-06-30'),
            10,
            $startDate,
        ));
        $registration = new PlayerRegistration($season->id(), new \Goal\Legacy\Modules\Competition\Domain\CompetitionId($selected['competition_id']), $club, $player->id());
        $database->transaction(function () use ($database, $player, $membership, $contract, $registration, $careerId, $startDate, $careerRepository): void {
            $this->players->repository($database)->saveInTransaction($player);
            $this->clubs->squadRepository($database)->save($membership);
            $this->contracts->repository($database)->saveInTransaction($contract);
            $this->competitions->registrationRepository($database)->registerInTransaction($registration);
            $careerRepository->save(new CareerPlayerReference($careerId, $player->id(), $startDate));
            $this->finance?->initializeInTransaction($database, $player->id(), $startDate);
        });
        // Youth Camp owns the atomic career-start write path, so explicitly
        // initialize the same controlled-career social context that the
        // canonical PlayerService path provides. This keeps the first Career
        // Home coherent before a first Match or event lazily touches it.
        $this->players->socialService()->initializeCareer($database, $player, $club->value(), $role->value, $startDate);
    }

    private function firstTierEligible(Player $player, int $reputation): bool
    {
        return $player->developmentProfile() === DevelopmentProfile::Prodigy && $player->overallRating() >= 65 && $reputation <= 70;
    }

    private function potential(DevelopmentProfile $profile, int $seed): int
    {
        $base = match ($profile) {
            DevelopmentProfile::LateBloomer => 87,
            DevelopmentProfile::Regular => 82,
            DevelopmentProfile::Prodigy => 92,
        };

        return min(99, max(1, $base + $this->number($profile->value . '|potential|' . $seed, 5) - 2));
    }

    private function attributes(PlayerPosition $position, DevelopmentProfile $profile, int $potential, string $id, int $seed): PlayerAttributeSet
    {
        $base = match ($position) {
            PlayerPosition::Goalkeeper => [46, 34, 57, 45, 64, 68],
            PlayerPosition::CentreBack => [56, 38, 52, 50, 70, 68],
            PlayerPosition::LeftBack, PlayerPosition::RightBack => [64, 43, 56, 58, 65, 64],
            PlayerPosition::DefensiveMidfielder => [58, 48, 65, 58, 66, 64],
            PlayerPosition::CentralMidfielder => [61, 52, 68, 64, 57, 58],
            PlayerPosition::AttackingMidfielder => [63, 59, 69, 67, 47, 56],
            PlayerPosition::LeftWinger, PlayerPosition::RightWinger => [69, 62, 60, 69, 40, 55],
            PlayerPosition::Striker => [65, 70, 55, 64, 38, 61],
        };
        $profileOffset = match ($profile) {
            DevelopmentProfile::LateBloomer => -3,
            DevelopmentProfile::Regular => 0,
            DevelopmentProfile::Prodigy => 6,
        };
        $values = [];
        foreach ($base as $index => $value) {
            $jitter = $this->number($id . '|' . $seed . '|' . $position->value . '|' . $index, 5) - 2;
            $values[] = min($potential, max(0, $value + $profileOffset + $jitter));
        }

        return new PlayerAttributeSet(...$values);
    }

    private function number(string $key, int $modulo): int
    {
        return hexdec(substr(hash('sha256', $key), 0, 8)) % $modulo;
    }
}
