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
use Goal\Legacy\Modules\Match\Domain\SelectionStatus;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
use Goal\Legacy\Modules\Match\Persistence\MatchSubstitutionRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerEvaluationRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerDevelopmentRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerAvailabilityRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class Domain011Test extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            foreach (glob($root . '/*') ?: [] as $file) {
                if (is_file($file)) { unlink($file); }
            }
            if (is_dir($root)) { rmdir($root); }
        }
    }

    public function testPopulatedMatchUsesPositionAwareSquadSubstitutionsAndMinuteInvariant(): void
    {
        [$services, $database, $season, $store] = $this->scenario('domain-011-participation');
        $services->playerModule()->service()->populationService()->populate($database, $season, 11011);
        $matchService = $services->matchModule()->service();
        $match = array_values(array_filter($matchService->generateFixtures($database, 'premier-league', $season->id()), static fn (GameMatch $value): bool => $value->homeClubId()->value() === 'arsenal' || $value->awayClubId()->value() === 'arsenal'))[0];
        $match = $matchService->simulate($database, $match->id());

        $selections = (new MatchSelectionRepository($database))->byMatch($match->id());
        $arsenalSelections = array_values(array_filter($selections, static fn ($selection): bool => $selection->clubId()->value() === 'arsenal'));
        self::assertCount(25, $arsenalSelections);
        self::assertCount(11, array_filter($arsenalSelections, static fn ($selection): bool => $selection->status() === SelectionStatus::Starter));
        self::assertCount(7, array_filter($arsenalSelections, static fn ($selection): bool => $selection->status() === SelectionStatus::Bench));
        self::assertCount(7, array_filter($arsenalSelections, static fn ($selection): bool => $selection->status() === SelectionStatus::NotSelected));

        $players = new PlayerRepository($database);
        $starterPositions = array_map(static fn ($selection): string => $players->get($selection->playerId())->primaryPosition()->value, array_filter($arsenalSelections, static fn ($selection): bool => $selection->status() === SelectionStatus::Starter));
        self::assertSame(1, count(array_filter($starterPositions, static fn (string $position): bool => $position === 'GK')));
        self::assertNotEmpty(array_filter($starterPositions, static fn (string $position): bool => in_array($position, ['CB', 'LB', 'RB'], true)));
        self::assertNotEmpty(array_filter($starterPositions, static fn (string $position): bool => in_array($position, ['DM', 'CM', 'AM'], true)));
        self::assertNotEmpty(array_filter($starterPositions, static fn (string $position): bool => in_array($position, ['LW', 'RW', 'ST'], true)));

        $stats = (new PlayerMatchStatRepository($database))->byMatch($match->id());
        $arsenalStats = array_values(array_filter($stats, static fn ($stat): bool => $stat->clubId()->value() === 'arsenal'));
        self::assertGreaterThan(11, count($arsenalStats));
        self::assertCount(11, array_filter($arsenalStats, static fn ($stat): bool => $stat->started()));
        self::assertNotEmpty(array_filter($arsenalStats, static fn ($stat): bool => !$stat->started() && $stat->appeared() && $stat->minutes() > 0));
        self::assertSame(990, array_sum(array_map(static fn ($stat): int => $stat->minutes(), $arsenalStats)));
        self::assertLessThanOrEqual(90, max(array_map(static fn ($stat): int => $stat->minutes(), $stats)));
        self::assertSame(array_sum(array_map(static fn ($stat): int => $stat->goals(), array_filter($stats, static fn ($stat): bool => $stat->clubId()->value() === $match->homeClubId()->value()))), $match->result()?->homeGoals());
        self::assertSame(array_sum(array_map(static fn ($stat): int => $stat->goals(), array_filter($stats, static fn ($stat): bool => $stat->clubId()->value() === $match->awayClubId()->value()))), $match->result()?->awayGoals());

        $substitutions = (new MatchSubstitutionRepository($database))->byMatch($match->id());
        self::assertCount(count(array_filter($arsenalStats, static fn ($stat): bool => !$stat->started())), array_filter($substitutions, static fn ($substitution): bool => $substitution->clubId()->value() === 'arsenal'));
        foreach ($substitutions as $substitution) {
            $outgoing = array_values(array_filter($stats, static fn ($stat): bool => $stat->playerId()->value() === $substitution->outgoingPlayerId()->value()))[0];
            $incoming = array_values(array_filter($stats, static fn ($stat): bool => $stat->playerId()->value() === $substitution->incomingPlayerId()->value()))[0];
            self::assertSame(SelectionStatus::Starter, array_values(array_filter($selections, static fn ($selection): bool => $selection->playerId()->value() === $substitution->outgoingPlayerId()->value()))[0]->status());
            self::assertSame(SelectionStatus::Bench, array_values(array_filter($selections, static fn ($selection): bool => $selection->playerId()->value() === $substitution->incomingPlayerId()->value()))[0]->status());
            self::assertSame($substitution->minute(), $outgoing->minutes());
            self::assertSame(90 - $substitution->minute(), $incoming->minutes());
            self::assertFalse($incoming->started());
        }
        $incomingId = $substitutions[0]->incomingPlayerId();
        self::assertNotEmpty(array_filter((new PlayerDevelopmentRepository($database))->byPlayer($incomingId), static fn ($entry): bool => $entry->source() === 'match' && $entry->sourceId() === $match->id()->value() . ':' . $incomingId->value()));
        self::assertGreaterThan(0, (new PlayerAvailabilityRepository($database))->state($incomingId)['fatigue']);

        $unusedBench = array_values(array_filter($arsenalSelections, static fn ($selection): bool => $selection->status() === SelectionStatus::Bench && !in_array($selection->playerId()->value(), array_map(static fn ($stat): string => $stat->playerId()->value(), $arsenalStats), true)));
        self::assertNotEmpty($unusedBench);
        self::assertSame([], (new CareerEvaluationRepository($database))->byPlayer($unusedBench[0]->playerId(), new ClubId('arsenal')));

        $beforeStats = array_map(static fn ($stat): array => $stat->toArray(), $stats);
        $beforeSubs = array_map(static fn ($substitution): array => $substitution->toArray(), $substitutions);
        unset($database);
        $database = $store->openDatabase('domain-011-participation');
        self::assertSame($beforeStats, array_map(static fn ($stat): array => $stat->toArray(), (new PlayerMatchStatRepository($database))->byMatch($match->id())));
        self::assertSame($beforeSubs, array_map(static fn ($substitution): array => $substitution->toArray(), (new MatchSubstitutionRepository($database))->byMatch($match->id())));
    }

    public function testPositionShortageFallsBackWithoutBreakingMatchdaySelection(): void
    {
        [$services, $database, $season] = $this->scenario('domain-011-shortage');
        $playerService = $services->playerModule()->service();
        $contractService = $services->contractModule()->service();
        $registration = $services->competitionModule()->service()->registrationRepository($database);
        for ($index = 1; $index <= 12; ++$index) {
            $player = $playerService->create(new \Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest('shortage-player-' . $index, 'Shortage', 'Player', 'shortage-player-' . $index, '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 90, 'regular', $index, new \Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet(60, 60, 60, 60, 60, 60)));
            $playerService->repository($database)->save($player);
            $services->clubModule()->service()->squadRepository($database)->save(new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Regular));
            $contractService->save($database, $contractService->create(new ContractCreationRequest(new ContractId('shortage-contract-' . $index), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2025-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
            $registration->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));
        }
        $match = new GameMatch(new MatchId('domain-011-shortage-match'), new CompetitionId('premier-league'), $season->id(), 1, SimulationDate::fromIsoString('2024-08-01'), new ClubId('arsenal'), new ClubId('chelsea'));
        $services->matchModule()->service()->repository($database)->save($match);
        $services->matchModule()->service()->simulate($database, $match->id());
        $selections = array_values(array_filter((new MatchSelectionRepository($database))->byMatch($match->id()), static fn ($selection): bool => $selection->clubId()->value() === 'arsenal'));
        self::assertCount(11, array_filter($selections, static fn ($selection): bool => $selection->status() === SelectionStatus::Starter));
        self::assertCount(1, array_filter($selections, static fn ($selection): bool => $selection->status() === SelectionStatus::Bench));
    }

    public function testSubstituteCanScoreAndGoalAttributionRemainsParticipantBound(): void
    {
        [$services, $database, $season] = $this->scenario('domain-011-substitute-goal');
        $services->playerModule()->service()->populationService()->populate($database, $season, 11011);
        $matchService = $services->matchModule()->service();
        $matches = array_values(array_filter($matchService->generateFixtures($database, 'premier-league', $season->id()), static fn (GameMatch $value): bool => $value->homeClubId()->value() === 'arsenal' || $value->awayClubId()->value() === 'arsenal'));
        $found = false;
        foreach ($matches as $match) {
            $completed = $matchService->simulate($database, $match->id());
            $stats = (new PlayerMatchStatRepository($database))->byMatch($completed->id());
            $substituteGoals = array_filter($stats, static fn ($stat): bool => !$stat->started() && $stat->goals() > 0);
            if ($substituteGoals !== []) {
                $found = true;
                foreach ($substituteGoals as $stat) {
                    self::assertTrue($stat->appeared());
                    self::assertGreaterThan(0, $stat->minutes());
                }
                break;
            }
        }
        self::assertTrue($found, 'The deterministic Arsenal season should produce at least one substitute scorer.');
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season,3:SqliteSaveStore} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 11011, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $season, $store];
    }
}
