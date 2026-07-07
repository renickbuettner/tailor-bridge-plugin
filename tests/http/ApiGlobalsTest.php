<?php namespace Renick\TailorCompanion\Tests\Http;

use Backend\Models\User;
use PluginTestCase;
use Renick\TailorCompanion\Classes\Auth\TokenManager;
use Tailor\Classes\BlueprintIndexer;

class ApiGlobalsTest extends PluginTestCase
{
    protected array $authHeader;
    protected string $globalUuid;

    public function setUp(): void
    {
        parent::setUp();
        $this->migrateTailor();

        $user = new User;
        $user->first_name = 'Global';
        $user->last_name = 'Tester';
        $user->login = 'globaltester';
        $user->email = 'globaltester@example.com';
        $user->password = 'global-pass-123';
        $user->password_confirmation = 'global-pass-123';
        $user->is_superuser = true;
        $user->is_activated = true;
        $user->save();

        $result = (new TokenManager)->issue($user);
        $this->authHeader = ['Authorization' => 'Bearer ' . $result['token']];
        $this->globalUuid = BlueprintIndexer::instance()->findGlobalByHandle('Demo\Settings')->uuid;
    }

    public function testSchemaIncludesGlobalWithFields()
    {
        $schema = $this->getJson('/api/tailor-companion/v1/schema', $this->authHeader)->json('data');
        $global = collect($schema)->firstWhere('handle', 'Demo\Settings');

        $this->assertNotNull($global);
        $this->assertSame('global', $global['type']);
        $names = array_column($global['fields'], 'name');
        $this->assertContains('site_title', $names);
        $this->assertContains('default_category', $names);
    }

    public function testGetGlobalReturnsSingleRecord()
    {
        $response = $this->getJson("/api/tailor-companion/v1/globals/{$this->globalUuid}", $this->authHeader);

        $response->assertStatus(200);
        $response->assertJsonPath('data.is_global', true);
        $response->assertJsonPath('data.blueprint_uuid', $this->globalUuid);
        $this->assertIsArray($response->json('data.fields'));
    }

    public function testGetUnknownGlobalIs404()
    {
        $this->getJson('/api/tailor-companion/v1/globals/00000000-0000-0000-0000-000000000000', $this->authHeader)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'unknown_global');

        // A section uuid is not a global
        $postUuid = BlueprintIndexer::instance()->findSectionByHandle('Blog\Post')->uuid;
        $this->getJson("/api/tailor-companion/v1/globals/{$postUuid}", $this->authHeader)
            ->assertStatus(404);
    }

    public function testUpdateGlobalViaBatch()
    {
        $category = \Tailor\Models\EntryRecord::inSection('Blog\Category');
        $category->title = 'Global Cat';
        $category->slug = 'global-cat-' . random_int(1000, 9999);
        $category->save();

        $response = $this->postJson('/api/tailor-companion/v1/sync/batch', [
            'ops' => [[
                'op' => 'update',
                'blueprint_uuid' => $this->globalUuid,
                'fields' => [
                    'site_title' => 'My Tailor Site',
                    'tagline' => 'Built with October',
                    'accent_color' => '#6d28d9',
                    'keywords' => ['cms', 'tailor'],
                    // relation + repeater on a global → returned as warnings
                    'default_category' => $category->id,
                    'social_links' => [['platform' => 'GitHub', 'url' => 'https://x']],
                ],
            ]],
        ], $this->authHeader);

        $response->assertStatus(200);
        $response->assertJsonPath('meta.applied', 1);
        $result = $response->json('data.results.0');
        $this->assertSame('ok', $result['status']);
        $this->assertTrue($result['entry']['is_global']);

        // Scalars / colours / tags write normally
        $this->assertSame('My Tailor Site', $result['entry']['fields']['site_title']);
        $this->assertSame('#6d28d9', $result['entry']['fields']['accent_color']);
        $this->assertSame(['cms', 'tailor'], $result['entry']['fields']['keywords']);

        // Relation + repeater came back as warnings, values preserved
        $warned = collect($result['warnings'])->keyBy('field');
        $this->assertSame('unsupported_on_global', $warned['default_category']['code']);
        $this->assertSame('unsupported_on_global', $warned['social_links']['code']);
        $this->assertSame((int) $category->id, $warned['default_category']['value']);

        // Re-fetch confirms scalar persistence
        $fresh = $this->getJson("/api/tailor-companion/v1/globals/{$this->globalUuid}", $this->authHeader);
        $fresh->assertJsonPath('data.fields.site_title', 'My Tailor Site');
        $fresh->assertJsonPath('data.fields.tagline', 'Built with October');
    }
}
