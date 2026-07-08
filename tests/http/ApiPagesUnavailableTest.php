<?php namespace Renick\TailorCompanion\Tests\Http;

use Backend\Models\User;
use PluginTestCase;
use Renick\TailorCompanion\Classes\Auth\TokenManager;
use Renick\TailorCompanion\Classes\Pages\PagesFeature;
use Renick\TailorCompanion\Classes\Pages\PagesGateway;

/**
 * Covers the "RainLab.Pages not installed / disabled" side of the optional
 * integration: the API must keep working and report the capability as off.
 */
class ApiPagesUnavailableTest extends PluginTestCase
{
    protected array $authHeader;

    public function setUp(): void
    {
        parent::setUp();

        $user = new User;
        $user->first_name = 'Pages';
        $user->last_name = 'Tester';
        $user->login = 'pagestester';
        $user->email = 'pagestester@example.com';
        $user->password = 'pages-pass-1234';
        $user->password_confirmation = 'pages-pass-1234';
        $user->is_superuser = true;
        $user->is_activated = true;
        $user->save();

        $result = (new TokenManager)->issue($user);
        $this->authHeader = ['Authorization' => 'Bearer ' . $result['token']];

        PagesFeature::forceAvailability(false);
    }

    public function tearDown(): void
    {
        PagesFeature::forceAvailability(null);
        PagesFeature::setGateway(null);
        parent::tearDown();
    }

    /**
     * /ping runs at the start of every sync — an error introspecting the
     * optional static-pages feature (e.g. no resolvable active theme, as seen
     * on a real deployment) must NEVER 500 the endpoint. It degrades to
     * "unavailable" instead. Regression test for the pr11 500.
     */
    public function testPingSurvivesPagesIntrospectionError()
    {
        PagesFeature::forceAvailability(true);
        PagesFeature::setGateway(new class implements PagesGateway {
            public function layouts(): array { throw new \RuntimeException('no active theme'); }
            public function tree(): array { return []; }
            public function page(string $fileName): ?array { return null; }
            public function updatePage(string $fileName, array $viewBag, ?string $markup, ?string $code): array { return []; }
            public function menus(): array { return []; }
            public function menu(string $code): ?array { return null; }
        });

        $response = $this->getJson('/api/tailor-companion/v1/ping', $this->authHeader);

        $response->assertStatus(200);
        $response->assertJsonPath('data.features.static_pages.available', false);
        $response->assertJsonPath('data.features.static_pages.schema_version', null);
    }

    public function testPingReportsStaticPagesUnavailable()
    {
        $response = $this->getJson('/api/tailor-companion/v1/ping', $this->authHeader);

        $response->assertStatus(200);
        $response->assertJsonPath('data.features.static_pages.available', false);
        $response->assertJsonPath('data.features.static_pages.schema_version', null);
    }

    public function testPingReportsStaticPagesAvailableWhenForcedOn()
    {
        if (!PagesFeature::isInstalled()) {
            $this->markTestSkipped('RainLab.Pages not installed — schema build would fail.');
        }

        PagesFeature::forceAvailability(true);

        $response = $this->getJson('/api/tailor-companion/v1/ping', $this->authHeader);

        $response->assertStatus(200);
        $response->assertJsonPath('data.features.static_pages.available', true);
    }

    public function testPagesSchemaIsUnavailableWhenFeatureOff()
    {
        $response = $this->getJson('/api/tailor-companion/v1/pages/schema', $this->authHeader);

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'feature_unavailable');
    }
}
