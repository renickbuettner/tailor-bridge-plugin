<?php namespace Renick\TailorCompanion\Tests\Http;

use Backend\Models\User;
use PluginTestCase;
use Renick\TailorCompanion\Classes\Auth\TokenManager;
use Renick\TailorCompanion\Classes\Pages\PagesFeature;

/**
 * Installed-gated: asserts the real /pages/schema against the companion theme
 * fixtures. Skips when RainLab.Pages is not present (public CI) — the
 * unavailable path is covered by ApiPagesUnavailableTest.
 */
class ApiPagesSchemaTest extends PluginTestCase
{
    protected array $authHeader;

    public function setUp(): void
    {
        parent::setUp();

        if (!PagesFeature::isInstalled()) {
            $this->markTestSkipped('RainLab.Pages not installed.');
        }

        $user = new User;
        $user->first_name = 'Pages';
        $user->last_name = 'Schema';
        $user->login = 'pagesschema';
        $user->email = 'pagesschema@example.com';
        $user->password = 'pages-pass-1234';
        $user->password_confirmation = 'pages-pass-1234';
        $user->is_superuser = true;
        $user->is_activated = true;
        $user->save();

        $result = (new TokenManager)->issue($user);
        $this->authHeader = ['Authorization' => 'Bearer ' . $result['token']];
    }

    public function testReturnsCompanionLayouts()
    {
        $response = $this->getJson('/api/tailor-companion/v1/pages/schema', $this->authHeader);

        $response->assertStatus(200);
        $files = collect($response->json('data.layouts'))->pluck('file_name');
        $this->assertTrue($files->contains('static-default'));
        $this->assertTrue($files->contains('static-landing'));

        $default = collect($response->json('data.layouts'))->firstWhere('file_name', 'static-default');
        $this->assertTrue($default['use_content']);
        $names = array_column($default['fields'], 'name');
        $this->assertContains('title', $names);
        $this->assertContains('hero_heading', $names);
        $this->assertContains('placeholder:sidebar', $names);
        $this->assertContains('markup', $names);

        $landing = collect($response->json('data.layouts'))->firstWhere('file_name', 'static-landing');
        $this->assertFalse($landing['use_content']);
        $this->assertNotContains('markup', array_column($landing['fields'], 'name'));
    }

    public function testEtagYields304()
    {
        $first = $this->getJson('/api/tailor-companion/v1/pages/schema', $this->authHeader);
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $second = $this->getJson('/api/tailor-companion/v1/pages/schema', $this->authHeader + ['If-None-Match' => $etag]);
        $second->assertStatus(304);
    }

    public function testPingSchemaVersionMatchesSchemaEtag()
    {
        $ping = $this->getJson('/api/tailor-companion/v1/ping', $this->authHeader);
        $version = $ping->json('data.features.static_pages.schema_version');
        $this->assertNotNull($version);

        $schema = $this->getJson('/api/tailor-companion/v1/pages/schema', $this->authHeader);
        $this->assertSame($version, $schema->json('meta.pages_schema_version'));
    }
}
