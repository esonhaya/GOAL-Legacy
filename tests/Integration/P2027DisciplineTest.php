<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Integration;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Devtools\Presentation\CareerPresentationService;
use Goal\Legacy\Modules\Club\Domain\ClubId;
use Goal\Legacy\Modules\Club\Domain\ClubSquadMembership;
use Goal\Legacy\Modules\Club\Domain\SquadRole;
use Goal\Legacy\Modules\Competition\Domain\CompetitionId;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\MatchResult;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\Domain\PlayerSelection;
use Goal\Legacy\Modules\Match\Domain\SelectionStatus;
use Goal\Legacy\Modules\Match\MatchSelectionService;
use Goal\Legacy\Modules\Match\Persistence\MatchSelectionRepository;
use Goal\Legacy\Modules\Match\Persistence\PlayerMatchStatRepository;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\Player\Domain\PlayerAttributeSet;
use Goal\Legacy\Modules\Player\Domain\PlayerCreationRequest;
use Goal\Legacy\Modules\Competition\Domain\PlayerRegistration;
use Goal\Legacy\Modules\Competition\Persistence\PlayerRegistrationRepository;
use Goal\Legacy\Modules\Player\PlayerDisciplineService;
use Goal\Legacy\Modules\Player\Persistence\PlayerDisciplineRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\World\Domain\Season;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use Goal\Legacy\Modules\World\Domain\World;
use Goal\Legacy\Modules\World\Domain\WorldId;
use Goal\Legacy\Modules\World\Persistence\SeasonRepository;
use PHPUnit\Framework\TestCase;

final class P2027DisciplineTest extends TestCase
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

    public function testRedBanIsCompetitionScopedServedOnceAndIdempotent(): void
    {
        [$services, $database, $season, $player] = $this->scenario('p2027-red');
        $service = new PlayerDisciplineService();
        $red = $this->completedMatch($database, $season, $player->id()->value(), 'p2027-red-match', 'premier-league', '2024-08-10', 0, 1, SelectionStatus::Starter);
        $service->processCompletedMatchInTransaction($database, $red);
        $service->processCompletedMatchInTransaction($database, $red);

        $league = $service->eligibility($database, $this->scheduledMatch($season, 'p2027-next-league', 'premier-league', '2024-08-11'), $player->id());
        $cup = $service->eligibility($database, $this->scheduledMatch($season, 'p2027-next-cup', 'domestic-cup-england', '2024-08-11'), $player->id());
        $europe = $service->eligibility($database, $this->scheduledMatch($season, 'p2027-next-europe', 'europe-tier-1', '2024-08-11'), $player->id());
        $international = $service->eligibility($database, $this->scheduledMatch($season, 'p2027-next-international', 'world-championship', '2024-08-11'), $player->id());
        self::assertFalse($league['eligible']);
        self::assertSame(1, $league['remaining']);
        self::assertTrue($cup['eligible']);
        self::assertTrue($europe['eligible']);
        self::assertTrue($international['eligible']);

        $serve = $this->completedMatch($database, $season, $player->id()->value(), 'p2027-serve', 'premier-league', '2024-08-17', 0, 0, SelectionStatus::Suspended);
        $service->processCompletedMatchInTransaction($database, $serve);
        self::assertTrue($service->eligibility($database, $this->scheduledMatch($season, 'p2027-return', 'premier-league', '2024-08-24'), $player->id())['eligible']);
        self::assertSame(2, (int) $database->connection()->query("SELECT COUNT(*) FROM player_discipline_sources WHERE player_id = 'p2027-player'")->fetchColumn());
        unset($services);
    }

    public function testYellowAccumulationResetsForNewSeasonWhileActiveBanCarries(): void
    {
        [$services, $database, $season, $player] = $this->scenario('p2027-rollover');
        $service = new PlayerDisciplineService();
        for ($i = 1; $i <= 4; ++$i) {
            $match = $this->completedMatch($database, $season, $player->id()->value(), 'p2027-rollover-yellow-' . $i, 'premier-league', '2024-08-' . str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT), 0, 0, SelectionStatus::Starter, 1, 1);
            $service->processCompletedMatchInTransaction($database, $match);
        }
        $nextSeason = new Season(new SeasonId('season-2025-26'), '2025/26', SimulationDate::fromIsoString('2025-08-01'), SimulationDate::fromIsoString('2026-05-31'));
        (new SeasonRepository($database))->save($nextSeason);
        $newSeasonYellow = $this->completedMatch($database, $nextSeason, $player->id()->value(), 'p2027-rollover-new-season', 'premier-league', '2025-08-10', 0, 0, SelectionStatus::Starter, 1, 1, false);
        $service->processCompletedMatchInTransaction($database, $newSeasonYellow);
        $state = (new PlayerDisciplineRepository($database))->find($player->id()->value(), 'domestic_league');
        self::assertNotNull($state);
        self::assertSame(1, (int) $state['yellow_count']);
        self::assertSame(0, (int) $state['suspension_matches_remaining']);

        $red = $this->completedMatch($database, $nextSeason, $player->id()->value(), 'p2027-rollover-red', 'premier-league', '2025-08-17', 0, 1, SelectionStatus::Starter, 0, 0, false);
        $service->processCompletedMatchInTransaction($database, $red);
        $serving = $this->completedMatch($database, $nextSeason, $player->id()->value(), 'p2027-rollover-serve', 'premier-league', '2025-08-24', 0, 0, SelectionStatus::Suspended, 0, 0, false);
        $service->processCompletedMatchInTransaction($database, $serving);
        self::assertTrue($service->eligibility($database, $this->scheduledMatch($nextSeason, 'p2027-rollover-return', 'premier-league', '2025-08-31'), $player->id())['eligible']);
        unset($services);
    }

    public function testFiveYellowCardsCreateOneLeagueBanAndResetAccumulation(): void
    {
        [$services, $database, $season, $player] = $this->scenario('p2027-yellow');
        $service = new PlayerDisciplineService();
        for ($i = 1; $i <= 5; ++$i) {
            $match = $this->completedMatch($database, $season, $player->id()->value(), 'p2027-yellow-' . $i, 'premier-league', '2024-08-' . str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT), 0, 0, SelectionStatus::Starter, 1, 1);
            $service->processCompletedMatchInTransaction($database, $match);
        }
        $state = (new PlayerDisciplineRepository($database))->find($player->id()->value(), 'domestic_league');
        self::assertNotNull($state);
        self::assertSame(0, (int) $state['yellow_count']);
        self::assertSame(1, (int) $state['suspension_matches_remaining']);
        self::assertSame('yellow_accumulation', $state['suspension_reason']);
        self::assertSame('Yellow-card accumulation suspension', $service->context($database, $player->id())['suspensions'][0]['reason_label']);
        unset($services);
    }

    public function testSelectionStorySeparatesSuspensionFromMedicalUnavailability(): void
    {
        [$services, $database, $season, $player] = $this->scenario('p2027-boundary');
        $service = new PlayerDisciplineService();
        $red = $this->completedMatch($database, $season, $player->id()->value(), 'p2027-boundary-red', 'premier-league', '2024-08-10', 0, 1, SelectionStatus::Starter);
        $service->processCompletedMatchInTransaction($database, $red);
        $next = $this->scheduledMatch($season, 'p2027-boundary-next', 'premier-league', '2024-08-11');
        $selections = (new MatchSelectionService($services->clubModule()->service()))->select($database, $next, [$player->id()->value() => true]);
        $selection = array_values(array_filter($selections, static fn (PlayerSelection $value): bool => $value->playerId()->value() === 'p2027-player'))[0] ?? null;
        self::assertNotNull($selection);
        self::assertSame(SelectionStatus::Suspended, $selection->status());
        $storySelection = new MatchSelectionRepository($database);
        $database->transaction(fn (): mixed => $storySelection->replaceForMatchInTransaction([
            $selection,
        ]));
        $story = (new \Goal\Legacy\Modules\Match\MatchStoryService())->playerStory($database, $next, $player->id());
        self::assertSame('suspended', $story['participation_state']);
        self::assertSame('Suspended — disciplinary eligibility', $story['participation_label']);
        self::assertSame('SUSPENDED', $service->context($database, $player->id())['status_label']);
        self::assertSame(0, (int) $database->connection()->query("SELECT COUNT(*) FROM player_discipline_sources WHERE player_id = 'p2027-player' AND match_id = 'p2027-boundary-next'")->fetchColumn());
        unset($services);
    }

    public function testFriendlyAndLegacyStateDoNotFabricateSanctions(): void
    {
        [$services, $database, $season, $player] = $this->scenario('p2027-legacy');
        $service = new PlayerDisciplineService();
        $legacy = $service->context($database, $player->id());
        self::assertFalse($legacy['active']);
        self::assertTrue($service->eligibility($database, $this->scheduledMatch($season, 'p2027-friendly', 'friendly-tournament', '2024-08-11'), $player->id())['eligible']);
        self::assertSame(0, (new PlayerDisciplineRepository($database))->byPlayer($player->id()->value()) === [] ? 0 : 1);
        unset($services);
    }

    public function testCareerReadModelsExposeActiveScopeWithoutWritingNarrativeRows(): void
    {
        [$services, $database, $season, $player, $store, $saveId] = $this->scenario('p2027-presentation');
        $service = new PlayerDisciplineService();
        $red = $this->completedMatch($database, $season, $player->id()->value(), 'p2027-presentation-red', 'premier-league', '2024-08-10', 0, 1, SelectionStatus::Starter);
        $service->processCompletedMatchInTransaction($database, $red);
        $presentation = new CareerPresentationService($services);
        $summary = $presentation->snapshot($database, $saveId)['summary'];
        self::assertTrue($summary['discipline']['active']);
        self::assertSame('League', $summary['discipline']['suspensions'][0]['scope_label']);
        self::assertCount(1, $summary['discipline']['suspensions']);
        $profile = $presentation->playerProfile($database, $saveId, $player->id()->value());
        self::assertSame('SUSPENDED', $profile['discipline']['status_label']);
        unset($store, $services);
    }

    public function testActiveSanctionSurvivesSaveReloadWithoutChangingScope(): void
    {
        [$services, $database, $season, $player, $store, $saveId] = $this->scenario('p2027-reload');
        $service = new PlayerDisciplineService();
        $red = $this->completedMatch($database, $season, $player->id()->value(), 'p2027-reload-red', 'premier-league', '2024-08-10', 0, 1, SelectionStatus::Starter);
        $service->processCompletedMatchInTransaction($database, $red);
        unset($database);
        $reloaded = $store->openDatabase($saveId);
        $context = (new PlayerDisciplineService())->context($reloaded, $player->id());
        self::assertTrue($context['active']);
        self::assertSame('League', $context['suspensions'][0]['scope_label']);
        self::assertFalse((new PlayerDisciplineService())->eligibility($reloaded, $this->scheduledMatch($season, 'p2027-reload-next', 'premier-league', '2024-08-17'), $player->id())['eligible']);
        self::assertTrue((new PlayerDisciplineService())->eligibility($reloaded, $this->scheduledMatch($season, 'p2027-reload-cup', 'domestic-cup-england', '2024-08-17'), $player->id())['eligible']);
        unset($services, $reloaded);
    }

    /** @return array{0:\Goal\Legacy\Core\Bootstrap\CoreServices,1:\Goal\Legacy\Core\Persistence\DatabaseInterface,2:Season,3:\Goal\Legacy\Modules\Player\Domain\Player} */
    private function scenario(string $id): array
    {
        $services = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $nations = $services->nationModule()->service()->loadSelected();
        $competitions = $services->competitionModule()->service()->loadSelected();
        $season = new Season(new SeasonId('season-2024-25'), '2024/25', SimulationDate::fromIsoString('2024-08-01'), SimulationDate::fromIsoString('2025-05-31'));
        $calendar = $services->worldModule()->service()->calendar();
        $world = new World(new WorldId($id), $id, 2027027, new DateTimeImmutable('@0'), $calendar->timeAt(SimulationDate::fromIsoString('2024-07-31')), $season->id(), array_map(static fn ($nation): string => $nation->id()->value(), $nations), array_map(static fn ($competition): string => $competition->id()->value(), $competitions), $services->contentPackages()->selectedIds());
        $root = sys_get_temp_dir() . '/' . $id . '-' . bin2hex(random_bytes(8));
        mkdir($root, 0775, true); $this->roots[] = $root;
        $store = new SqliteSaveStore($root, new JsonSerializer());
        $store->create(SaveMetadata::create($id, $id, $world->currentTime(), new DateTimeImmutable('@0')));
        $database = $store->openDatabase($id);
        $services->worldModule()->service()->initialize($database, $world, $season);
        $player = $services->playerModule()->service()->create(new PlayerCreationRequest('p2027-player', 'Discipline', 'Player', 'Discipline Player', '2000-01-01', 'england', [], 'england', ['england'], 180, 75, 'ST', 90, 'regular', 12027, new PlayerAttributeSet(70, 70, 70, 70, 70, 70)));
        (new PlayerRepository($database))->save($player);
        $services->playerModule()->service()->initializeCareer($database, $player, new \Goal\Legacy\Modules\Player\Domain\CareerPlayerReference(new \Goal\Legacy\Modules\Player\Domain\CareerId($id), $player->id(), SimulationDate::fromIsoString('2024-07-31')), new ClubSquadMembership(new ClubId('arsenal'), $player->id(), $season->id(), SquadRole::Regular));
        $contracts = $services->contractModule()->service();
        $contracts->save($database, $contracts->create(new \Goal\Legacy\Modules\Contract\Domain\ContractCreationRequest(new \Goal\Legacy\Modules\Contract\Domain\ContractId($id . '-contract'), $player->id(), new ClubId('arsenal'), SimulationDate::fromIsoString('2024-07-31'), SimulationDate::fromIsoString('2025-06-30'), 100, SimulationDate::fromIsoString('2024-07-31'))));
        (new PlayerRegistrationRepository($database))->register(new PlayerRegistration($season->id(), new CompetitionId('premier-league'), new ClubId('arsenal'), $player->id()));

        return [$services, $database, $season, $player, $store, $id];
    }

    private function scheduledMatch(Season $season, string $id, string $competitionId, string $date): GameMatch
    {
        return new GameMatch(new MatchId($id), new CompetitionId($competitionId), $season->id(), 1, SimulationDate::fromIsoString($date), new ClubId('arsenal'), new ClubId('chelsea'));
    }

    private function completedMatch($database, Season $season, string $playerId, string $id, string $competitionId, string $date, int $goals, int $redCards, SelectionStatus $selectionStatus, int $yellowCards = 0, int $foulsCommitted = 0, bool $persist = true): GameMatch
    {
        $match = $this->scheduledMatch($season, $id, $competitionId, $date)->complete(new MatchResult(2, 0));
        if ($persist) { (new MatchRepository($database))->save($match); }
        $database->transaction(fn (): mixed => (new MatchSelectionRepository($database))->replaceForMatchInTransaction([new PlayerSelection($match->id(), new \Goal\Legacy\Modules\Player\Domain\PlayerId($playerId), new ClubId('arsenal'), $selectionStatus)]));
        if ($selectionStatus !== SelectionStatus::Suspended) {
            (new PlayerMatchStatRepository($database))->replaceForMatch([new PlayerMatchStat(matchId: $match->id(), playerId: new \Goal\Legacy\Modules\Player\Domain\PlayerId($playerId), clubId: new ClubId('arsenal'), appeared: true, started: true, minutes: 90, goals: $goals, assists: 0, shots: max($goals, 1), shotsOnTarget: max($goals, 1), foulsCommitted: $foulsCommitted, yellowCards: $yellowCards, redCards: $redCards)]);
        }

        return $match;
    }
}
