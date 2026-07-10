<?php namespace Renick\TailorCompanion\Tests\Http;

use Backend\Models\User;
use PluginTestCase;
use Renick\TailorCompanion\Classes\Auth\TokenManager;
use Renick\TailorCompanion\Classes\Pages\PagesFeature;
use Renick\TailorCompanion\Classes\Pages\PagesGateway;
use Renick\TailorCompanion\Plugin;

/**
 * /version is the deploy marker used to confirm which plugin code is live.
 * It must be a clean 200 regardless of the state of optional subsystems —
 * that is the whole point of having it separate from /ping.
 */
class ApiVersionTest extends PluginTestCase
{
    protected array $authHeader;

    public function setUp(): void
    {
        parent::setUp();

        $user = new User;
        $user->first_name = 'Version';
        $user->last_name = 'Tester';
        $user->login = 'versiontester';
        $user->email = 'versiontester@example.com';
        $user->password = 'version-pass-1234';
        $user->password_confirmation = 'version-pass-1234';
        $user->is_superuser = true;
        $user->is_activated = true;
        $user->save();

        $result = (new TokenManager)->issue($user);
        $this->authHeader = ['Authorization' => 'Bearer ' . $result['token']];
    }

    public function tearDown(): void
    {
        PagesFeature::forceAvailability(null);
        PagesFeature::setGateway(null);
        parent::tearDown();
    }

    public function testReportsBuildMarkerAndVersion()
    {
        $response = $this->getJson('/api/tailor-companion/v1/version', $this->authHeader);

        $response->assertStatus(200);
        $response->assertJsonPath('data.api_version', 1);
        $response->assertJsonPath('data.build', Plugin::BUILD);
        $response->assertJsonStructure([
            'data' => ['api_version', 'build', 'plugin_version', 'october_version', 'server_time'],
        ]);
    }

    public function testRequiresToken()
    {
        $response = $this->getJson('/api/tailor-companion/v1/version');

        $response->assertStatus(401);
    }

    /**
     * The endpoint must NOT touch the optional static-pages subsystem — a
     * gateway that throws on introspection (the exact pr11 failure mode) must
     * not affect /version at all.
     */
    public function testIsUnaffectedByBrokenPagesFeature()
    {
        PagesFeature::forceAvailability(true);
        PagesFeature::setGateway(new class implements PagesGateway {
            public function layouts(): array { throw new \RuntimeException('no active theme'); }
            public function tree(): array { return []; }
            public function page(string $fileName): ?array { return null; }
            public function updatePage(string $fileName, array $viewBag, ?string $markup, ?string $code): array { return []; }
            public function menus(): array { return []; }
            public function menu(string $code): ?array { return null; }
            public function themePath(): ?string { return null; }
        });

        $response = $this->getJson('/api/tailor-companion/v1/version', $this->authHeader);

        $response->assertStatus(200);
        $response->assertJsonPath('data.build', Plugin::BUILD);
    }
}
