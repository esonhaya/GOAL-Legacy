<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Web;

use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Web\WebApplication;
use Goal\Legacy\Web\WebView;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class P3018UiHardeningTest extends TestCase
{
    private string $root;
    private WebApplication $application;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($this->root, ['APP_ENV' => 'test']);
        $this->application = new WebApplication($services, $this->root);
    }

    public function testSharedLayoutExposesSkipLinkMainLandmarkAndCurrentNavigation(): void
    {
        $dom = $this->dom(WebView::layout('Career Home', '<h1>Career Home</h1>', 'ui-hardening', 'home'));
        $xpath = new DOMXPath($dom);

        $this->assertUniqueIds($dom);
        self::assertCount(1, $xpath->query('//a[@href="#main-content"]'));
        self::assertCount(1, $xpath->query('//main[@id="main-content"]'));
        self::assertCount(1, $xpath->query('//nav[@aria-label="Career navigation"]'));
        self::assertCount(1, $xpath->query('//a[@aria-current="page" and normalize-space(.)="Career Home"]'));
        self::assertCount(1, $xpath->query('//main[@id="main-content"]//h1'));
    }

    public function testCareerShellKeepsPrimaryDestinationsAndIdentityTogether(): void
    {
        $identity = WebView::careerIdentity([
            'name' => 'Shell Player',
            'club' => 'Example FC',
            'position' => 'CM',
            'age' => 19,
            'ovr' => 64,
            'role' => 'Regular',
            'status' => 'Active',
        ]);
        $dom = $this->dom(WebView::layout('Profile', $identity . '<h1>Shell Player</h1>', 'ui-hardening', 'profile'));
        $xpath = new DOMXPath($dom);

        self::assertCount(1, $xpath->query('//section[@data-career-identity and @aria-label="Career identity"]'));
        self::assertCount(1, $xpath->query('//nav[@aria-label="Career navigation"]//a[normalize-space(.)="Profile" and @aria-current="page"]'));
        self::assertCount(1, $xpath->query('//nav[@aria-label="Career navigation"]//a[normalize-space(.)="Training"]'));
        self::assertCount(1, $xpath->query('//nav[@aria-label="Career navigation"]//a[normalize-space(.)="Career History"]'));
        self::assertCount(1, $xpath->query('//details[contains(@class,"nav-more")]'));
        self::assertStringContainsString('Shell Player', $dom->saveHTML() ?: '');
    }

    public function testCreationPageUsesOneHeadingNativeLabelsAndPostForms(): void
    {
        $session = [];
        $response = $this->application->handle('GET', '/', ['page' => 'new', 'step' => 'identity'], [], $session);
        self::assertSame(200, $response['status']);

        $dom = $this->dom($response['body']);
        $xpath = new DOMXPath($dom);
        $this->assertUniqueIds($dom);
        self::assertCount(1, $xpath->query('//main[@id="main-content"]'));
        self::assertCount(1, $xpath->query('//main[@id="main-content"]//h1'));
        self::assertGreaterThanOrEqual(1, $xpath->query('//form[@method="post"]')->length);

        $controls = $xpath->query('//form//*[self::input or self::select or self::textarea][not(self::input[@type="hidden"])]');
        self::assertNotFalse($controls);
        foreach ($controls as $control) {
            self::assertInstanceOf(DOMElement::class, $control);
            self::assertTrue(
                $this->hasAccessibleLabel($control, $xpath),
                'Every visible creation control must have a native or explicit accessible label.'
            );
        }
    }

    public function testSharedMutationHelperRemainsPostOnlyAndButtonBased(): void
    {
        $dom = $this->dom(WebView::form('set_training', 'Save training', [
            'save' => 'ui-hardening',
            'token' => 'test-token',
        ]));
        $xpath = new DOMXPath($dom);

        self::assertCount(1, $xpath->query('//form[@method="post"]'));
        self::assertCount(1, $xpath->query('//form//input[@name="action" and @value="set_training"]'));
        self::assertCount(1, $xpath->query('//form//input[@name="save" and @value="ui-hardening"]'));
        self::assertCount(1, $xpath->query('//form//input[@name="token" and @value="test-token"]'));
        self::assertCount(1, $xpath->query('//form//button[@type="submit" and normalize-space(.)="Save training"]'));
    }

    public function testResponsiveStylesProvideFocusWrappingAndBoundedTableContracts(): void
    {
        $css = file_get_contents($this->root . '/game/public/assets/app.css');
        self::assertIsString($css);
        self::assertStringContainsString('.skip-link', $css);
        self::assertStringContainsString('a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible', $css);
        self::assertStringContainsString('.choice-card input:focus-visible + span', $css);
        self::assertStringContainsString('grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr)', $css);
        self::assertStringContainsString('overflow-wrap: anywhere', $css);
        self::assertStringContainsString('.table-scroll { overflow-x: auto; }', $css);
        self::assertStringContainsString('@media (max-width: 640px)', $css);
        self::assertStringContainsString('@media (max-width: 390px)', $css);
        self::assertStringContainsString('min-height: 44px', $css);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
        self::assertStringContainsString('.career-identity', $css);
        self::assertStringContainsString('.recent-result-card', $css);
        self::assertStringContainsString('.match-status-card', $css);
        self::assertStringContainsString('.match-contribution-stats', $css);
        self::assertStringContainsString('.post-match-next', $css);
        self::assertStringContainsString('.profile-facts', $css);
        self::assertStringContainsString('.progression-change-list', $css);
        self::assertStringContainsString('.career-season-card', $css);
        self::assertStringContainsString('.career-highlight-list', $css);
    }

    private function dom(string $html): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        self::assertTrue($loaded);

        return $dom;
    }

    private function hasAccessibleLabel(DOMElement $control, DOMXPath $xpath): bool
    {
        if ($control->hasAttribute('aria-label') || $control->hasAttribute('aria-labelledby')) {
            return true;
        }

        for ($parent = $control->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if ($parent instanceof DOMElement && strtolower($parent->tagName) === 'label') {
                return true;
            }
        }

        $id = $control->getAttribute('id');
        return $id !== '' && $xpath->query('//label[@for="' . str_replace('"', '&quot;', $id) . '"]')->length > 0;
    }

    private function assertUniqueIds(DOMDocument $dom): void
    {
        $ids = [];
        foreach ($dom->getElementsByTagName('*') as $element) {
            if (!$element instanceof DOMElement || !$element->hasAttribute('id')) {
                continue;
            }
            $id = $element->getAttribute('id');
            self::assertArrayNotHasKey($id, $ids, 'Rendered page contains duplicate id="' . $id . '".');
            $ids[$id] = true;
        }
    }
}
