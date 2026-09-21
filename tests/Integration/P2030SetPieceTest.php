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
use Goal\Legacy\Modules\Club\SetPieceResponsibilityService;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Match\Domain\SimulationFidelity;
use Goal\Legacy\Modules\Player\Domain\CareerId;
use Goal\Legacy\Modules\Player\Domain\CareerPlayerReference;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use PHPUnit\Framework\TestCase;

final class P2030SetPieceTest extends TestCase
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

    public function testPenaltyResponsibilityIsStableClubScopedAndReadSafe(): void
    {
        [$services, $database, $season] = $this->scenario('p2030-responsibility');
        $playerService = $services->playerModule()->service();
        $primary = $this->addSquadPlayer($services, $database, $season, 'p2030-primary', 92, SquadRole::Regular);
        $fallback = $this->addSquadPlayer($services, $database, $season, 'p2030-fallback', 70, SquadRole::KeyPlayer);
        $setPieces = new SetPieceResponsibilityService($services->clubModule()->service());

        $before = (int) $database->connection()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'club_set_piece_responsibilities'")->fetchColumn();
        $legacy = $setPieces->current($database, 'arsenal', $season->id());
        self::assertSame($before, (int) $database->connection()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'club_set_piece_responsibilities'")->fetchColumn());
        self::assertSame($primary->id()->value(), $legacy['penalty_primary_player_id']);
        self::assertSame($fallback->id()->value(), $legacy['penalty_fallback_player_id']);

        $setPieces->ensureClub($database, 'arsenal', $season->id(), $season->startDate());
        $appointment = $setPieces->current($database, 'arsenal', $season->id());
        self::assertSame($primary->id()->value(), $appointment['penalty_primary_player_id']);
        self::assertSame($fallback->id()->value(), $appointment['penalty_fallback_player_id']);
        self::assertSame(SetPieceResponsibilityService::PRIMARY, $setPieces->contextForPlayer($database, $primary->id()->value(), 'arsenal', $season->id())['status']);
        self::assertSame(SetPieceResponsibilityService::FALLBACK, $setPieces->contextForPlayer($database, $fallback->id()->value(), 'arsenal', $season->id())['status']);
        self::assertArrayNotHasKey('specialist_score', $appointment);
        self::assertSame(['direct_free_kick', 'corner'], $setPieces->contextForPlayer($database, $primary->id()->value(), 'arsenal', $season->id())['deferred_categories']);
        self::assertCount(1, $setPieces->historyForPlayer($database, $primary->id()->value()));
        self::assertSame($appointment, $setPieces->current($database, 'arsenal', $season->id()));
    }

    public function testOnlyEligibleOnPitchPlayerCanTakePenalty(): void
    {
        [$services, $database, $season] = $this->scenario('p2030-eligibility');
        $primary = $this->addSquadPlayer($services, $database, $season, 'p2030-eligible-primary', 92, SquadRole::Regular);
        $fallback = $this->addSquadPlayer($services, $database, $season, 'p2030-eligible-fallback', 70, SquadRole::Regular);
        $setPieces = new SetPieceResponsibilityService($services->clubModule()->service());
        $setPieces->ensureClub($database, 'arsenal', $season->id(), $season->startDate());

        self::assertSame($primary->id()->value(), $setPieces->matchTaker($database, 'arsenal', $season->id(), [$primary->id()->value(), $fallback->id()->value()]));
        self::assertSame($fallback->id()->value(), $setPieces->matchTaker($database, 'arsenal', $season->id(), [$fallback->id()->value()]));
        self::assertNull($setPieces->matchTaker($database, 'arsenal', $season->id(), []));
    }

    public function testPenaltyIsARealPlayerMatchFactAndWorldFidelitySkipsDetailedExecution(): void
    {
        [$services, $database, $season] = $this->scenario('p2030-match');
        $player = $services->playerModule()->service()->create(new PlayerCreationRequest(
            'p2030-match-player',
            'Penalty',
            'Taker',
            'p2030-match-player',
            '1998-01-01',
            'england',
            [],
            'england',
            ['england'],
            180,
            75,
            'ST',
            95,
            'regular',
            20301,
            new PlayerAttributeSet(60, 92, 60, 60, 60, 60),
        ));
        $services->playerModule()->service()->initializeCareer(
            $database,
            $player,
            new CareerPlayerReference(new CareerId('p2030-match-career'), $player->id(), $season->startDate()),
            new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::KeyPlayer),
        );
        $services->contractModule()->service()->save($database, $services->contractModule()->service()->create(new ContractCreationRequest(
            new ContractId('p2030-match-contract'),
            $player->id(),
            new ClubId('arsenal'),
            $season->startDate(),
            $season->endDate(),
            100,
            $season->startDate(),
        )));
        $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration(
            $season->id(),
            new CompetitionId('premier-league'),
            new ClubId('arsenal'),
            $player->id(),
        ));

        $fixtures = array_values(array_filter(
            $services->matchModule()->service()->generateFixtures($database, 'premier-league', $season->id()),
            static fn ($match): bool => $match->homeClubId()->value() === 'arsenal' || $match->awayClubId()->value() === 'arsenal',
        ));
        $fixture = null;
        foreach ($fixtures as $candidate) {
            if ($this->unit($candidate->id()->value() . '|arsenal|penalty-opportunity') < 0.12) {
                $fixture = $candidate;
                break;
            }
        }
        self::assertNotNull($fixture, 'Fixture generation should provide a deterministic penalty opportunity sample.');
        $services->matchModule()->service()->simulate($database, $fixture->id(), SimulationFidelity::Player);
        $highlights = $services->matchModule()->service()->highlightRepository($database)->byMatch($fixture->id());
        $penaltyFacts = array_values(array_filter($highlights, static fn ($highlight): bool => ($highlight->data()['set_piece'] ?? null) === 'penalty'));
        self::assertNotSame([], $penaltyFacts);
        self::assertSame($player->id()->value(), $penaltyFacts[0]->playerId()?->value());
        self::assertTrue($services->matchModule()->service()->integrity($database, $fixture->id())['valid']);

        [$worldServices, $worldDatabase, $worldSeason] = $this->scenario('p2030-world');
        $worldFixtures = array_values(array_filter(
            $worldServices->matchModule()->service()->generateFixtures($worldDatabase, 'premier-league', $worldSeason->id()),
            static fn ($match): bool => $match->homeClubId()->value() === 'arsenal' || $match->awayClubId()->value() === 'arsenal',
        ));
        self::assertNotEmpty($worldFixtures);
        $worldServices->matchModule()->service()->simulate($worldDatabase, $worldFixtures[0]->id(), SimulationFidelity::World);
        $worldHighlights = $worldServices->matchModule()->service()->highlightRepository($worldDatabase)->byMatch($worldFixtures[0]->id());
        self::assertSame([], array_values(array_filter($worldHighlights, static fn ($highlight): bool => ($highlight->data()['set_piece'] ?? null) === 'penalty')));
    }

    private function addSquadPlayer($services, $database, Season $season, string $id, int $shooting, SquadRole $role)
    {
        $player = $services->playerModule()->service()->create(new PlayerCreationRequest(
            $id,
            'Set',
            'Piece',
            $id,
            '1998-01-01',
            'england',
            [],
            'england',
            ['england'],
            180,
            75,
            'ST',
            95,
            'regular',
            20300 + strlen($id),
            new PlayerAttributeSet(60, $shooting, 60, 60, 60, 60),
        ));
        $services->playerModule()->service()->repository($database)->save($player);
        $services->clubModule()->service()->squadRepository($database)->save(new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), $role));

        return $player;
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2026030, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $season];
    }

    private function unit(string $key): float
    {
        return hexdec(substr(hash('sha256', $key), 0, 12)) / 281474976710655;
    }
}
