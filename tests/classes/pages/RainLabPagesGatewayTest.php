<?php namespace Renick\TailorCompanion\Tests\Classes\Pages;

use Cms\Classes\Theme;
use Config;
use PluginTestCase;
use Renick\TailorCompanion\Classes\Pages\PagesFeature;
use Renick\TailorCompanion\Classes\Pages\RainLabPagesGateway;
use System\Classes\SiteManager;

/**
 * Installed-gated: reproduces the pr11 outage where the API request context has
 * no resolvable active theme (cms.active_theme points at a non-existent theme),
 * so the gateway must fall back to the site definition's own theme column.
 */
class RainLabPagesGatewayTest extends PluginTestCase
{
    protected ?string $originalConfigTheme = null;
    protected $originalSiteTheme = null;

    public function setUp(): void
    {
        parent::setUp();
        $this->originalConfigTheme = Config::get('cms.active_theme');
    }

    public function tearDown(): void
    {
        Config::set('cms.active_theme', $this->originalConfigTheme);
        if ($site = SiteManager::instance()->getPrimarySite()) {
            $site->theme = $this->originalSiteTheme;
            $site->save();
        }
        Theme::resetCache();
        SiteManager::instance()->resetCache();
        parent::tearDown();
    }

    public function testResolvesThemeFromSiteWhenActiveThemeIsUnresolvable()
    {
        if (!PagesFeature::isInstalled()) {
            $this->markTestSkipped('RainLab.Pages not installed.');
        }

        $realTheme = Config::get('cms.active_theme'); // companion in the test env
        $site = SiteManager::instance()->getPrimarySite();
        $this->originalSiteTheme = $site->theme;

        // Simulate a bare API context: cms.active_theme resolves to a theme that
        // does not exist → Theme::getActiveTheme() returns null.
        Config::set('cms.active_theme', 'this-theme-does-not-exist');
        Theme::resetCache();
        $this->assertNull(Theme::getActiveTheme(), 'Precondition: active theme must be null');

        // But the site knows its theme — the gateway must use it.
        $site->theme = $realTheme;
        $site->save();
        SiteManager::instance()->resetCache();

        $gateway = new RainLabPagesGateway;

        $this->assertNotEmpty($gateway->layouts(), 'Layouts must resolve via the site theme fallback');
        // The companion theme ships a static page tree fixture.
        $this->assertNotEmpty($gateway->tree(), 'Page tree must resolve via the site theme fallback');
    }

    public function testReturnsEmptyWhenNoThemeResolvesAtAll()
    {
        $site = SiteManager::instance()->getPrimarySite();
        $this->originalSiteTheme = $site->theme;

        Config::set('cms.active_theme', 'this-theme-does-not-exist');
        $site->theme = null;
        $site->save();
        Theme::resetCache();
        SiteManager::instance()->resetCache();

        $gateway = new RainLabPagesGateway;

        // No theme anywhere → empty, never a crash.
        $this->assertSame([], $gateway->layouts());
        $this->assertSame([], $gateway->tree());
        $this->assertSame([], $gateway->menus());
        $this->assertNull($gateway->page('whatever'));
    }
}
