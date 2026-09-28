<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Web;

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\SqlProfiler;
use Goal\Legacy\Devtools\Presentation\CareerPresentationService;
use Goal\Legacy\Devtools\Simulation\GoalScenarioBuilder;
use Goal\Legacy\Modules\Player\Persistence\CareerOpportunityRepository;
use Goal\Legacy\Tests\Support\CareerJourneyRunner;
use Goal\Legacy\Web\WebApplication;
use PHPUnit\Framework\TestCase;

final class P5005PlayerExperienceGateTest extends TestCase
{
    public function testCareerLoopUsesProductionRoutesAndSurvivesCanonicalReload(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        $fixture = (new GoalScenarioBuilder($services))->build('HEALTHY_LOW_MINUTES', 5005);
        $profiler = new SqlProfiler();
        $application = new WebApplication($services, $root, $fixture->store(), $profiler);
        $runner = new CareerJourneyRunner($application);
        $saveId = $fixture->saveId();

        try {
            $home = $this->readOnlyGet($runner, $profiler, 'home', ['save' => $saveId]);
            self::assertStringContainsString('NEXT UP', $home['body']);
            self::assertStringContainsString('CAREER DECISION HUB', $home['body']);
            self::assertStringContainsString('data-career-identity', $home['body']);

            $training = $this->readOnlyGet($runner, $profiler, 'training', ['save' => $saveId]);
            self::assertStringContainsString('DEVELOPMENT FEEDBACK', $training['body']);
            $trainingUpdate = $runner->post('set_training', ['save' => $saveId, 'focus' => 'passing']);
            self::assertSame(303, $trainingUpdate['status']);
            $trainingAfter = $this->readOnlyGet($runner, $profiler, 'training', ['save' => $saveId]);
            self::assertStringContainsString('Passing', $trainingAfter['body']);

            $fixture->reload();
            $snapshot = (new CareerPresentationService($services))->snapshot($fixture->database(), $saveId, false);
            $next = $snapshot['summary']['next_scheduled_match'] ?? null;
            self::assertIsArray($next);
            self::assertNotEmpty($next['match_id'] ?? null);

            $homeBeforeMatch = $this->readOnlyGet($runner, $profiler, 'home', ['save' => $saveId]);
            self::assertStringContainsString('NEXT UP', $homeBeforeMatch['body']);
            $continued = $runner->post('continue', ['save' => $saveId]);
            self::assertSame(303, $continued['status']);
            self::assertStringContainsString('page=matchday', (string) ($continued['headers']['Location'] ?? ''));

            $preMatch = $this->readOnlyGet($runner, $profiler, 'matchday', ['save' => $saveId, 'match' => $next['match_id']]);
            self::assertStringContainsString('PRE-MATCH', $preMatch['body']);
            self::assertStringContainsString('FIXTURE CONTEXT', $preMatch['body']);
            self::assertStringContainsString('PLAYER STATUS', $preMatch['body']);
            $advanced = $runner->post('advance_match', ['save' => $saveId, 'match' => $next['match_id']]);
            self::assertSame(303, $advanced['status']);

            $postMatch = $this->readOnlyGet($runner, $profiler, 'matchday', ['save' => $saveId, 'match' => $next['match_id']]);
            self::assertStringContainsString('FINAL RESULT', $postMatch['body']);
            self::assertStringContainsString('YOUR MATCH', $postMatch['body']);
            self::assertStringContainsString('CAREER IMPACT', $postMatch['body']);

            $homeAfterMatch = $this->readOnlyGet($runner, $profiler, 'home', ['save' => $saveId]);
            self::assertStringContainsString('RECENT RESULT', $homeAfterMatch['body']);
            self::assertStringContainsString('Review Match', $homeAfterMatch['body']);
            self::assertStringContainsString('CAREER OUTLOOK', $homeAfterMatch['body']);

            $profile = $this->readOnlyGet($runner, $profiler, 'profile', ['save' => $saveId, 'player' => $fixture->playerId()->value()]);
            self::assertStringContainsString('CURRENT PLAYER', $profile['body']);
            self::assertStringContainsString('CURRENT SEASON', $profile['body']);
            self::assertStringContainsString('CAREER DECISION HUB', $profile['body']);

            $decisionSurface = $this->readOnlyGet($runner, $profiler, 'market', ['save' => $saveId]);
            self::assertStringContainsString('Contract &amp; career mobility', $decisionSurface['body']);
            self::assertStringContainsString('CAREER DECISION HUB', $decisionSurface['body']);

            $history = $this->readOnlyGet($runner, $profiler, 'career', ['save' => $saveId]);
            self::assertStringContainsString('SEASON HISTORY', $history['body']);
            self::assertStringContainsString('DEVELOPMENT HISTORY', $history['body']);
            $seasonReview = $this->readOnlyGet($runner, $profiler, 'season-review', ['save' => $saveId]);
            self::assertStringContainsString('CAREER REVIEW', $seasonReview['body']);
            $trophies = $this->readOnlyGet($runner, $profiler, 'trophies', ['save' => $saveId]);
            self::assertStringContainsString('TROPHY ROOM', $trophies['body']);

            $fixture->reload();
            $reloaded = (new CareerPresentationService($services))->snapshot($fixture->database(), $saveId, false)['summary'];
            self::assertSame($fixture->playerId()->value(), $reloaded['player']['id'] ?? null);
            self::assertSame($fixture->clubId(), $reloaded['current_club']['id'] ?? null);
            self::assertSame('active', $reloaded['career_state'] ?? null);
            $finalHome = $this->readOnlyGet($runner, $profiler, 'home', ['save' => $saveId]);
            self::assertStringContainsString('NEXT UP', $finalHome['body']);
            self::assertStringContainsString('data-career-identity', $finalHome['body']);
        } finally {
            $fixture->close();
        }
    }

    public function testCanonicalContractDecisionRouteAndStaleSubmissionProtection(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        $fixture = (new GoalScenarioBuilder($services))->build('CONTRACT_EXPIRING', 5006);
        $application = new WebApplication($services, $root, $fixture->store());
        $runner = new CareerJourneyRunner($application);
        $saveId = $fixture->saveId();

        try {
            $database = $fixture->database();
            $worldService = $services->worldModule()->service();
            $world = $worldService->load($database, $saveId);
            $currentSeason = $worldService->seasonRepository($database)->get($world->currentSeasonId());
            $nextSeason = $worldService->seasonRollover()?->nextSeason($currentSeason);
            $membership = $services->clubModule()->service()->squadRepository($database)->byPlayer($fixture->playerId(), $currentSeason->id())[0] ?? null;
            self::assertNotNull($nextSeason);
            self::assertNotNull($membership);
            $worldService->seasonRepository($database)->save($nextSeason);
            $activeContract = $services->contractModule()->service()->activeForPlayer($database, $fixture->playerId()->value());
            self::assertNotNull($activeContract);
            $services->contractModule()->service()->save($database, $activeContract->terminate());

            $opportunity = $services->transferModule()->service()->careerMovement()->prepareContractDecision(
                $database,
                $fixture->playerId(),
                $currentSeason,
                $nextSeason,
                $world->currentDate($worldService->calendar()),
                $membership,
                true,
            );
            self::assertNotNull($opportunity);

            $decision = $runner->get('decision', ['save' => $saveId]);
            self::assertSame(200, $decision['status']);
            self::assertStringContainsString('A decision is waiting', $decision['body']);
            self::assertStringContainsString('BEFORE YOU CHOOSE', $decision['body']);
            self::assertStringContainsString('Renew with your current Club', $decision['body']);
            self::assertStringContainsString('Enter free agency', $decision['body']);
            $token = $runner->tokenFor($decision, 'resolve_decision');
            self::assertNotNull($token);

            $resolved = $runner->post('resolve_decision', ['save' => $saveId, 'choice' => '1', 'token' => $token]);
            self::assertSame(303, $resolved['status']);
            $home = $runner->get('home', ['save' => $saveId]);
            self::assertSame(200, $home['status']);
            self::assertStringContainsString('Contract accepted with', $home['body']);

            $fixture->reload();
            $reloaded = (new CareerPresentationService($services))->snapshot($fixture->database(), $saveId, false)['summary'];
            self::assertSame('active', $reloaded['career_state'] ?? null);
            self::assertSame('resolved', (new CareerOpportunityRepository($fixture->database()))->get($opportunity->id())?->status()->value);

            $stale = $runner->post('resolve_decision', ['save' => $saveId, 'choice' => '1', 'token' => $token]);
            self::assertSame(303, $stale['status']);
            $staleHome = $runner->get('home', ['save' => $saveId]);
            self::assertStringContainsString('already been submitted', strtolower($staleHome['body']));
        } finally {
            $fixture->close();
        }
    }

    public function testActiveTransferRequestIsPresentedAsStateRatherThanFreshAction(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        $fixture = (new GoalScenarioBuilder($services))->build('TRANSFER_REQUESTED', 5007);
        $application = new WebApplication($services, $root, $fixture->store());

        try {
            $session = [];
            $market = $application->handle('GET', '/', ['page' => 'market', 'save' => $fixture->saveId()], [], $session);
            self::assertSame(200, $market['status']);
            self::assertStringContainsString('Transfer request active', $market['body']);
            self::assertStringContainsString('name="action" value="withdraw_transfer"', $market['body']);
            self::assertStringNotContainsString('name="action" value="request_transfer"', $market['body']);
        } finally {
            $fixture->close();
        }
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    private function readOnlyGet(CareerJourneyRunner $runner, SqlProfiler $profiler, string $page, array $query): array
    {
        $profiler->reset();
        $response = $runner->get($page, $query);
        self::assertSame(200, $response['status'], 'Expected a successful GET for ' . $page . '.');
        self::assertSame(0, $this->dmlCalls($profiler), 'GET ' . $page . ' must remain read-only.');

        return $response;
    }

    private function dmlCalls(SqlProfiler $profiler): int
    {
        $calls = 0;
        foreach ($profiler->snapshot()['queries'] as $query) {
            if (preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\b/i', trim((string) ($query['fingerprint'] ?? ''))) === 1) {
                $calls += (int) ($query['calls'] ?? 0);
            }
        }

        return $calls;
    }
}
