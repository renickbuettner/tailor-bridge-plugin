<?php namespace Renick\TailorCompanion\Tests\Http;

use Backend\Models\User;
use Illuminate\Http\UploadedFile;
use PluginTestCase;
use Renick\TailorCompanion\Classes\Auth\TokenManager;
use Renick\TailorCompanion\Models\AuditLog;
use Renick\TailorCompanion\Models\Setting;
use Tailor\Classes\BlueprintIndexer;

class ApiWriteTest extends PluginTestCase
{
    protected array $authHeader;
    protected string $postUuid;

    public function setUp(): void
    {
        parent::setUp();
        $this->migrateTailor();

        $user = new User;
        $user->first_name = 'Write';
        $user->last_name = 'Tester';
        $user->login = 'writetester';
        $user->email = 'writetester@example.com';
        $user->password = 'write-pass-12345';
        $user->password_confirmation = 'write-pass-12345';
        $user->is_superuser = true;
        $user->is_activated = true;
        $user->save();

        $result = (new TokenManager)->issue($user);
        $this->authHeader = ['Authorization' => 'Bearer ' . $result['token']];
        $this->postUuid = BlueprintIndexer::instance()->findSectionByHandle('Blog\Post')->uuid;
    }

    // -- /sync/batch -----------------------------------------------------------

    public function testBatchRequiresOps()
    {
        $this->postJson('/api/tailor-companion/v1/sync/batch', [], $this->authHeader)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation');
    }

    public function testBatchAppliesOpsInOrderWithMixedResults()
    {
        $response = $this->postJson('/api/tailor-companion/v1/sync/batch', [
            'ops' => [
                // ok
                ['op' => 'create', 'blueprint_uuid' => $this->postUuid, 'local_id' => 'l1',
                    'fields' => ['title' => 'Batch One']],
                // error (missing title)
                ['op' => 'create', 'blueprint_uuid' => $this->postUuid, 'local_id' => 'l2',
                    'fields' => ['excerpt' => 'no title']],
                // conflict (unknown id → deleted server-side)
                ['op' => 'update', 'blueprint_uuid' => $this->postUuid, 'local_id' => 'l3',
                    'id' => 99999, 'fields' => ['title' => 'nope']],
            ],
        ], $this->authHeader);

        $response->assertStatus(200);
        $response->assertJsonPath('meta.applied', 1);
        $response->assertJsonPath('meta.errors', 1);
        $response->assertJsonPath('meta.conflicts', 1);

        $results = $response->json('data.results');
        $this->assertSame(['ok', 'error', 'conflict'], array_column($results, 'status'));
        $this->assertSame(['l1', 'l2', 'l3'], array_column($results, 'local_id'));

        // ok result returns the server entry with slug for local_id mapping
        $this->assertNotEmpty($results[0]['entry']['slug']);
    }

    public function testBatchOpCountLimit()
    {
        $ops = array_fill(0, 101, ['op' => 'delete', 'blueprint_uuid' => $this->postUuid, 'id' => 1]);

        $this->postJson('/api/tailor-companion/v1/sync/batch', ['ops' => $ops], $this->authHeader)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'too_many_ops');
    }

    public function testBatchWritesAppearInChangesJournal()
    {
        $baseline = $this->getJson('/api/tailor-companion/v1/sync/changes', $this->authHeader)
            ->json('meta.latest_cursor');

        $create = $this->postJson('/api/tailor-companion/v1/sync/batch', [
            'ops' => [['op' => 'create', 'blueprint_uuid' => $this->postUuid,
                'fields' => ['title' => 'Journaled Batch']]],
        ], $this->authHeader);

        $changes = $this->getJson("/api/tailor-companion/v1/sync/changes?since={$baseline}", $this->authHeader)
            ->json('data');

        $this->assertCount(1, $changes);
        $this->assertSame('created', $changes[0]['action']);
        $this->assertSame($create->json('data.results.0.id'), $changes[0]['record_id']);
    }

    // -- /files ------------------------------------------------------------------

    public function testFileUploadAttachAndDownload()
    {
        $sinkUuid = BlueprintIndexer::instance()->findSectionByHandle('Demo\KitchenSink')->uuid;

        // 1. Upload
        $upload = $this->post('/api/tailor-companion/v1/files', [
            'file' => UploadedFile::fake()->image('photo.jpg', 100, 80),
        ], $this->authHeader);

        $upload->assertStatus(201);
        $fileId = $upload->json('data.id');
        $this->assertIsInt($fileId);
        $this->assertSame('photo.jpg', $upload->json('data.name'));

        // 2. Attach via batch op
        $create = $this->postJson('/api/tailor-companion/v1/sync/batch', [
            'ops' => [['op' => 'create', 'blueprint_uuid' => $sinkUuid,
                'fields' => ['title' => 'With Gallery', 'gallery' => [$fileId]]]],
        ], $this->authHeader);

        $entry = $create->json('data.results.0.entry');
        $this->assertCount(1, $entry['fields']['gallery']);
        $this->assertSame($fileId, $entry['fields']['gallery'][0]['id']);

        // 3. Download through the API
        $download = $this->get("/api/tailor-companion/v1/files/{$fileId}", $this->authHeader);
        $download->assertStatus(200);

        // 4. Unknown file
        $this->getJson('/api/tailor-companion/v1/files/999999', $this->authHeader)
            ->assertStatus(404);
    }

    public function testFileUploadValidation()
    {
        $this->postJson('/api/tailor-companion/v1/files', [], $this->authHeader)
            ->assertStatus(422);
    }

    // -- settings enforcement ---------------------------------------------------------

    public function testApiDisabledBlocksEverything()
    {
        Setting::set('api_enabled', false);

        $this->getJson('/api/tailor-companion/v1/ping', $this->authHeader)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'api_disabled');

        $this->postJson('/api/tailor-companion/v1/auth/token', [
            'login' => 'writetester', 'password' => 'write-pass-12345',
        ])->assertStatus(403);

        Setting::set('api_enabled', true);

        $this->getJson('/api/tailor-companion/v1/ping', $this->authHeader)->assertStatus(200);
    }

    public function testTokenExpirySettingIsApplied()
    {
        Setting::set('token_expiry_days', 7);

        $response = $this->postJson('/api/tailor-companion/v1/auth/token', [
            'login' => 'writetester', 'password' => 'write-pass-12345',
        ]);

        $response->assertStatus(201);
        $this->assertNotNull($response->json('data.expires_at'));

        Setting::set('token_expiry_days', 0);
    }

    // -- audit trail through HTTP ------------------------------------------------------

    public function testBatchMutationsAreAudited()
    {
        AuditLog::query()->delete();

        $this->postJson('/api/tailor-companion/v1/sync/batch', [
            'ops' => [['op' => 'create', 'blueprint_uuid' => $this->postUuid,
                'fields' => ['title' => 'HTTP Audited']]],
        ], $this->authHeader);

        $log = AuditLog::orderByDesc('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('create', $log->action);
        $this->assertNotNull($log->token_id, 'Audit entry linked to the API token');
        $this->assertNotNull($log->backend_user_id);
    }
}
