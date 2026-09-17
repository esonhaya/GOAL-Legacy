<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Web;

use Goal\Legacy\Core\Bootstrap\Bootstrap;
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
        $identity = $this->application->handle('POST', '/', [], [
            'action' => 'new_identity', 'save' => 'web-shell-test', 'name' => 'Web Shell Player', 'nation' => $nation,
        ], $session);
        self::assertSame(303, $identity['status']);

        $body = $this->application->handle('POST', '/', [], [
            'action' => 'new_body', 'height' => '180', 'weight' => '75',
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
}
