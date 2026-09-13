<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\Domain\AvailabilityStatus;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Domain\TrainingRequest;
use Goal\Legacy\Modules\Player\Persistence\PlayerAvailabilityRepository;
use Goal\Legacy\Modules\Player\PlayerAvailabilityService;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class Domain009Test extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            foreach (glob($root . '/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
            if (is_dir($root)) { rmdir($root); }
        }
    }

    public function testCongestionUsesFatigueToCreateDeterministicRotation(): void
    {
        [$services, $database, $season] = $this->scenario('domain-009-rotation');
        $playerService = $services->playerModule()->service(); $contract = $services->contractModule()->service(); $registration = $services->competitionModule()->service()->registrationRepository($database);
        for ($index = 1; $index <= 12; ++$index) {
            $id = 'rotation-player-' . $index; $player = $playerService->create(new PlayerCreationRequest($id, 'Rotation', 'Player', $id, '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 90, 'regular', $index, new PlayerAttributeSet(60, 60, 60, 60, 60, 60)));
            $playerService->repository($database)->save($player); $services->clubModule()->service()->squadRepository($database)->save(new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Regular));
            $contract->save($database, $contract->create(new ContractCreationRequest(new ContractId('rotation-contract-' . $index), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2025-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
            $registration->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));
        }
        $matches = $services->matchModule()->service()->repository($database); $first = new GameMatch(new MatchId('congestion-match-1'), new CompetitionId('premier-league'), $season->id(), 1, SimulationDate::fromIsoString('2024-08-01'), new ClubId('arsenal'), new ClubId('chelsea')); $second = new GameMatch(new MatchId('congestion-match-2'), new CompetitionId('premier-league'), $season->id(), 2, SimulationDate::fromIsoString('2024-08-03'), new ClubId('chelsea'), new ClubId('arsenal')); $matches->save($first); $matches->save($second);
        $service = $services->matchModule()->service(); $service->simulate($database, $first->id()); $firstSelections = (new MatchSelectionRepository($database))->byMatch($first->id()); $availability = new PlayerAvailabilityService(); $service->simulate($database, $second->id()); $secondSelections = (new MatchSelectionRepository($database))->byMatch($second->id());
        $firstStarters = array_values(array_map(static fn ($selection): string => $selection->playerId()->value(), array_filter($firstSelections, static fn ($selection): bool => $selection->status()->value === 'starter' && $selection->clubId()->value() === 'arsenal'))); $secondStarters = array_values(array_map(static fn ($selection): string => $selection->playerId()->value(), array_filter($secondSelections, static fn ($selection): bool => $selection->status()->value === 'starter' && $selection->clubId()->value() === 'arsenal')));
        self::assertCount(11, $firstStarters); self::assertCount(11, $secondStarters); self::assertNotSame($firstStarters, $secondStarters); self::assertNotSame(0, (new PlayerAvailabilityService())->assess($database, $firstStarters[0], SimulationDate::fromIsoString('2024-08-03'))->fatigue());
    }

    public function testInjuryIsDeterministicBlocksSelectionAndRecovers(): void
    {
        [$services, $database, $season] = $this->scenario('domain-009-injury'); $player = $services->playerModule()->service()->create(new PlayerCreationRequest('injury-player', 'Injury', 'Player', 'Injury Player', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 90, 'regular', 99, new PlayerAttributeSet(60, 60, 60, 60, 60, 60))); $services->playerModule()->service()->repository($database)->save($player); $availability = new PlayerAvailabilityService(); $stats = new PlayerMatchStatRepository($database); $injuryDate = null; $changes = [];
        for ($index = 0; $index < 200 && $changes === []; ++$index) {
            $date = SimulationDate::fromIsoString('2024-08-01')->addDays($index); $match = new GameMatch(new MatchId('injury-match-' . $index), new CompetitionId('premier-league'), $season->id(), 1, $date, new ClubId('arsenal'), new ClubId('chelsea')); $stat = new PlayerMatchStat($match->id(), $player->id(), new ClubId('arsenal'), true, true, 90, 0); $changes = $database->transaction(function () use ($database, $stats, $stat, $availability, $match): array { $stats->replaceForMatchInTransaction([$stat]); return $availability->applyMatchInTransaction($database, $match); }); if ($changes !== []) { $injuryDate = $date; }
        }
        self::assertNotNull($injuryDate); self::assertNotEmpty($availability->injuries($database, $player->id())); $injury = (new PlayerAvailabilityRepository($database))->byPlayer($player->id())[0]; self::assertSame(AvailabilityStatus::Unavailable, $availability->assess($database, $player->id(), $injuryDate)->status()); self::assertNotSame(AvailabilityStatus::Unavailable, $availability->assess($database, $player->id(), $injury->recoveryDate())->status(), 'fatigue=' . $availability->assess($database, $player->id(), $injury->recoveryDate())->fatigue()); $recovered = $database->transaction(fn (): array => $availability->reconcileInTransaction($database, $injury->recoveryDate())); self::assertCount(1, $recovered); self::assertSame('player.recovered', $recovered[0]['event']);
    }

    public function testTrainingWhileInjuredDoesNotApplyDevelopmentAndIsIdempotent(): void
    {
        [$services, $database] = $this->scenario('domain-009-training'); $playerService = $services->playerModule()->service(); $player = $playerService->create(new PlayerCreationRequest('training-injury-player', 'Training', 'Player', 'Training Player', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 90, 'regular', 100, new PlayerAttributeSet(60, 60, 60, 60, 60, 60))); $playerService->repository($database)->save($player); $availability = new PlayerAvailabilityService(); $repository = new PlayerAvailabilityRepository($database); $injury = new \Goal\Legacy\Modules\Player\Domain\Injury('training-injury', $player->id(), 'test', 'training-source', \Goal\Legacy\Modules\Player\Domain\InjuryCategory::Muscular, \Goal\Legacy\Modules\Player\Domain\InjurySeverity::Moderate, SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2024-08-11')); $database->transaction(fn () => $repository->saveInjuryInTransaction($injury));
        $request = new TrainingRequest($player->id(), 'injured-training', 'balanced', SimulationDate::fromIsoString('2024-08-02'), SimulationDate::fromIsoString('2024-08-03')); $first = $playerService->trainingService()->complete($database, $request); $second = $playerService->trainingService()->complete($database, $request); self::assertFalse($first->applied()); self::assertFalse($second->applied()); self::assertSame(60, $playerService->repository($database)->get($player->id())->overallRating()); self::assertSame(0, $repository->state($player->id())['fatigue']);
    }

    /** @return array{0: \Goal\Legacy\Core\Bootstrap\CoreServices,1: \Goal\Legacy\Core\Persistence\DatabaseInterface,2: Season} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']); $nations = $services->nationModule()->service()->loadSelected(); $competitions = $services->competitionModule()->service()->loadSelected(); $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31')); $calendar = $services->worldModule()->service()->calendar(); $world = new World(new WorldId($id), $id, 2026009, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds()); $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8)); mkdir($directory, 0775, true); $this->roots[] = $directory; $store = new SqliteSaveStore($directory, new JsonSerializer()); $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0'))); $database = $store->openDatabase($id); $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $season];
    }
}
