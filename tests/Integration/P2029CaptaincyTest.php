<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Modules\Club\ClubCaptaincyService;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest;
use Goal\Legacy\Modules\Contract\Domain\ContractId;
use Goal\Legacy\Modules\Match\Domain\SelectionStatus;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
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

final class P2029CaptaincyTest extends TestCase
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

    public function testAppointmentIsClubScopedStableAndReadFallbackDoesNotWrite(): void
    {
        [$services, $database, $season] = $this->scenario('p2029-appointment');
        $playerService = $services->playerModule()->service();
        $players = [];
        for ($index = 1; $index <= 20; ++$index) {
            $id = 'captain-player-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $player = $playerService->create(new PlayerCreationRequest($id, 'Captain', 'Player', $id, '1998-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 95, 'regular', 12000 + $index, new PlayerAttributeSet(60, 60, 60, 60, 60, 60)));
            $playerService->repository($database)->save($player);
            $role = $index === 1 ? SquadRole::KeyPlayer : ($index === 2 ? SquadRole::Regular : SquadRole::Prospect);
            $membership = new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), $role);
            $services->clubModule()->service()->squadRepository($database)->save($membership);
            $services->contractModule()->service()->save($database, $services->contractModule()->service()->create(new ContractCreationRequest(new ContractId('captain-contract-' . $index), $player->id(), new ClubId('arsenal'), $season->startDate(), $season->endDate(), 100, $season->startDate())));
            $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));
            $players[] = $player;
        }
        $captaincy = new ClubCaptaincyService($services->clubModule()->service());
        $before = (int) $database->connection()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'club_captaincy_appointments'")->fetchColumn();
        $fallback = $captaincy->current($database, 'arsenal', $season->id());
        self::assertSame($before, (int) $database->connection()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'club_captaincy_appointments'")->fetchColumn());
        self::assertSame($players[0]->id()->value(), $fallback['captain_player_id']);
        $captaincy->ensureClub($database, 'arsenal', $season->id(), $season->startDate());
        $appointment = $captaincy->current($database, 'arsenal', $season->id());
        self::assertSame($players[0]->id()->value(), $appointment['captain_player_id']);
        self::assertSame($players[1]->id()->value(), $appointment['vice_captain_player_id']);
        self::assertSame([], array_filter($captaincy->historyForPlayer($database, $players[2]->id()->value())));
        self::assertSame($appointment, $captaincy->current($database, 'arsenal', $season->id()));
    }

    public function testOnlySelectedStartingCaptainIsSnapshottedAndNoMatchEffectIsAdded(): void
    {
        [$services, $database, $season] = $this->scenario('p2029-match');
        $playerService = $services->playerModule()->service();
        $controlled = $playerService->create(new PlayerCreationRequest('career-captain', 'Senior', 'Captain', 'career-captain', '1998-01-01', 'england', [], 'england', ['england'], 180, 75, 'CM', 95, 'regular', 13001, new PlayerAttributeSet(60, 60, 60, 60, 60, 60)));
        $playerService->initializeCareer($database, $controlled, new CareerPlayerReference(new CareerId('p2029-match'), $controlled->id(), $season->startDate()), new ClubSquadMembership(new ClubId('arsenal'), $controlled->id(), $season->id(), SquadRole::KeyPlayer));
        $contract = $services->contractModule()->service()->create(new ContractCreationRequest(new ContractId('p2029-match-contract'), $controlled->id(), new ClubId('arsenal'), $season->startDate(), $season->endDate(), 100, $season->startDate()));
        $services->contractModule()->service()->save($database, $contract);
        $services->competitionModule()->service()->registrationRepository($database)->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $controlled->id()));
        $captaincy = new ClubCaptaincyService($services->clubModule()->service());
        $captaincy->ensureClub($database, 'arsenal', $season->id(), $season->startDate());
        $fixture = array_values(array_filter($services->matchModule()->service()->generateFixtures($database, 'premier-league', $season->id()), static fn ($match): bool => $match->homeClubId()->value() === 'arsenal' || $match->awayClubId()->value() === 'arsenal'))[0];
        $services->matchModule()->service()->simulate($database, $fixture->id());
        $selections = array_values(array_filter((new MatchSelectionRepository($database))->byMatch($fixture->id()), static fn ($selection): bool => $selection->clubId()->value() === 'arsenal'));
        self::assertCount(1, array_filter($selections, static fn ($selection): bool => $selection->isCaptain()));
        $captain = array_values(array_filter($selections, static fn ($selection): bool => $selection->isCaptain()))[0];
        self::assertSame(SelectionStatus::Starter, $captain->status());
        self::assertSame($captain->playerId()->value(), $controlled->id()->value());
        $story = $services->matchModule()->service()->playerStory($database, $fixture->id(), $controlled->id());
        self::assertTrue($story['captain']);
        self::assertArrayNotHasKey('captaincy_score', $story);
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2026029, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $directory = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->roots[] = $directory;
        $store = new SqliteSaveStore($directory, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);

        return [$services, $database, $season];
    }
}
