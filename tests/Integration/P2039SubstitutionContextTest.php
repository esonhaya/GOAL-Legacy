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
use Goal\Legacy\Modules\Match\Domain\MatchSubstitution;
use Goal\Legacy\Modules\Match\Domain\SelectionStatus;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
use Goal\Legacy\Modules\Match\Persistence\MatchSubstitutionRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Player\Persistence\PlayerAvailabilityRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class P2039SubstitutionContextTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            foreach (glob($root . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

    public function testDetailedProductionMatchHasBoundedContextAndCanonicalParticipationWindows(): void
    {
        [$services, $database, $season, $player, $store] = $this->scenario('p2039-context');
        $services->playerModule()->service()->populationService()->populate($database, $season, 2039001);
        $this->addControlledPlayer($services, $database, $season, $player);
        (new PlayerAvailabilityRepository($database))->saveStateInTransaction($player->id(), 60, SimulationDate::fromIsoString('2024-08-01'), 1);

        $scheduled = new GameMatch(new MatchId('p2039-match-1'), new CompetitionId('premier-league'), $season->id(), 1, SimulationDate::fromIsoString('2024-08-01'), new ClubId('arsenal'), new ClubId('chelsea'));
        $services->matchModule()->service()->repository($database)->save($scheduled);
        $completed = $services->matchModule()->service()->simulate($database, $scheduled->id());
        $matchService = $services->matchModule()->service();
        $story = $matchService->playerStory($database, $completed->id(), $player->id());
        $selections = (new MatchSelectionRepository($database))->byMatch($completed->id());
        $stats = (new PlayerMatchStatRepository($database))->byMatch($completed->id());
        $substitutions = (new MatchSubstitutionRepository($database))->byMatch($completed->id());

        self::assertSame('starter', $story['participation_state']);
        self::assertNotEmpty($substitutions);
        self::assertNotNull($story['substitution_off_minute']);
        self::assertSame(MatchSubstitution::REASON_WORKLOAD, $story['substitution_reason']);
        self::assertSame($story['substitution_off_minute'], $story['minutes']);
        self::assertTrue($matchService->integrity($database, $completed->id())['valid']);
        self::assertCount(count($stats), array_unique(array_map(static fn ($stat): string => $stat->playerId()->value(), $stats)));

        $selectionByPlayer = [];
        foreach ($selections as $selection) {
            $selectionByPlayer[$selection->playerId()->value()] = $selection;
        }
        foreach ($substitutions as $substitution) {
            self::assertContains($substitution->reason(), [MatchSubstitution::REASON_WORKLOAD, MatchSubstitution::REASON_READINESS, MatchSubstitution::REASON_TACTICAL]);
            self::assertSame(SelectionStatus::Starter, $selectionByPlayer[$substitution->outgoingPlayerId()->value()]->status());
            self::assertSame(SelectionStatus::Bench, $selectionByPlayer[$substitution->incomingPlayerId()->value()]->status());
            $outgoing = array_values(array_filter($stats, static fn ($stat): bool => $stat->playerId()->value() === $substitution->outgoingPlayerId()->value()))[0] ?? null;
            $incoming = array_values(array_filter($stats, static fn ($stat): bool => $stat->playerId()->value() === $substitution->incomingPlayerId()->value()))[0] ?? null;
            self::assertNotNull($outgoing);
            self::assertNotNull($incoming);
            self::assertSame($substitution->minute(), $outgoing->minutes());
            self::assertSame(90 - $substitution->minute(), $incoming->minutes());
        }

        $entry = $story['started'] ? 1 : (int) ($story['substitution_on_minute'] ?? 1);
        $exit = $story['substitution_off_minute'] === null ? 90 : (int) $story['substitution_off_minute'];
        foreach ($story['timeline'] as $event) {
            if ($event['type'] === 'substitution') {
                continue;
            }
            $belongs = $event['player_id'] === $player->id()->value() || $event['assist_player_id'] === $player->id()->value();
            if ($belongs) {
                self::assertGreaterThanOrEqual($entry, $event['minute']);
                self::assertLessThan($exit, $event['minute']);
            }
        }

        $beforeWrites = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();
        self::assertSame($story, $matchService->playerStory($database, $completed->id(), $player->id()));
        $afterWrites = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();
        self::assertSame($beforeWrites, $afterWrites);
        $beforeSubs = array_map(static fn ($substitution): array => $substitution->toArray(), $substitutions);
        unset($database);
        $reloaded = $store->openDatabase('p2039-context');
        self::assertSame($story, $matchService->playerStory($reloaded, $completed->id(), $player->id()));
        self::assertSame($beforeSubs, array_map(static fn ($substitution): array => $substitution->toArray(), (new MatchSubstitutionRepository($reloaded))->byMatch($completed->id())));
    }

    public function testLegacySubstitutionRowsReadWithoutFabricatedContext(): void
    {
        [, $database] = $this->scenario('p2039-legacy-row');
        $database->connection()->exec('CREATE TABLE match_substitutions (match_id TEXT NOT NULL, club_id TEXT NOT NULL, sequence_number INTEGER NOT NULL, outgoing_player_id TEXT NOT NULL, incoming_player_id TEXT NOT NULL, minute INTEGER NOT NULL, PRIMARY KEY (match_id, club_id, sequence_number))');
        new MatchSubstitutionRepository($database);
        $columns = $database->connection()->query('PRAGMA table_info(match_substitutions)')->fetchAll(\PDO::FETCH_COLUMN, 1);
        self::assertContains('reason', $columns);
    }

    /** @return array{0:object,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season,3:object,4:object} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2039001, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $root = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($root, 0775, true);
        $this->roots[] = $root;
        $store = new SqliteSaveStore($root, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);
        $player = $services->playerModule()->service()->create(new PlayerCreationRequest($id . '-player', 'Substitution', 'Context', 'Substitution Context', '2005-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 99, 'prodigy', 2039001, new PlayerAttributeSet(99, 99, 99, 99, 99, 99)));
        $services->playerModule()->service()->repository($database)->save($player);

        return [$services, $database, $season, $player, $store];
    }

    private function addControlledPlayer(object $services, object $database, Season $season, object $player): void
    {
        $services->playerModule()->service()->initializeCareer($database, $player, new CareerPlayerReference(new CareerId('p2039-career'), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::KeyPlayer));
        $contracts = $services->contractModule()->service();
        $contracts->save($database, $contracts->create(new ContractCreationRequest(new ContractId('p2039-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2025-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));
    }
}
