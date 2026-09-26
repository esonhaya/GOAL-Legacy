<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Web;

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Simulation\SimulationCheckpoint;
use Goal\Legacy\Devtools\Presentation\CareerPresentationService;
use Goal\Legacy\Devtools\Simulation\GoalMatchRunner;
use Goal\Legacy\Modules\Contract\Persistence\ContractRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerDevelopmentRepository;
use Goal\Legacy\Modules\Player\Persistence\PlayerRepository;
use Goal\Legacy\Modules\Player\PlayerCareerProgressionQuery;
use Goal\Legacy\Modules\Player\Finance\PlayerFinanceRepository;
use Goal\Legacy\Modules\Match\Domain\MatchId;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\Match\Persistence\MatchRepository;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Tests\Support\CareerJourneyRunner;
use Goal\Legacy\Web\WebApplication;
use PHPUnit\Framework\TestCase;

final class P3016CareerJourneyTest extends TestCase
{
    public function testFixedProductionJourneyReachesFirstMatchAndSurvivesReload(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        $application = new WebApplication($services, $root);
        $runner = new CareerJourneyRunner($application);
        $saveId = 'p3016-primary-31601';
        $seed = 31601;
        $position = 'CM';
        $archetype = 'regular';
        $checkpoints = [];

        try {
            $nation = $services->nationModule()->service()->loadSelected()[0]->id()->value();
            $identity = $runner->get('new', ['step' => 'identity']);
            self::assertSame(200, $identity['status']);
            $runner->post('new_identity', ['save' => $saveId, 'name' => 'Journey Gate Player', 'nation' => $nation]);
            $body = $runner->get('new', ['step' => 'body']);
            $runner->post('new_body', ['height' => 180, 'weight' => 75]);
            $appearance = $runner->get('new', ['step' => 'appearance']);
            $runner->post('new_appearance', ['mode' => 'save']);
            $profile = $runner->get('new', ['step' => 'profile']);
            self::assertStringContainsString('Regular:', $profile['body']);
            self::assertStringContainsString('actual progress depends', strtolower($profile['body']));
            $runner->post('new_profile', ['position' => $position, 'archetype' => $archetype, 'seed' => $seed]);
            $review = $runner->get('new', ['step' => 'review']);
            self::assertStringContainsString('Starting OVR', $review['body']);
            self::assertStringNotContainsString('Potential', $review['body']);
            $runner->post('new_youth_view');
            $youth = $runner->get('new', ['step' => 'youth']);
            preg_match('/name="club" value="([^"]+)"/', $youth['body'], $clubMatch);
            self::assertNotEmpty($clubMatch[1] ?? null);
            $clubToken = $runner->tokenFor($youth, 'select_club');
            self::assertNotNull($clubToken);
            $started = $runner->post('select_club', ['club' => $clubMatch[1], 'token' => $clubToken]);
            self::assertSame(303, $started['status']);
            self::assertSame('/?page=home&save=' . $saveId, $started['headers']['Location'] ?? null);

            $database = $services->saveStore()->openDatabase($saveId);
            $career = (new CareerPlayerRepository($database))->get($saveId);
            $playerId = $career->playerId()->value();
            $player = (new PlayerRepository($database))->get($playerId);
            $world = $services->worldModule()->service()->load($database, $saveId);
            $seasonId = $world->currentSeasonId();
            self::assertNotNull($seasonId);
            $membership = $services->clubModule()->service()->squadRepository($database)->byPlayer($player->id(), $seasonId)[0] ?? null;
            self::assertNotNull($membership);
            self::assertSame($clubMatch[1], $membership->clubId()->value());
            $contract = (new ContractRepository($database))->activeForPlayer($playerId);
            self::assertNotNull($contract);
            self::assertSame($clubMatch[1], $contract->clubId()->value());
            self::assertSame(1, (int) $database->connection()->query('SELECT COUNT(*) FROM career_player_references')->fetchColumn());
            self::assertSame(1, (int) $database->connection()->query('SELECT COUNT(*) FROM player_finance_state')->fetchColumn());
            $summary = (new PlayerCareerProgressionQuery($services->clubModule()->service()))->summary($database, $player->id(), $world->currentDate($services->worldModule()->service()->calendar()), $seasonId);
            $checkpoints[] = new SimulationCheckpoint('AFTER_CAREER_START', 'P3-016_JOURNEY', $this->controlledState($summary), ['seed' => $seed, 'position' => $position, 'archetype' => $archetype]);
            self::assertSame($playerId, $summary['player']['id']);
            self::assertSame($clubMatch[1], $summary['current_club']['id']);
            self::assertSame('active', $summary['career_state']);
            self::assertSame('season-2024-25', $summary['current_season_id']);
            self::assertSame(50, (new PlayerFinanceRepository($database))->state($playerId)['balance'] ?? null);

            unset($database);
            $database = $services->saveStore()->openDatabase($saveId);
            $homeBefore = $runner->get('home', ['save' => $saveId]);
            self::assertSame(200, $homeBefore['status']);
            self::assertStringContainsString('NEXT UP', $homeBefore['body']);
            self::assertStringContainsString('No recent attribute or OVR change is retained.', $homeBefore['body']);
            self::assertStringNotContainsString('transfer request', strtolower($homeBefore['body']));
            self::assertStringContainsString('Career stage', $homeBefore['body']);

            $training = $runner->get('training', ['save' => $saveId]);
            $trainingResult = $runner->post('set_training', ['save' => $saveId, 'focus' => 'passing']);
            self::assertSame(303, $trainingResult['status']);
            $trainingAfter = $runner->get('training', ['save' => $saveId]);
            self::assertStringContainsString('Passing', $trainingAfter['body']);
            $database = $services->saveStore()->openDatabase($saveId);
            self::assertSame('passing', (new PlayerDevelopmentRepository($database))->state($player->id())->currentFocus()?->value);

            $snapshot = (new CareerPresentationService($services))->snapshot($database, $saveId, false);
            $next = $snapshot['summary']['next_scheduled_match'] ?? null;
            self::assertIsArray($next);
            self::assertNotEmpty($next['match_id'] ?? null);
            $preMatch = $runner->get('matchday', ['save' => $saveId, 'match' => $next['match_id']]);
            self::assertSame(200, $preMatch['status']);
            self::assertStringContainsString('PRE-MATCH', $preMatch['body']);
            $advanced = $runner->post('advance_match', ['save' => $saveId, 'match' => $next['match_id']]);
            self::assertSame(303, $advanced['status']);
            $postMatch = $runner->get('matchday', ['save' => $saveId, 'match' => $next['match_id']]);
            self::assertSame(200, $postMatch['status']);
            self::assertStringContainsString('YOUR MATCH', $postMatch['body']);
            $afterMatchDml = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();
            $refreshed = $runner->get('matchday', ['save' => $saveId, 'match' => $next['match_id']]);
            self::assertSame($afterMatchDml, (int) $database->connection()->query('SELECT total_changes()')->fetchColumn());
            $database = $services->saveStore()->openDatabase($saveId);
            $completedMatch = (new MatchRepository($database))->get(new MatchId($next['match_id']));
            self::assertSame(MatchStatus::Completed, $completedMatch->status());

            $matchResult = (new GoalMatchRunner($services))->many($database, $saveId, $playerId, 2);
            self::assertSame('PASS', $matchResult->status());
            self::assertCount(4, $matchResult->checkpoints());
            $checkpoints = array_merge($checkpoints, $matchResult->checkpoints());
            self::assertSame(2, (int) ($matchResult->metrics()['matches_completed'] ?? 0));

            $database = $services->saveStore()->openDatabase($saveId);
            $endSnapshot = (new CareerPresentationService($services))->snapshot($database, $saveId, false);
            $endSummary = $endSnapshot['summary'];
            $checkpoints[] = new SimulationCheckpoint('END_OF_PRIMARY_JOURNEY', 'P3-016_JOURNEY', $this->controlledState($endSummary), ['fixtures' => 3]);
            self::assertSame($playerId, $endSummary['player']['id']);
            self::assertSame($clubMatch[1], $endSummary['current_club']['id']);
            $completedClubMatches = array_filter(
                (new MatchRepository($database))->byClub($clubMatch[1], $seasonId),
                static fn (object $match): bool => $match->status() === MatchStatus::Completed,
            );
            self::assertGreaterThanOrEqual(3, count($completedClubMatches));
            self::assertArrayHasKey('season_stats', $endSummary);
            self::assertNotEmpty($checkpoints);
        } finally {
            $path = $root . '/game/saves/' . $saveId . '.sqlite';
            if (is_file($path)) { unlink($path); }
        }
    }

    public function testCreationReplayAndTamperedProfileAreRejectedWithoutSave(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        $runner = new CareerJourneyRunner(new WebApplication($services, $root));
        $saveId = 'p3016-replay-31602';
        try {
            $nation = $services->nationModule()->service()->loadSelected()[0]->id()->value();
            $identity = $runner->get('new', ['step' => 'identity']);
            $payload = ['save' => $saveId, 'name' => 'Replay Gate Player', 'nation' => $nation];
            $first = $runner->post('new_identity', $payload);
            self::assertSame(303, $first['status']);
            $replay = $runner->post('new_identity', $payload);
            self::assertSame(303, $replay['status']);
            self::assertFalse($services->saveStore()->exists($saveId));

            $body = $runner->get('new', ['step' => 'body']);
            self::assertSame(200, $body['status']);
            $runner->post('new_body', ['height' => 180, 'weight' => 75]);
            $appearance = $runner->get('new', ['step' => 'appearance']);
            $runner->post('new_appearance', ['mode' => 'save']);
            $profile = $runner->get('new', ['step' => 'profile']);
            $tampered = $runner->post('new_profile', ['position' => 'LIBERO', 'archetype' => 'regular', 'seed' => 31602]);
            self::assertSame(303, $tampered['status']);
            self::assertFalse($services->saveStore()->exists($saveId));
        } finally {
            $path = $root . '/game/saves/' . $saveId . '.sqlite';
            if (is_file($path)) { unlink($path); }
        }
    }

    /** @param array<string,mixed> $summary @return array<string,mixed> */
    private function controlledState(array $summary): array
    {
        return [
            'player_id' => $summary['player']['id'] ?? null,
            'club_id' => is_array($summary['current_club'] ?? null) ? ($summary['current_club']['id'] ?? null) : null,
            'contract_club' => is_array($summary['current_contract'] ?? null) && is_array($summary['current_contract']['club'] ?? null) ? ($summary['current_contract']['club']['id'] ?? null) : null,
            'ovr' => $summary['current_ovr'] ?? null,
            'role' => $summary['current_role'] ?? null,
            'availability' => $summary['availability'] ?? null,
            'season_id' => $summary['current_season_id'] ?? null,
            'appearances' => $summary['season_stats']['appearances'] ?? 0,
            'minutes' => $summary['season_stats']['minutes'] ?? 0,
        ];
    }
}
