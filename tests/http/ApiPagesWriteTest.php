<?php namespace Renick\TailorCompanion\Tests\Http;

use Backend\Models\User;
use Cms\Classes\Theme;
use File;
use PluginTestCase;
use Renick\TailorCompanion\Classes\Auth\TokenManager;
use Renick\TailorCompanion\Classes\Pages\PagesFeature;
use Renick\TailorCompanion\Tests\Fakes\PagesTestGateway;

require_once __DIR__ . '/../fakes/PagesTestGateway.php';

class ApiPagesWriteTest extends PluginTestCase
{
    protected array $authHeader;
    protected PagesTestGateway $gateway;

    public function setUp(): void
    {
        parent::setUp();

        $user = new User;
        $user->first_name = 'Pages';
        $user->last_name = 'Writer';
        $user->login = 'pageswriter';
        $user->email = 'pageswriter@example.com';
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

    protected function seedGateway(): void
    {
        $this->gateway->layoutList = [[
            'fileName' => 'static-default',
            'description' => 'Static default',
            'useContent' => true,
            'markup' => '{variable name="hero" label="Hero" type="text"}{/variable}<article>{% page %}</article>{% placeholder sidebar type="html" %}',
        ]];
        $this->gateway->pages = ['about' => [
            'fileName' => 'about',
            'viewBag' => ['title' => 'About', 'url' => '/about', 'layout' => 'static-default', 'hero' => 'Old'],
            'markup' => '<p>Body</p>',
            'code' => '',
            'mtime' => 1710000000,
            'contentHash' => 'base-hash',
        ]];
    }

    public function testPatchAppliesChanges()
    {
        $this->seedGateway();

        $response = $this->patchJson('/api/tailor-companion/v1/pages/file/about', [
            'base_hash' => 'base-hash',
            'fields' => ['title' => 'About us', 'hero' => 'New', 'placeholder:sidebar' => '<p>Side</p>'],
        ], $this->authHeader);

        $response->assertStatus(200);
        $response->assertJsonPath('data.title', 'About us');
        $response->assertJsonPath('data.fields.hero', 'New');
        $response->assertJsonPath('meta.warnings', []);
        $this->assertNotSame('base-hash', $response->json('data.content_hash'));
    }

    public function testPatchReturnsWarnings()
    {
        $this->seedGateway();

        $response = $this->patchJson('/api/tailor-companion/v1/pages/file/about', [
            'base_hash' => 'base-hash',
            'fields' => ['url' => '/x', 'ghost' => 'y'],
        ], $this->authHeader);

        $response->assertStatus(200);
        $warnings = collect($response->json('meta.warnings'))->pluck('code', 'field');
        $this->assertSame('readonly_field', $warnings['url']);
        $this->assertSame('unknown_field', $warnings['ghost']);
    }

    public function testStaleBaseHashIsConflict()
    {
        $this->seedGateway();

        $response = $this->patchJson('/api/tailor-companion/v1/pages/file/about', [
            'base_hash' => 'stale',
            'fields' => ['title' => 'Nope'],
        ], $this->authHeader);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'conflict');
        $response->assertJsonPath('server_state.title', 'About');
    }

    public function testPatchUnknownPageIs404()
    {
        $this->seedGateway();

        $this->patchJson('/api/tailor-companion/v1/pages/file/nope', ['fields' => []], $this->authHeader)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'page_not_found');
    }

    // -- Installed-gated real round-trip (restores the fixture file) ----------

    public function testRealPageRoundTripThroughRainLab()
    {
        if (!PagesFeature::isInstalled()) {
            $this->markTestSkipped('RainLab.Pages not installed.');
        }

        // Use the real gateway against the companion theme's throwaway page.
        PagesFeature::setGateway(null);

        $theme = Theme::getActiveTheme();
        $path = $theme->getPath() . '/content/static-pages/e2e-playground.htm';
        $original = File::get($path);

        try {
            $before = $this->getJson('/api/tailor-companion/v1/pages/file/e2e-playground', $this->authHeader);
            $before->assertStatus(200);
            $baseHash = $before->json('data.content_hash');

            $patch = $this->patchJson('/api/tailor-companion/v1/pages/file/e2e-playground', [
                'base_hash' => $baseHash,
                'fields' => [
                    'hero_heading' => 'Edited by test',
                    'placeholder:sidebar' => '<p>Edited sidebar</p>',
                ],
            ], $this->authHeader);

            $patch->assertStatus(200);
            $this->assertSame('Edited by test', $patch->json('data.fields.hero_heading'));
            $this->assertNotSame($baseHash, $patch->json('data.content_hash'));

            // Re-read: change persisted to disk through the RainLab model.
            $after = $this->getJson('/api/tailor-companion/v1/pages/file/e2e-playground', $this->authHeader);
            $this->assertSame('Edited by test', $after->json('data.fields.hero_heading'));
            $this->assertSame('<p>Edited sidebar</p>', $after->json('data.fields.placeholder:sidebar'));
        }
        finally {
            File::put($path, $original);
        }
    }
}
