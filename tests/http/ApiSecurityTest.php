<?php namespace Renick\TailorCompanion\Tests\Http;

use Backend\Models\User;
use Illuminate\Http\UploadedFile;
use PluginTestCase;
use Renick\TailorCompanion\Classes\Auth\TokenManager;
use System\Models\File as FileModel;
use Tailor\Classes\BlueprintIndexer;

class ApiSecurityTest extends PluginTestCase
{
    protected array $authHeader;
    protected string $sinkUuid;

    public function setUp(): void
    {
        parent::setUp();
        $this->migrateTailor();

        $user = new User;
        $user->first_name = 'Sec';
        $user->last_name = 'Tester';
        $user->login = 'sectester';
        $user->email = 'sectester@example.com';
        $user->password = 'sec-pass-123456';
        $user->password_confirmation = 'sec-pass-123456';
        $user->is_superuser = true;
        $user->is_activated = true;
        $user->save();

        $result = (new TokenManager)->issue($user);
        $this->authHeader = ['Authorization' => 'Bearer ' . $result['token']];
        $this->sinkUuid = BlueprintIndexer::instance()->findSectionByHandle('Demo\KitchenSink')->uuid;
    }

    // -- Deactivated user ------------------------------------------------------

    public function testDeactivatedUserIsRejected()
    {
        $user = new User;
        $user->first_name = 'Off';
        $user->last_name = 'User';
        $user->login = 'deactivated';
        $user->email = 'deactivated@example.com';
        $user->password = 'deact-pass-123';
        $user->password_confirmation = 'deact-pass-123';
        $user->is_superuser = true;
        $user->is_activated = false;
        $user->save();

        $result = (new TokenManager)->issue($user);

        // Existing token no longer works
        $this->getJson('/api/tailor-companion/v1/ping', ['Authorization' => 'Bearer ' . $result['token']])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'user_unavailable');

        // Cannot mint a new token either
        $this->postJson('/api/tailor-companion/v1/auth/token', [
            'login' => 'deactivated', 'password' => 'deact-pass-123',
        ])->assertStatus(403);
    }

    // -- Attachment hijacking --------------------------------------------------

    public function testCannotClaimForeignAttachment()
    {
        // A file "owned" by some other model (simulated foreign attachment)
        $foreign = new FileModel;
        $foreign->data = UploadedFile::fake()->image('foreign.jpg');
        $foreign->attachment_type = 'Backend\Models\BrandSetting';
        $foreign->attachment_id = 1;
        $foreign->field = 'logo';
        $foreign->save();

        $create = $this->postJson('/api/tailor-companion/v1/sync/batch', [
            'ops' => [['op' => 'create', 'blueprint_uuid' => $this->sinkUuid,
                'fields' => ['title' => 'Thief', 'gallery' => [$foreign->id]]]],
        ], $this->authHeader);

        $entry = $create->json('data.results.0.entry');
        $this->assertSame([], $entry['fields']['gallery'], 'Foreign file must not be attached');

        // The foreign file is untouched
        $foreign->refresh();
        $this->assertSame('Backend\Models\BrandSetting', $foreign->attachment_type);
    }

    public function testForeignFileDownloadIs404()
    {
        $foreign = new FileModel;
        $foreign->data = UploadedFile::fake()->create('secret.pdf', 10);
        $foreign->attachment_type = 'Backend\Models\BrandSetting';
        $foreign->attachment_id = 1;
        $foreign->field = 'logo';
        $foreign->save();

        $this->getJson("/api/tailor-companion/v1/files/{$foreign->id}", $this->authHeader)
            ->assertStatus(404);
    }

    public function testFreshUploadIsClaimableAndServable()
    {
        $upload = $this->post('/api/tailor-companion/v1/files', [
            'file' => UploadedFile::fake()->image('mine.jpg'),
        ], $this->authHeader);
        $fileId = $upload->json('data.id');

        $create = $this->postJson('/api/tailor-companion/v1/sync/batch', [
            'ops' => [['op' => 'create', 'blueprint_uuid' => $this->sinkUuid,
                'fields' => ['title' => 'Owner', 'gallery' => [$fileId]]]],
        ], $this->authHeader);

        $this->assertCount(1, $create->json('data.results.0.entry.fields.gallery'));
        $this->getJson("/api/tailor-companion/v1/files/{$fileId}", $this->authHeader)->assertStatus(200);
    }

    // -- Upload validation -----------------------------------------------------

    public function testDisallowedFileTypeRejected()
    {
        $this->post('/api/tailor-companion/v1/files', [
            'file' => UploadedFile::fake()->create('evil.php', 1),
        ], $this->authHeader)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'file_type');
    }
}
