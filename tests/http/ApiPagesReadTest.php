<?php namespace Renick\TailorCompanion\Tests\Http;

use Backend\Models\User;
use PluginTestCase;
use Renick\TailorCompanion\Classes\Auth\TokenManager;
use Renick\TailorCompanion\Classes\Pages\PagesFeature;
use Renick\TailorCompanion\Tests\Fakes\PagesTestGateway;

require_once __DIR__ . '/../fakes/PagesTestGateway.php';

/**
 * Read-endpoint coverage. Most cases run everywhere by forcing the feature on
 * with an in-memory gateway (no RainLab needed); a couple assert the real
 * fixtures when RainLab.Pages is installed.
 */
class ApiPagesReadTest extends PluginTestCase
{
    protected array $authHeader;
    protected PagesTestGateway $gateway;

    public function setUp(): void
    {
        parent::setUp();

        $user = new User;
        $user->first_name = 'Pages';
        $user->last_name = 'Reader';
        $user->login = 'pagesreader';
        $user->email = 'pagesreader@example.com';
        $user->password = 'pages-pass-1234';
        $user->password_confirmation = 'pages-pass-1234';
        $user->is_superuser = true;
        $user->is_activated = true;
        $user->save();

        $result = (new TokenManager)->issue($user);
        $this->authHeader = ['Authorization' => 'Bearer ' . $result['token']];

        $this->gateway = new PagesTestGateway;
        PagesFeature::forceAvailability(true);
        PagesFeature::setGateway($this->gateway);
    }

    public function tearDown(): void
    {
        PagesFeature::forceAvailability(null);
        PagesFeature::setGateway(null);
        parent::tearDown();
    }

    protected function seedSimpleTheme(): void
    {
        $this->gateway->layoutList = [[
            'fileName' => 'static-default',
            'description' => 'Static default',
            'useContent' => true,
            'markup' => '{variable name="hero" label="Hero" type="text"}{/variable}<article>{% page %}</article>{% placeholder sidebar type="html" %}',
        ]];

        $this->gateway->pages = [
            'home' => [
                'fileName' => 'home',
                'viewBag' => ['title' => 'Home', 'url' => '/', 'layout' => 'static-default', 'hero' => 'Hi', 'legacy' => 'x'],
                'markup' => '<p>Body</p>',
                'code' => "{% put sidebar %}\n<p>Side</p>\n{% endput %}",
                'mtime' => 1710000000,
                'contentHash' => 'hash-home',
            ],
            'about' => [
                'fileName' => 'about',
                'viewBag' => ['title' => 'About', 'url' => '/about', 'layout' => 'static-default'],
                'markup' => '',
                'code' => '',
                'mtime' => 1710000001,
                'contentHash' => 'hash-about',
            ],
        ];

        $this->gateway->treeNodes = [
            ['fileName' => 'home', 'children' => [
                ['fileName' => 'about', 'children' => []],
            ]],
        ];

        $this->gateway->menuList = [['code' => 'main', 'name' => 'Main', 'contentHash' => 'hm']];
        $this->gateway->menuData = ['main' => [
            'code' => 'main',
            'name' => 'Main',
            'items' => [['title' => 'Home', 'type' => 'static-page', 'reference' => 'home']],
        ]];
    }

    public function testTreeReturnsNestedNodesWithHashes()
    {
        $this->seedSimpleTheme();

        $response = $this->getJson('/api/tailor-companion/v1/pages/tree', $this->authHeader);

        $response->assertStatus(200);
        $response->assertJsonPath('data.pages.0.file_name', 'home');
        $response->assertJsonPath('data.pages.0.content_hash', 'hash-home');
        $response->assertJsonPath('data.pages.0.children.0.file_name', 'about');
        $this->assertArrayNotHasKey('fields', $response->json('data.pages.0'));
    }

    public function testShowReturnsFullPageWithPartitionedFields()
    {
        $this->seedSimpleTheme();

        $response = $this->getJson('/api/tailor-companion/v1/pages/file/home', $this->authHeader);

        $response->assertStatus(200);
        $response->assertJsonPath('data.title', 'Home');
        $response->assertJsonPath('data.fields.hero', 'Hi');
        $response->assertJsonPath('data.fields.placeholder:sidebar', '<p>Side</p>');
        $response->assertJsonPath('data.fields.markup', '<p>Body</p>');
        $response->assertJsonPath('data.viewbag_extra.legacy', 'x');
    }

    public function testShowUnknownPageIs404()
    {
        $this->seedSimpleTheme();

        $response = $this->getJson('/api/tailor-companion/v1/pages/file/nope', $this->authHeader);

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'page_not_found');
    }

    public function testMenusListAndDetail()
    {
        $this->seedSimpleTheme();

        $this->getJson('/api/tailor-companion/v1/pages/menus', $this->authHeader)
            ->assertStatus(200)
            ->assertJsonPath('data.0.code', 'main')
            // content_hash must be snake_case to match the client wire contract
            ->assertJsonStructure(['data' => [['code', 'name', 'content_hash']]]);

        $this->getJson('/api/tailor-companion/v1/pages/menus/main', $this->authHeader)
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.reference', 'home');
    }

    public function testUnknownMenuIs404()
    {
        $this->seedSimpleTheme();

        $this->getJson('/api/tailor-companion/v1/pages/menus/nope', $this->authHeader)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'menu_not_found');
    }

    // -- Installed-gated: real companion fixtures ----------------------------

    public function testRealFixtureTreeRoundTrips()
    {
        if (!PagesFeature::isInstalled()) {
            $this->markTestSkipped('RainLab.Pages not installed.');
        }

        // Use the real gateway against the companion theme fixtures.
        PagesFeature::setGateway(null);

        $tree = $this->getJson('/api/tailor-companion/v1/pages/tree', $this->authHeader);
        $tree->assertStatus(200);
        $files = collect($tree->json('data.pages'))->pluck('file_name');
        $this->assertTrue($files->contains('info'));

        // Hashes are stable across two reads.
        $again = $this->getJson('/api/tailor-companion/v1/pages/tree', $this->authHeader);
        $this->assertSame($tree->json('data.pages.0.content_hash'), $again->json('data.pages.0.content_hash'));

        $detail = $this->getJson('/api/tailor-companion/v1/pages/file/info', $this->authHeader);
        $detail->assertStatus(200);
        $detail->assertJsonPath('data.title', 'Info');
        $this->assertSame('Good to know', $detail->json('data.fields.hero_heading'));
        $this->assertStringContainsString('Need help', $detail->json('data.fields.placeholder:sidebar'));
    }
}
