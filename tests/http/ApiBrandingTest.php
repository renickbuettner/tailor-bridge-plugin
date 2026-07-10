<?php namespace Renick\TailorCompanion\Tests\Http;

use Backend\Models\User;
use PluginTestCase;
use Renick\TailorCompanion\Classes\Auth\TokenManager;
use Renick\TailorCompanion\Models\Setting;

/**
 * /ping carries the customizable branding (title, logo, preview website) that
 * the app uses in place of its built-in defaults.
 */
class ApiBrandingTest extends PluginTestCase
{
    protected array $authHeader;

    public function setUp(): void
    {
        parent::setUp();

        $user = new User;
        $user->first_name = 'Brand';
        $user->last_name = 'Tester';
        $user->login = 'brandtester';
        $user->email = 'brandtester@example.com';
        $user->password = 'brand-pass-1234';
        $user->password_confirmation = 'brand-pass-1234';
        $user->is_superuser = true;
        $user->is_activated = true;
        $user->save();

        $result = (new TokenManager)->issue($user);
        $this->authHeader = ['Authorization' => 'Bearer ' . $result['token']];
    }

    public function tearDown(): void
    {
        Setting::set('brand_title', null);
        Setting::set('brand_logo', null);
        Setting::set('preview_url', null);
        parent::tearDown();
    }

    public function testBrandingDefaultsToNullWhenUnset()
    {
        $response = $this->getJson('/api/tailor-companion/v1/ping', $this->authHeader);

        $response->assertStatus(200);
        $response->assertJsonPath('data.branding.title', null);
        $response->assertJsonPath('data.branding.logo_url', null);
        $response->assertJsonPath('data.branding.preview_url', null);
    }

    public function testBrandingReflectsConfiguredValues()
    {
        Setting::set('brand_title', 'Acme CMS');
        Setting::set('preview_url', 'https://acme.example.com');

        $response = $this->getJson('/api/tailor-companion/v1/ping', $this->authHeader);

        $response->assertStatus(200);
        $response->assertJsonPath('data.branding.title', 'Acme CMS');
        $response->assertJsonPath('data.branding.preview_url', 'https://acme.example.com');
    }

    public function testBlankTitleIsReportedAsNullNotEmptyString()
    {
        Setting::set('brand_title', '   ');

        $response = $this->getJson('/api/tailor-companion/v1/ping', $this->authHeader);

        $response->assertStatus(200);
        $response->assertJsonPath('data.branding.title', null);
    }
}
