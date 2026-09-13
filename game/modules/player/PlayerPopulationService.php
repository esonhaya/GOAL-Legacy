<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Club\Domain\Club;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Club\ClubService;
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Contract\ContractService;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Nation\Domain\Nation;
use Goal\Legacy\Modules\Nation\NationService;
use Goal\Legacy\Modules\Player\Domain\DevelopmentProfile;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\PlayerException;
use Goal\Legacy\Modules\Player\Domain\PlayerPosition;
use Goal\Legacy\Modules\Player\Persistence\PlayerPopulationRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Competition\Persistence\PlayerRegistrationRepository;
use Goal\Legacy\Modules\Contract\Persistence\ContractRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use DateTimeImmutable;
use RuntimeException;

final class PlayerPopulationService
{
    public const GENERATION_VERSION = 1;
    public const TARGET_SQUAD_SIZE = 25;

    /** @var list<string> */
    private const POSITIONS = ['GK', 'GK', 'CB', 'CB', 'CB', 'LB', 'RB', 'CB', 'DM', 'DM', 'CM', 'CM', 'CM', 'AM', 'AM', 'LW', 'RW', 'LW', 'RW', 'ST', 'ST', 'ST', 'CM', 'CB', 'GK'];

    /** @var list<string> */
    private const FIRST_NAMES = ['Alex', 'Ben', 'Daniel', 'Elias', 'Felix', 'Gabriel', 'Hugo', 'Ivan', 'Jonas', 'Leo', 'Marco', 'Mateo', 'Noah', 'Oliver', 'Rafael', 'Theo', 'Victor', 'William'];

    /** @var list<string> */
    private const LAST_NAMES = ['Adams', 'Bennett', 'Costa', 'Duarte', 'Fischer', 'Garcia', 'Hansen', 'Ivanov', 'Keller', 'Larsen', 'Martin', 'Novak', 'Ortega', 'Parker', 'Rossi', 'Silva', 'Turner', 'Vega'];

    public function __construct(
        private readonly NationService $nationService,
        private readonly ClubService $clubService,
        private readonly ContractService $contractService,
    ) {
    }

    /** @return array<string, mixed> */
    public function populate(DatabaseInterface $database, Season $season, int $worldSeed): array
    {
        if ($worldSeed < 0) {
            throw new PlayerException('Population world seeds cannot be negative.');
        }
        $nations = $this->nationService->loadSelected();
        $clubs = $this->clubService->repository($database)->all();
        $memberships = $this->clubService->membershipRepository($database)->bySeason($season->id());
        $byClub = [];
        foreach ($memberships as $membership) {
            $byClub[$membership->clubId()->value()][] = $membership;
        }
        $populationRepository = new PlayerPopulationRepository($database);
        $reports = [];
        foreach ($clubs as $club) {
            $reports[] = $database->transaction(fn (): array => $this->populateClubInTransaction($database, $club, $season, $worldSeed, $nations, $byClub[$club->id()->value()] ?? [], $populationRepository));
        }

        return $this->summarize($database, $clubs, $season, $reports);
    }

    /**
     * Fill only missing senior-squad places for a continuing Season.
     * Replenishment Players use a Season-scoped identity prefix so an old
     * initialization slot is never silently reused as a new Player.
     *
     * @return array<string, mixed>
     */
    public function replenish(DatabaseInterface $database, Season $season, int $worldSeed, SimulationDate $asOfDate): array
    {
        if ($worldSeed < 0) {
            throw new PlayerException('Population world seeds cannot be negative.');
        }
        $nations = $this->nationService->loadSelected();
        $clubs = $this->clubService->repository($database)->all();
        $memberships = $this->clubService->membershipRepository($database)->bySeason($season->id());
        $byClub = [];
        foreach ($memberships as $membership) {
            $byClub[$membership->clubId()->value()][] = $membership;
        }
        $populationRepository = new PlayerPopulationRepository($database);
        $reports = [];
        foreach ($clubs as $club) {
            $reports[] = $database->transaction(fn (): array => $this->replenishClubInTransaction(
                $database,
                $club,
                $season,
                $worldSeed,
                $asOfDate,
                $nations,
                $byClub[$club->id()->value()] ?? [],
                $populationRepository,
            ));
        }

        $summary = $this->summarize($database, $clubs, $season, $reports);
        $summary['replenishment'] = true;

        return $summary;
    }

    /** @param list<Nation> $nations @param list<\Goal\Legacy\Modules\Club\Domain\ClubCompetitionMembership> $competitionMemberships @return array<string, mixed> */
    private function populateClubInTransaction(DatabaseInterface $database, Club $club, Season $season, int $worldSeed, array $nations, array $competitionMemberships, PlayerPopulationRepository $populationRepository): array
    {
        if ($competitionMemberships === []) {
            return ['club_id' => $club->id()->value(), 'generated' => 0, 'total' => 0];
        }
        $seasonId = $season->id();
        $clubId = $club->id();
        $metadata = $populationRepository->get($seasonId, $clubId);
        if ($metadata !== null && ($metadata['generation_version'] !== self::GENERATION_VERSION || $metadata['world_seed'] !== $worldSeed || $metadata['target_squad_size'] !== self::TARGET_SQUAD_SIZE)) {
            throw new PlayerException(sprintf('Population metadata for Club "%s" uses a different generator version, seed, or target.', $clubId->value()));
        }
        $playerRepository = new PlayerRepository($database);
        $squadRepository = $this->clubService->squadRepository($database);
        $contractRepository = $this->contractService->repository($database);
        $registrationRepository = new PlayerRegistrationRepository($database);
        $currentMemberships = $squadRepository->byClub($clubId, $seasonId);
        $existingIds = [];
        foreach ($currentMemberships as $membership) {
            $existingIds[$membership->playerId()->value()] = true;
        }
        $generatedPrefix = 'npc-v' . self::GENERATION_VERSION . '-' . $clubId->value() . '-';
        $generatedCount = 0;
        foreach (array_keys($existingIds) as $playerId) {
            if (str_starts_with($playerId, $generatedPrefix)) {
                ++$generatedCount;
            }
        }
        $needed = max(0, self::TARGET_SQUAD_SIZE - count($currentMemberships));
        $created = 0;
        $ordinal = 1;
        while ($created < $needed) {
            $playerId = $generatedPrefix . str_pad((string) $ordinal, 2, '0', STR_PAD_LEFT);
            ++$ordinal;
            if (isset($existingIds[$playerId])) {
                continue;
            }
            $player = $this->generatePlayer($club, $season->startDate(), $nations, $worldSeed, $ordinal - 1);
            if (!$playerRepository->exists($player->id())) {
                $playerRepository->saveInTransaction($player);
            } else {
                $player = $playerRepository->get($player->id());
            }
            $role = $this->roleForOrdinal($ordinal - 1);
            $membership = new ClubSquadMembership($clubId, $player->id(), $seasonId, $role);
            if (!$squadRepository->exists($membership)) {
                $squadRepository->save($membership);
            }
            $this->ensureContractInTransaction($contractRepository, $player, $club, $season);
            $this->ensureRegistrationsInTransaction($registrationRepository, $competitionMemberships, $player);
            $existingIds[$playerId] = true;
            ++$created;
            ++$generatedCount;
        }
        foreach ($currentMemberships as $membership) {
            $player = $playerRepository->get($membership->playerId());
            $this->ensureRegistrationsInTransaction($registrationRepository, $competitionMemberships, $player);
        }
        $populationRepository->saveInTransaction($seasonId, $clubId, self::GENERATION_VERSION, $worldSeed, self::TARGET_SQUAD_SIZE, $generatedCount);

        return ['club_id' => $clubId->value(), 'generated' => $created, 'total' => count($currentMemberships) + $created];
    }

    /** @param list<Nation> $nations @param list<\Goal\Legacy\Modules\Club\Domain\ClubCompetitionMembership> $competitionMemberships */
    private function replenishClubInTransaction(DatabaseInterface $database, Club $club, Season $season, int $worldSeed, SimulationDate $asOfDate, array $nations, array $competitionMemberships, PlayerPopulationRepository $populationRepository): array
    {
        if ($competitionMemberships === []) {
            return ['club_id' => $club->id()->value(), 'generated' => 0, 'total' => 0];
        }
        $seasonId = $season->id();
        $clubId = $club->id();
        $metadata = $populationRepository->get($seasonId, $clubId);
        if ($metadata !== null && ($metadata['generation_version'] !== self::GENERATION_VERSION || $metadata['world_seed'] !== $worldSeed || $metadata['target_squad_size'] !== self::TARGET_SQUAD_SIZE)) {
            throw new PlayerException(sprintf('Population metadata for Club "%s" uses a different generator version, seed, or target.', $clubId->value()));
        }
        $playerRepository = new PlayerRepository($database);
        $squadRepository = $this->clubService->squadRepository($database);
        $contractRepository = $this->contractService->repository($database);
        $currentMemberships = $squadRepository->byClub($clubId, $seasonId);
        $existingIds = [];
        $currentPlayers = [];
        foreach ($currentMemberships as $membership) {
            $existingIds[$membership->playerId()->value()] = true;
            $currentPlayers[] = $playerRepository->get($membership->playerId());
        }
        $generatedPrefix = 'npc-v' . self::GENERATION_VERSION . '-replenishment-' . $seasonId->value() . '-' . $clubId->value() . '-';
        $generatedCount = 0;
        foreach (array_keys($existingIds) as $playerId) {
            if (str_starts_with($playerId, $generatedPrefix)) {
                ++$generatedCount;
            }
        }
        $needed = max(0, self::TARGET_SQUAD_SIZE - count($currentMemberships));
        $created = 0;
        $ordinal = 1;
        while ($created < $needed) {
            $playerId = $generatedPrefix . str_pad((string) $ordinal, 2, '0', STR_PAD_LEFT);
            ++$ordinal;
            if (isset($existingIds[$playerId])) {
                continue;
            }
            $position = $this->replenishmentPosition($currentPlayers, $ordinal);
            $player = $this->generatePlayer($club, $season->startDate(), $nations, $worldSeed, $ordinal, $position, $generatedPrefix, 'replenishment');
            if (!$playerRepository->exists($player->id())) {
                $playerRepository->saveInTransaction($player);
            } else {
                $player = $playerRepository->get($player->id());
            }
            $membership = new ClubSquadMembership($clubId, $player->id(), $seasonId, $this->roleForOrdinal(count($currentMemberships) + $ordinal - 1));
            if (!$squadRepository->exists($membership)) {
                $squadRepository->save($membership);
            }
            $this->ensureContractInTransaction($contractRepository, $player, $club, $season, $asOfDate, 'repl-' . $seasonId->value() . '-');
            $existingIds[$playerId] = true;
            $currentPlayers[] = $player;
            ++$created;
            ++$generatedCount;
        }
        $populationRepository->saveInTransaction($seasonId, $clubId, self::GENERATION_VERSION, $worldSeed, self::TARGET_SQUAD_SIZE, $generatedCount);

        return ['club_id' => $clubId->value(), 'generated' => $created, 'total' => count($currentMemberships) + $created];
    }

    private function ensureContractInTransaction(ContractRepository $contracts, Player $player, Club $club, Season $season, ?SimulationDate $asOfDate = null, ?string $contractPrefix = null): void
    {
        $active = $contracts->activeForPlayer($player->id());
        if ($active !== null) {
            if ($active->clubId()->value() !== $club->id()->value()) {
                throw new PlayerException(sprintf('Generated Player "%s" already has an active Contract with another Club.', $player->id()->value()));
            }
            return;
        }
        $ordinalSeed = $this->integer('contract|' . $player->id()->value());
        $end = $season->endDate()->addDays(90 + ($ordinalSeed % 640));
        $contract = $this->contractService->create(new ContractCreationRequest(
            new ContractId(($contractPrefix ?? 'npc-contract-v' . self::GENERATION_VERSION . '-') . substr(hash('sha256', $player->id()->value()), 0, 24)),
            $player->id(),
            $club->id(),
            $season->startDate()->addDays(-1),
            $end,
            max(50, ($club->reputation() * 10) + ($player->overallRating() * 5) + ($ordinalSeed % 250)),
            $asOfDate ?? $season->startDate(),
        ));
        $contracts->saveInTransaction($contract);
    }

    /** @param list<\Goal\Legacy\Modules\Club\Domain\ClubCompetitionMembership> $competitionMemberships */
    private function ensureRegistrationsInTransaction(PlayerRegistrationRepository $registrations, array $competitionMemberships, Player $player): void
    {
        foreach ($competitionMemberships as $membership) {
            $registration = new PlayerRegistration($membership->seasonId(), $membership->competitionId(), $membership->clubId(), $player->id());
            if (!$registrations->exists($registration)) {
                $registrations->registerInTransaction($registration);
            }
        }
    }

    /** @param list<Nation> $nations */
    private function generatePlayer(Club $club, SimulationDate $seasonStart, array $nations, int $worldSeed, int $ordinal, ?PlayerPosition $requestedPosition = null, ?string $idPrefix = null, string $generationContext = 'initial'): Player
    {
        $keyParts = ['population', self::GENERATION_VERSION, $worldSeed, $club->id()->value(), $ordinal];
        if ($generationContext !== 'initial') {
            $keyParts[] = $generationContext;
            $keyParts[] = $seasonStart->toIsoString();
        }
        $key = implode('|', $keyParts);
        $age = 18 + (int) floor($this->unit($key . '|age') * 17);
        $profile = $this->profile($key, $age);
        $position = $requestedPosition ?? PlayerPosition::fromInput(self::POSITIONS[($ordinal - 1) % count(self::POSITIONS)]);
        $base = (int) round(35 + ($club->reputation() * 0.5) + ($age < 22 ? -3 : ($age > 30 ? -2 : 2)));
        $bias = $this->positionBias($position);
        $values = [];
        foreach (['pace', 'shooting', 'passing', 'dribbling', 'defending', 'physicality'] as $index => $attribute) {
            $jitter = (int) floor($this->unit($key . '|jitter|' . $attribute) * 15) - 7;
            $values[$attribute] = max(0, min(99, $base + ($bias[$attribute] ?? 0) + $jitter));
        }
        $attributes = new PlayerAttributeSet(...array_values($values));
        $overall = $attributes->overallRating();
        $ageHeadroom = max(3, 22 - max(0, $age - 18));
        $profileHeadroom = match ($profile) {
            DevelopmentProfile::Prodigy => 4,
            DevelopmentProfile::LateBloomer => 6,
            DevelopmentProfile::Regular => 2,
        };
        $potential = min(99, $overall + 3 + (int) floor($this->unit($key . '|potential') * ($ageHeadroom + $profileHeadroom)));
        $nationId = $this->nationFor($club, $nations, $key);
        $first = self::FIRST_NAMES[$this->index($key . '|first', count(self::FIRST_NAMES))];
        $last = self::LAST_NAMES[$this->index($key . '|last', count(self::LAST_NAMES))];
        $birthDate = $seasonStart->atStartOfDay()->modify(sprintf('-%d years -%d days', $age, $this->index($key . '|birthday', 330)));
        if (!$birthDate instanceof DateTimeImmutable) {
            throw new RuntimeException('Generated Player birth date could not be calculated.');
        }
        $heightBase = in_array($position, [PlayerPosition::Goalkeeper, PlayerPosition::CentreBack], true) ? 186 : 178;
        $height = $heightBase + $this->index($key . '|height', 15) - 7;
        $weight = ($heightBase > 180 ? 76 : 70) + $this->index($key . '|weight', 17) - 8;
        $playerId = ($idPrefix ?? 'npc-v' . self::GENERATION_VERSION . '-' . $club->id()->value() . '-') . str_pad((string) $ordinal, 2, '0', STR_PAD_LEFT);

        return new PlayerCreationService($nations)->create(new PlayerCreationRequest(
            $playerId,
            $first,
            $last,
            $first . ' ' . $last,
            $birthDate->format('Y-m-d'),
            $nationId,
            [],
            $nationId,
            [$nationId],
            $height,
            $weight,
            $position->value,
            $potential,
            $profile->value,
            $this->integer($key . '|seed'),
            $attributes,
        ));
    }

    /** @param list<Player> $players */
    private function replenishmentPosition(array $players, int $ordinal): PlayerPosition
    {
        $has = [];
        foreach ($players as $player) {
            $has[$player->primaryPosition()->value] = true;
        }
        if (!isset($has[PlayerPosition::Goalkeeper->value])) {
            return PlayerPosition::Goalkeeper;
        }
        if (!array_intersect_key($has, array_fill_keys([PlayerPosition::CentreBack->value, PlayerPosition::LeftBack->value, PlayerPosition::RightBack->value], true))) {
            return PlayerPosition::CentreBack;
        }
        if (!array_intersect_key($has, array_fill_keys([PlayerPosition::DefensiveMidfielder->value, PlayerPosition::CentralMidfielder->value, PlayerPosition::AttackingMidfielder->value], true))) {
            return PlayerPosition::CentralMidfielder;
        }
        if (!array_intersect_key($has, array_fill_keys([PlayerPosition::Striker->value, PlayerPosition::LeftWinger->value, PlayerPosition::RightWinger->value], true))) {
            return PlayerPosition::Striker;
        }

        return PlayerPosition::fromInput(self::POSITIONS[($ordinal - 1) % count(self::POSITIONS)]);
    }

    private function profile(string $key, int $age): DevelopmentProfile
    {
        $roll = $this->unit($key . '|profile');
        if ($age <= 22) {
            return $roll < 0.20 ? DevelopmentProfile::Prodigy : ($roll < 0.40 ? DevelopmentProfile::LateBloomer : DevelopmentProfile::Regular);
        }
        if ($age >= 29) {
            return $roll < 0.05 ? DevelopmentProfile::Prodigy : ($roll < 0.30 ? DevelopmentProfile::LateBloomer : DevelopmentProfile::Regular);
        }

        return $roll < 0.12 ? DevelopmentProfile::Prodigy : ($roll < 0.27 ? DevelopmentProfile::LateBloomer : DevelopmentProfile::Regular);
    }

    /** @return array<string, int> */
    private function positionBias(PlayerPosition $position): array
    {
        $bias = array_fill_keys(['pace', 'shooting', 'passing', 'dribbling', 'defending', 'physicality'], 0);
        foreach (match ($position) {
            PlayerPosition::Goalkeeper => ['defending' => 3, 'physicality' => 2],
            PlayerPosition::CentreBack => ['defending' => 7, 'physicality' => 4, 'pace' => -2],
            PlayerPosition::LeftBack, PlayerPosition::RightBack => ['pace' => 5, 'defending' => 4, 'physicality' => 1],
            PlayerPosition::DefensiveMidfielder => ['defending' => 4, 'passing' => 4, 'physicality' => 2],
            PlayerPosition::CentralMidfielder => ['passing' => 5, 'dribbling' => 3],
            PlayerPosition::AttackingMidfielder => ['passing' => 3, 'dribbling' => 5, 'shooting' => 2],
            PlayerPosition::LeftWinger, PlayerPosition::RightWinger => ['pace' => 6, 'dribbling' => 5, 'shooting' => 2],
            PlayerPosition::Striker => ['shooting' => 7, 'pace' => 3, 'physicality' => 2],
        } as $attribute => $value) {
            $bias[$attribute] = $value;
        }

        return $bias;
    }

    /** @param list<Nation> $nations */
    private function nationFor(Club $club, array $nations, string $key): string
    {
        if ($this->unit($key . '|nation') < 0.72 || count($nations) < 2) {
            return $club->nationId()->value();
        }
        $ids = array_values(array_filter(array_map(static fn (Nation $nation): string => $nation->id()->value(), $nations), static fn (string $id): bool => $id !== $club->nationId()->value()));

        return $ids[$this->index($key . '|foreign-nation', count($ids))] ?? $club->nationId()->value();
    }

    private function roleForOrdinal(int $ordinal): SquadRole
    {
        return $ordinal <= 2 ? SquadRole::KeyPlayer : ($ordinal <= 10 ? SquadRole::Regular : ($ordinal <= 18 ? SquadRole::Rotation : SquadRole::Prospect));
    }

    private function unit(string $key): float
    {
        return hexdec(substr(hash('sha256', $key), 0, 12)) / 281474976710655;
    }

    private function integer(string $key): int
    {
        return hexdec(substr(hash('sha256', $key), 0, 8));
    }

    private function index(string $key, int $count): int
    {
        return $count < 1 ? 0 : $this->integer($key) % $count;
    }

    /** @param list<Club> $clubs @param list<array<string, mixed>> $reports @return array<string, mixed> */
    private function summarize(DatabaseInterface $database, array $clubs, Season $season, array $reports): array
    {
        $playerRepository = new PlayerRepository($database);
        $squadRepository = $this->clubService->squadRepository($database);
        $players = [];
        $positionCounts = [];
        $roleCounts = [];
        $ages = [];
        foreach ($clubs as $club) {
            foreach ($squadRepository->byClub($club->id(), $season->id()) as $membership) {
                $player = $playerRepository->get($membership->playerId());
                $players[] = $player;
                $positionCounts[$player->primaryPosition()->value] = ($positionCounts[$player->primaryPosition()->value] ?? 0) + 1;
                $roleCounts[$membership->role()->value] = ($roleCounts[$membership->role()->value] ?? 0) + 1;
                $ages[] = $player->ageAt($season->startDate());
            }
        }
        $sizes = array_map(static fn (array $report): int => (int) $report['total'], $reports);
        $ovrs = array_map(static fn (Player $player): int => $player->overallRating(), $players);
        $profiles = array_count_values(array_map(static fn (Player $player): string => $player->developmentProfile()->value, $players));

        return [
            'generation_version' => self::GENERATION_VERSION,
            'clubs_populated' => count(array_filter($reports, static fn (array $report): bool => (int) $report['total'] > 0)),
            'players_generated' => array_sum(array_map(static fn (array $report): int => (int) $report['generated'], $reports)),
            'players_total' => count($players),
            'avg_squad_size' => $sizes === [] ? 0.0 : round(array_sum($sizes) / count($sizes), 1),
            'min_squad_size' => $sizes === [] ? 0 : min($sizes),
            'max_squad_size' => $sizes === [] ? 0 : max($sizes),
            'position_counts' => $positionCounts,
            'role_counts' => $roleCounts,
            'ovr_min' => $ovrs === [] ? 0 : min($ovrs),
            'ovr_max' => $ovrs === [] ? 0 : max($ovrs),
            'ovr_avg' => $ovrs === [] ? 0.0 : round(array_sum($ovrs) / count($ovrs), 1),
            'age_min' => $ages === [] ? 0 : min($ages),
            'age_max' => $ages === [] ? 0 : max($ages),
            'age_avg' => $ages === [] ? 0.0 : round(array_sum($ages) / count($ages), 1),
            'profile_counts' => $profiles,
        ];
    }
}
