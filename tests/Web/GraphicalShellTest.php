<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Web;

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Modules\Player\Domain\CareerEvent;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\Player\Persistence\CareerEventRepository;
use Goal\Legacy\Modules\Match\Domain\MatchStatus;
use Goal\Legacy\Modules\World\Domain\SeasonId;
use Goal\Legacy\Web\WebApplication;
use PHPUnit\Framework\TestCase;

final class GraphicalShellTest extends TestCase
{
    private WebApplication $application;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        $this->application = new WebApplication($services, $root);
    }

    public function testMainMenuAndNewCareerShellArePlayerFacing(): void
    {
        $session = [];
        $menu = $this->application->handle('GET', '/', ['page' => 'menu'], [], $session);
        self::assertSame(200, $menu['status']);
        self::assertStringContainsString('GOAL: LEGACY', $menu['body']);
        self::assertStringContainsString('New Career', $menu['body']);
        self::assertStringNotContainsString('database', strtolower($menu['body']));

        $new = $this->application->handle('GET', '/', ['page' => 'new', 'step' => 'identity'], [], $session);
        self::assertSame(200, $new['status']);
        self::assertStringContainsString('Identity', $new['body']);
        self::assertStringContainsString('Player name', $new['body']);
    }

    public function testCreatorUsesCanonicalAppearanceFieldsAndRealSvgPreview(): void
    {
        $session = [];
        $nation = (new Bootstrap())->create(dirname(__DIR__, 2), ['APP_ENV' => 'test'])
            ->nationModule()->service()->loadSelected()[0]->id()->value();
        $identityPage = $this->application->handle('GET', '/', ['page' => 'new', 'step' => 'identity'], [], $session);
        $identity = $this->application->handle('POST', '/', [], [
            'action' => 'new_identity', 'save' => 'web-shell-test', 'name' => 'Web Shell Player', 'nation' => $nation, 'token' => $this->tokenFor($identityPage, 'new_identity'),
        ], $session);
        self::assertSame(303, $identity['status']);

        $bodyPage = $this->application->handle('GET', '/', ['page' => 'new', 'step' => 'body'], [], $session);
        $body = $this->application->handle('POST', '/', [], [
            'action' => 'new_body', 'height' => '180', 'weight' => '75', 'token' => $this->tokenFor($bodyPage, 'new_body'),
        ], $session);
        self::assertSame(303, $body['status']);

        $creator = $this->application->handle('GET', '/', ['page' => 'new', 'step' => 'appearance'], [], $session);
        self::assertSame(200, $creator['status']);
        self::assertStringContainsString('Randomize all', $creator['body']);
        self::assertStringContainsString('Facial Hair', $creator['body']);
        self::assertStringContainsString('Skin Detail', $creator['body']);
        self::assertStringContainsString('data-draft-portrait', $creator['body']);
        self::assertStringNotContainsString('facial_hair</option>', $creator['body']);

        $portrait = $this->application->handle('GET', '/', ['page' => 'portrait', 'draft' => '1', 'size' => 256], [], $session);
        self::assertSame(200, $portrait['status']);
        self::assertSame('image/svg+xml; charset=UTF-8', $portrait['headers']['Content-Type']);
        self::assertStringContainsString('viewBox="0 0 512 512"', $portrait['body']);
    }

    public function testGraphicalActionsHaveSafeExitAndDuplicateSubmitProtection(): void
    {
        $session = [];
        $session['web_tokens']['continue_demo'] = 'once';
        $duplicate = $this->application->handle('POST', '/', [], [
            'action' => 'continue', 'save' => 'demo', 'token' => 'wrong',
        ], $session);
        self::assertSame(303, $duplicate['status']);
        self::assertSame('once', $session['web_tokens']['continue_demo']);

        $exit = $this->application->handle('POST', '/', [], ['action' => 'save_exit', 'save' => 'demo'], $session);
        self::assertSame(303, $exit['status']);
        self::assertArrayNotHasKey('web_tokens', $session);
        self::assertArrayNotHasKey('goal_legacy_new_career', $session);

        $menu = $this->application->handle('GET', '/', ['page' => 'menu'], [], $session);
        self::assertStringContainsString('Career saved. Choose Load Career to continue.', $menu['body']);
    }

    public function testMissingCareerLinkReturnsToMenu(): void
    {
        $session = [];
        $response = $this->application->handle('GET', '/', ['page' => 'home', 'save' => 'missing-web-career'], [], $session);
        self::assertSame(303, $response['status']);
        self::assertSame('/?page=menu', $response['headers']['Location']);

        $menu = $this->application->handle('GET', '/', ['page' => 'menu'], [], $session);
        self::assertStringContainsString('That saved career could not be found.', $menu['body']);
    }

    public function testPlayerProfilesUsePublicFieldsAndTheWorldDrillDownRoutes(): void
    {
        $root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($root, ['APP_ENV' => 'test']);
        $save = 'p2-004-profile-test';
        $session = [];
        try {
            $nation = $services->nationModule()->service()->loadSelected()[0]->id()->value();
            $identityPage = $this->application->handle('GET', '/', ['page' => 'new', 'step' => 'identity'], [], $session);
            $this->application->handle('POST', '/', [], [
                'action' => 'new_identity', 'save' => $save, 'name' => 'Profile Test Player', 'nation' => $nation, 'token' => $this->tokenFor($identityPage, 'new_identity'),
            ], $session);
            $bodyPage = $this->application->handle('GET', '/', ['page' => 'new', 'step' => 'body'], [], $session);
            $this->application->handle('POST', '/', [], ['action' => 'new_body', 'height' => '180', 'weight' => '75', 'token' => $this->tokenFor($bodyPage, 'new_body')], $session);
            $profilePage = $this->application->handle('GET', '/', ['page' => 'new', 'step' => 'profile'], [], $session);
            $this->application->handle('POST', '/', [], [
                'action' => 'new_profile', 'position' => 'CM', 'archetype' => 'regular', 'seed' => '24004', 'token' => $this->tokenFor($profilePage, 'new_profile'),
            ], $session);
            $reviewPage = $this->application->handle('GET', '/', ['page' => 'new', 'step' => 'review'], [], $session);
            $this->application->handle('POST', '/', [], ['action' => 'new_youth_view', 'token' => $this->tokenFor($reviewPage, 'new_youth_view')], $session);
            $youth = $this->application->handle('GET', '/', ['page' => 'new', 'step' => 'youth'], [], $session);
            preg_match('/name="club" value="([^"]+)"/', $youth['body'], $clubMatch);
            preg_match('/name="token" value="([^"]+)"/', $youth['body'], $tokenMatch);
            self::assertNotEmpty($clubMatch[1] ?? null);
            self::assertNotEmpty($tokenMatch[1] ?? null);
            $this->application->handle('POST', '/', [], [
                'action' => 'select_club', 'club' => $clubMatch[1], 'token' => $tokenMatch[1],
            ], $session);

            $database = $services->saveStore()->openDatabase($save);
            $career = (new CareerPlayerRepository($database))->get($save);
            $clubMembership = $services->clubModule()->service()->squadRepository($database)->byPlayer($career->playerId(), new SeasonId('season-2024-25'))[0] ?? null;
            self::assertNotNull($clubMembership);
            $fixture = array_values(array_filter($services->matchModule()->service()->repository($database)->byClub($clubMembership->clubId(), new SeasonId('season-2024-25')), static fn ($match): bool => $match->status() === MatchStatus::Scheduled))[0] ?? null;
            self::assertNotNull($fixture);
            $preMatch = $this->application->handle('GET', '/', ['page' => 'matchday', 'save' => $save, 'match' => $fixture->id()->value()], [], $session);
            self::assertSame(200, $preMatch['status']);
            self::assertStringContainsString('PRE-MATCH', $preMatch['body']);
            self::assertStringContainsString('Selection confirmed at kickoff', $preMatch['body']);
            self::assertStringContainsString('Match position', $preMatch['body']);
            preg_match('/name="token" value="([^"]+)"/', $preMatch['body'], $advanceToken);
            self::assertNotEmpty($advanceToken[1] ?? null);
            $advance = $this->application->handle('POST', '/', [], [
                'action' => 'advance_match', 'save' => $save, 'match' => $fixture->id()->value(), 'token' => $advanceToken[1],
            ], $session);
            self::assertSame(303, $advance['status']);
            $matchPage = $this->application->handle('GET', '/', ['page' => 'matchday', 'save' => $save, 'match' => $fixture->id()->value()], [], $session);
            self::assertSame(200, $matchPage['status']);
            self::assertStringContainsString('FINAL RESULT', $matchPage['body']);
            self::assertStringContainsString('PLAYER STATUS', $matchPage['body']);
            self::assertStringContainsString('Not selected', $matchPage['body']);
            self::assertStringNotContainsString('RATING EXPLANATION', $matchPage['body']);
            self::assertStringContainsString('MATCH STORY', $matchPage['body']);
            self::assertStringContainsString('YOUR MATCH', $matchPage['body']);
            $changesBeforeRefresh = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();
            $refreshedMatchPage = $this->application->handle('GET', '/', ['page' => 'matchday', 'save' => $save, 'match' => $fixture->id()->value()], [], $session);
            self::assertSame(200, $refreshedMatchPage['status']);
            self::assertSame($changesBeforeRefresh, (int) $database->connection()->query('SELECT total_changes()')->fetchColumn());
            $duplicateAdvance = $this->application->handle('POST', '/', [], [
                'action' => 'advance_match', 'save' => $save, 'match' => $fixture->id()->value(), 'token' => $advanceToken[1],
            ], $session);
            self::assertSame(303, $duplicateAdvance['status']);
            self::assertSame('completed', (new \Goal\Legacy\Modules\Match\Persistence\MatchRepository($database))->get($fixture->id())->status()->value);
            $invalidMatch = $this->application->handle('GET', '/', ['page' => 'matchday', 'save' => $save, 'match' => 'missing-match'], [], $session);
            self::assertSame(404, $invalidMatch['status']);
            self::assertStringContainsString('No simulation was rerun', $invalidMatch['body']);
            $home = $this->application->handle('GET', '/', ['page' => 'home', 'save' => $save], [], $session);
            self::assertSame(200, $home['status']);
            self::assertStringContainsString('Public profile', $home['body']);
            self::assertStringContainsString('Transfer Market', $home['body']);
            self::assertStringContainsString('Manager relationship', $home['body']);
            self::assertStringContainsString('On-pitch role', $home['body']);
            self::assertStringContainsString('READINESS', $home['body']);
            self::assertStringContainsString('Workload', $home['body']);
            self::assertStringContainsString('Trophy Room', $home['body']);
            self::assertStringContainsString('NEXT UP', $home['body']);
            self::assertStringContainsString('RECENT RESULT', $home['body']);
            self::assertStringContainsString('Review Match', $home['body']);
            self::assertStringContainsString('CURRENT STATUS', $home['body']);
            self::assertStringContainsString('NEEDS ATTENTION', $home['body']);
            self::assertStringContainsString('RECENT STORY', $home['body']);
            self::assertStringContainsString('Player Profile', $home['body']);
            self::assertStringContainsString('CAREER DECISION HUB', $home['body']);
            self::assertStringContainsString('UNDER CONTRACT', strtoupper($home['body']));
            self::assertStringContainsString('CAREER OUTLOOK', $home['body']);
            self::assertStringNotContainsString('No active Club manager', $home['body']);
            $training = $this->application->handle('GET', '/', ['page' => 'training', 'save' => $save], [], $session);
            self::assertSame(200, $training['status']);
            self::assertStringContainsString('Current football state', $training['body']);
            self::assertStringContainsString('DEVELOPMENT FEEDBACK', $training['body']);
            self::assertStringContainsString('No recent attribute or OVR change is retained.', $training['body']);
            $pulseSources = (int) $database->connection()->query('SELECT COUNT(*) FROM pulse_feed_sources')->fetchColumn();
            $pulsePosts = (int) $database->connection()->query('SELECT COUNT(*) FROM pulse_posts')->fetchColumn();
            $pulse = $this->application->handle('GET', '/', ['page' => 'pulse', 'save' => $save], [], $session);
            self::assertSame(200, $pulse['status']);
            self::assertStringContainsString('PULSE FEED', $pulse['body']);
            self::assertStringContainsString('Public reaction derived from canonical football facts', $pulse['body']);
            self::assertSame($pulseSources, (int) $database->connection()->query('SELECT COUNT(*) FROM pulse_feed_sources')->fetchColumn());
            self::assertSame($pulsePosts, (int) $database->connection()->query('SELECT COUNT(*) FROM pulse_posts')->fetchColumn());
            $market = $this->application->handle('GET', '/', ['page' => 'market', 'save' => $save], [], $session);
            self::assertSame(200, $market['status']);
            self::assertStringContainsString('TRANSFER MARKET', $market['body']);
            self::assertStringContainsString('CAREER DECISION HUB', $market['body']);
            self::assertStringContainsString('CURRENT STATUS', $market['body']);
            self::assertStringContainsString('CAREER OUTLOOK', $market['body']);
            self::assertStringContainsString('Market stature', $market['body']);
            $controlled = $this->application->handle('GET', '/', ['page' => 'profile', 'save' => $save, 'player' => $career->playerId()->value()], [], $session);
            self::assertSame(200, $controlled['status']);
            self::assertStringContainsString('CURRENT SEASON', $controlled['body']);
            self::assertStringContainsString('Training focus', $controlled['body']);
            self::assertStringContainsString('PLAYER DEVELOPMENT', $controlled['body']);
            self::assertStringContainsString('Current attributes', $controlled['body']);
            self::assertStringContainsString('CURRENT PLAYER', $controlled['body']);
            self::assertStringContainsString('FOOTBALL IDENTITY', $controlled['body']);
            self::assertStringContainsString('DEVELOPMENT SIGNAL', $controlled['body']);
            self::assertStringContainsString('Pace', $controlled['body']);
            self::assertStringContainsString('Career stage', $controlled['body']);
            self::assertStringContainsString('Readiness', $controlled['body']);
            self::assertStringContainsString('ON-PITCH ROLE', $controlled['body']);
            self::assertStringContainsString('Set preferred role', $controlled['body']);
            self::assertStringContainsString('PLAYING STYLE', $controlled['body']);
            self::assertStringContainsString('Open Trophy Room', $controlled['body']);
            self::assertStringContainsString('CAREER DECISION HUB', $controlled['body']);
            self::assertStringNotContainsString('Potential', $controlled['body']);
            $roleUpdate = $this->application->handle('POST', '/', [], [
                'action' => 'set_on_pitch_role', 'save' => $save, 'role' => 'box_to_box_midfielder', 'token' => $this->tokenFor($controlled, 'set_on_pitch_role'),
            ], $session);
            self::assertSame(303, $roleUpdate['status']);
            $roleProfile = $this->application->handle('GET', '/', ['page' => 'profile', 'save' => $save, 'player' => $career->playerId()->value()], [], $session);
            self::assertStringContainsString('Box-to-Box Midfielder', $roleProfile['body']);
            $careerHistory = $this->application->handle('GET', '/', ['page' => 'career', 'save' => $save], [], $session);
            self::assertSame(200, $careerHistory['status']);
            self::assertStringContainsString('CAREER STORY', $careerHistory['body']);
            self::assertStringContainsString('CAREER ARC', $careerHistory['body']);
            self::assertStringContainsString('SEASON HISTORY', $careerHistory['body']);
            self::assertStringContainsString('DEVELOPMENT HISTORY', $careerHistory['body']);
            self::assertStringContainsString('CAREER HIGHLIGHTS', $careerHistory['body']);
            self::assertStringContainsString('data-season-status="current"', $careerHistory['body']);
            $legacy = $this->application->handle('GET', '/', ['page' => 'legacy', 'save' => $save], [], $session);
            self::assertSame(200, $legacy['status']);
            self::assertStringContainsString('CAREER LEGACY', $legacy['body']);
            self::assertStringContainsString('PLAYING IDENTITY', $legacy['body']);
            self::assertStringContainsString('CAREER TIMELINE', $legacy['body']);
            self::assertStringContainsString('DEFINING SEASONS', $legacy['body']);
            self::assertStringContainsString('No earned honours yet.', $legacy['body']);
            $seasonReview = $this->application->handle('GET', '/', ['page' => 'season-review', 'save' => $save], [], $session);
            self::assertSame(200, $seasonReview['status']);
            self::assertStringContainsString('No completed Season review', $seasonReview['body']);
            $schemaBeforeTrophyRoom = (int) $database->connection()->query('SELECT COUNT(*) FROM sqlite_master')->fetchColumn();
            $trophyRoom = $this->application->handle('GET', '/', ['page' => 'trophies', 'save' => $save], [], $session);
            self::assertSame(200, $trophyRoom['status']);
            self::assertStringContainsString('TROPHY ROOM', $trophyRoom['body']);
            self::assertStringContainsString('CAREER RECORDS', $trophyRoom['body']);
            self::assertStringContainsString('PERSONAL BESTS', $trophyRoom['body']);
            self::assertStringContainsString('No Club honours yet.', $trophyRoom['body']);
            self::assertSame($schemaBeforeTrophyRoom, (int) $database->connection()->query('SELECT COUNT(*) FROM sqlite_master')->fetchColumn());

            $finances = $this->application->handle('GET', '/', ['page' => 'finances', 'save' => $save], [], $session);
            self::assertSame(200, $finances['status']);
            self::assertStringContainsString('GC 50', $finances['body']);
            self::assertStringContainsString('Wages arrive through simulated calendar time', $finances['body']);
            $lifestyle = $this->application->handle('GET', '/', ['page' => 'lifestyle', 'save' => $save], [], $session);
            self::assertSame(200, $lifestyle['status']);
            self::assertStringContainsString('Training bicycle', $lifestyle['body']);
            preg_match('/name="token" value="([^"]+)"/', $lifestyle['body'], $purchaseToken);
            self::assertNotEmpty($purchaseToken[1] ?? null);
            $purchase = $this->application->handle('POST', '/', [], [
                'action' => 'purchase_lifestyle', 'save' => $save, 'item' => 'transport.bicycle', 'price' => '1', 'confirm' => '1', 'token' => $purchaseToken[1],
            ], $session);
            self::assertSame(303, $purchase['status']);
            $afterPurchase = $this->application->handle('GET', '/', ['page' => 'finances', 'save' => $save], [], $session);
            self::assertStringContainsString('GC 20', $afterPurchase['body']);
            self::assertStringContainsString('Training bicycle', $afterPurchase['body']);
            $duplicatePurchase = $this->application->handle('POST', '/', [], [
                'action' => 'purchase_lifestyle', 'save' => $save, 'item' => 'transport.bicycle', 'confirm' => '1', 'token' => $purchaseToken[1],
            ], $session);
            self::assertSame(303, $duplicatePurchase['status']);
            $afterDuplicate = $this->application->handle('GET', '/', ['page' => 'finances', 'save' => $save], [], $session);
            self::assertStringContainsString('GC 20', $afterDuplicate['body']);

            $npc = (string) $database->connection()->query("SELECT id FROM player_records WHERE id <> '" . $career->playerId()->value() . "' ORDER BY id LIMIT 1")->fetchColumn();
            $npcProfile = $this->application->handle('GET', '/', ['page' => 'profile', 'save' => $save, 'player' => $npc], [], $session);
            self::assertSame(200, $npcProfile['status']);
            self::assertStringContainsString('MATCH HISTORY', $npcProfile['body']);
            self::assertStringContainsString('PLAYING STYLE', $npcProfile['body']);
            self::assertStringNotContainsString('Potential', $npcProfile['body']);
            self::assertStringNotContainsString('Balance', $npcProfile['body']);

            $world = $this->application->handle('GET', '/', ['page' => 'world', 'save' => $save], [], $session);
            self::assertStringContainsString('page=competition', $world['body']);
            preg_match('/page=competition&amp;save=' . preg_quote($save, '/') . '&amp;competition=([^"&]+)/', $world['body'], $competitionMatch);
            self::assertNotEmpty($competitionMatch[1] ?? null);
            $leaderboardDmlBefore = (int) $database->connection()->query('SELECT total_changes()')->fetchColumn();
            $competitionPage = $this->application->handle('GET', '/', ['page' => 'competition', 'save' => $save, 'competition' => html_entity_decode($competitionMatch[1])], [], $session);
            self::assertSame($leaderboardDmlBefore, (int) $database->connection()->query('SELECT total_changes()')->fetchColumn());
            self::assertSame(200, $competitionPage['status']);
            self::assertStringContainsString('STATISTICAL LEADERS', $competitionPage['body']);
            $international = $this->application->handle('GET', '/', ['page' => 'international', 'save' => $save], [], $session);
            self::assertSame(200, $international['status']);
            self::assertStringContainsString('National Teams', $international['body']);
            preg_match('/page=national-team&amp;save=' . preg_quote($save, '/') . '&amp;team=([^"&]+)/', $international['body'], $teamMatch);
            self::assertNotEmpty($teamMatch[1] ?? null);
            $nationalTeam = $this->application->handle('GET', '/', ['page' => 'national-team', 'save' => $save, 'team' => $teamMatch[1]], [], $session);
            self::assertSame(200, $nationalTeam['status']);
            self::assertStringContainsString('NATIONAL TEAM', $nationalTeam['body']);
            $clubPage = $this->application->handle('GET', '/', ['page' => 'club', 'save' => $save, 'club' => $clubMatch[1]], [], $session);
            self::assertStringContainsString('Open Squad', $clubPage['body']);

            // Exercise the canonical free-agent transition before reading the
            // profile: historical squad rows must not masquerade as a current
            // Club after the active Contract ends.
            $world = $services->worldModule()->service()->load($database, $save);
            $membership = $services->clubModule()->service()->squadRepository($database)->byPlayer($career->playerId(), $world->currentSeasonId())[0];
            $contract = $services->contractModule()->service()->activeForPlayer($database, $career->playerId()->value());
            self::assertNotNull($contract);
            $services->clubModule()->service()->squadRepository($database)->remove($membership);
            $services->contractModule()->service()->save($database, $contract->terminate());
            $services->competitionModule()->service()->registrationRepository($database)->unregisterByPlayerClubSeason($career->playerId(), $membership->clubId(), $world->currentSeasonId());
            $services->playerModule()->service()->socialService()->recordTransfer($database, $career->playerId(), $membership->clubId()->value(), null, $world->currentDate($services->worldModule()->service()->calendar()));
            $freeProfile = $this->application->handle('GET', '/', ['page' => 'profile', 'save' => $save, 'player' => $career->playerId()->value()], [], $session);
            self::assertSame(200, $freeProfile['status']);
            self::assertStringContainsString('Free Agent', $freeProfile['body']);

            $pending = CareerEvent::pending(
                'p2011-web-pending-event',
                $career->playerId(),
                $world->currentSeasonId(),
                $world->currentDate($services->worldModule()->service()->calendar()),
                'p2011-web-pending-source',
                'manager',
                'p2011-pending',
                'A meaningful Career decision',
                'Resolve this football context before advancing the Career.',
                [['id' => 'acknowledge', 'label' => 'Acknowledge']],
                ['club_id' => $clubMatch[1]],
            );
            $database->transaction(function () use ($database, $pending): void {
                (new CareerEventRepository($database))->saveInTransaction($pending);
            });
            $pendingHome = $this->application->handle('GET', '/', ['page' => 'home', 'save' => $save], [], $session);
            self::assertSame(200, $pendingHome['status']);
            self::assertStringContainsString('Resolve career event', $pendingHome['body']);
            self::assertStringNotContainsString('name="action" value="continue"', $pendingHome['body']);
        } finally {
            $path = $root . '/game/saves/' . $save . '.sqlite';
            if (is_file($path)) { unlink($path); }
        }
    }

    /** @param array{body:string} $response */
    private function tokenFor(array $response, string $action): string
    {
        preg_match('/<input type="hidden" name="action" value="' . preg_quote($action, '/') . '">.*?<input type="hidden" name="token" value="([^"]+)"/s', $response['body'], $matches);
        self::assertNotEmpty($matches[1] ?? null, 'Expected a one-use token for ' . $action . '.');

        return html_entity_decode((string) $matches[1], ENT_QUOTES | ENT_HTML5);
    }
}
