<?php namespace Renick\TailorCompanion\Tests\Http;

use Backend\Models\User;
use PluginTestCase;
use Renick\TailorCompanion\Classes\Auth\TokenManager;
use System\Classes\SiteManager;
use System\Models\SiteDefinition;
use Tailor\Classes\BlueprintIndexer;

class ApiSitesTest extends PluginTestCase
{
    protected array $authHeader;
    protected string $regionUuid;

    public function setUp(): void
    {
        parent::setUp();
        $this->migrateTailor();

        // Ensure a second site exists → multisite enabled
        if (count(SiteManager::instance()->listSites()) < 2) {
            $site = new SiteDefinition;
            $site->name = 'English';
            $site->code = 'en';
            $site->locale = 'en';
            $site->is_enabled = true;
            $site->is_primary = false;
            $site->save();
            SiteManager::instance()->resetCache();
        }

        $user = new User;
        $user->first_name = 'Site';
        $user->last_name = 'Tester';
        $user->login = 'sitetester';
        $user->email = 'sitetester@example.com';
        $user->password = 'site-pass-1234';
        $user->password_confirmation = 'site-pass-1234';
        $user->is_superuser = true;
        $user->is_activated = true;
        $user->save();

        $result = (new TokenManager)->issue($user);
        $this->authHeader = ['Authorization' => 'Bearer ' . $result['token']];
        $this->regionUuid = BlueprintIndexer::instance()->findSectionByHandle('Demo\Region')->uuid;
    }

    public function testListsSites()
    {
        $response = $this->getJson('/api/tailor-companion/v1/sites', $this->authHeader);

        $response->assertStatus(200);
        $response->assertJsonPath('meta.multisite', true);
        $codes = collect($response->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('primary'));
        $this->assertTrue($codes->contains('en'));
    }

    public function testEntriesAreScopedToRequestedSite()
    {
        $sites = collect(SiteManager::instance()->listSites());
        $site1 = $sites->firstWhere('is_primary', true)->id;
        $site2 = $sites->firstWhere('is_primary', false)->id;

        \Site::withContext($site1, function () {
            $r = \Tailor\Models\EntryRecord::inSection('Demo\Region');
            $r->title = 'Region One'; $r->slug = 'region-one'; $r->save();
        });
        \Site::withContext($site2, function () {
            $r = \Tailor\Models\EntryRecord::inSection('Demo\Region');
            $r->title = 'Region Two'; $r->slug = 'region-two'; $r->save();
        });

        $s1 = $this->getJson("/api/tailor-companion/v1/entries/{$this->regionUuid}", $this->authHeader + ['X-Tailor-Site' => (string) $site1]);
        $this->assertSame(['Region One'], collect($s1->json('data'))->pluck('title')->all());

        $s2 = $this->getJson("/api/tailor-companion/v1/entries/{$this->regionUuid}", $this->authHeader + ['X-Tailor-Site' => (string) $site2]);
        $this->assertSame(['Region Two'], collect($s2->json('data'))->pluck('title')->all());
    }

    public function testCreateLandsInRequestedSite()
    {
        $site2 = collect(SiteManager::instance()->listSites())->firstWhere('is_primary', false)->id;

        $response = $this->postJson('/api/tailor-companion/v1/sync/batch', [
            'ops' => [[
                'op' => 'create',
                'blueprint_uuid' => $this->regionUuid,
                'fields' => ['title' => 'Made in site 2', 'body' => 'x'],
            ]],
        ], $this->authHeader + ['X-Tailor-Site' => (string) $site2]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.results.0.status', 'ok');
        $response->assertJsonPath('data.results.0.entry.site_id', (int) $site2);
    }
}
