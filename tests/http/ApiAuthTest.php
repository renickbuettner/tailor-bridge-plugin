<?php namespace Renick\TailorCompanion\Tests\Http;

use Backend\Models\User;
use PluginTestCase;
use Renick\TailorCompanion\Classes\Auth\TokenManager;

class ApiAuthTest extends PluginTestCase
{
    protected function makeUser(string $login = 'apiuser', string $password = 'super-secret-99'): User
    {
        $user = new User;
        $user->first_name = 'Api';
        $user->last_name = 'User';
        $user->login = $login;
        $user->email = $login . '@example.com';
        $user->password = $password;
        $user->password_confirmation = $password;
        $user->is_superuser = true;
        $user->is_activated = true;
        $user->save();

        return $user;
    }

    // -- /ping (TokenAuth middleware) -----------------------------------------

    public function testPingWithoutTokenIsRejected()
    {
        $response = $this->getJson('/api/tailor-companion/v1/ping');

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'invalid_token');
    }

    public function testPingWithGarbageTokenIsRejected()
    {
        $response = $this->getJson('/api/tailor-companion/v1/ping', [
            'Authorization' => 'Bearer not-a-real-token',
        ]);

        $response->assertStatus(401);
    }

    public function testPingWithValidTokenReturnsServerInfo()
    {
        $user = $this->makeUser();
        $result = (new TokenManager)->issue($user, 'Test iPhone');

        $response = $this->getJson('/api/tailor-companion/v1/ping', [
            'Authorization' => 'Bearer ' . $result['token'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.api_version', 1);
        $response->assertJsonPath('data.user.login', 'apiuser');
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $response->json('data.schema_version')
        );
    }

    public function testPingWithRevokedTokenIsRejected()
    {
        $user = $this->makeUser();
        $manager = new TokenManager;
        $result = $manager->issue($user);
        $manager->revoke($result['model']);

        $response = $this->getJson('/api/tailor-companion/v1/ping', [
            'Authorization' => 'Bearer ' . $result['token'],
        ]);

        $response->assertStatus(401);
    }

    public function testPingWithDeletedUserIsRejected()
    {
        $user = $this->makeUser();
        $result = (new TokenManager)->issue($user);

        $user->delete(); // soft delete

        $response = $this->getJson('/api/tailor-companion/v1/ping', [
            'Authorization' => 'Bearer ' . $result['token'],
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'user_unavailable');
    }

    // -- /auth/token (manual pairing) -------------------------------------------

    public function testIssueTokenWithValidCredentials()
    {
        $this->makeUser('pairme', 'pairing-pass-123');

        $response = $this->postJson('/api/tailor-companion/v1/auth/token', [
            'login' => 'pairme',
            'password' => 'pairing-pass-123',
            'device_name' => 'Test iPhone',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.user.login', 'pairme');
        $response->assertJsonPath('data.name', 'Test iPhone');

        // The returned token must actually authenticate
        $ping = $this->getJson('/api/tailor-companion/v1/ping', [
            'Authorization' => 'Bearer ' . $response->json('data.token'),
        ]);
        $ping->assertStatus(200);
    }

    public function testIssueTokenWithWrongPasswordIsRejected()
    {
        $this->makeUser('pairme2', 'correct-password-1');

        $response = $this->postJson('/api/tailor-companion/v1/auth/token', [
            'login' => 'pairme2',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function testIssueTokenWithUnknownLoginIsRejected()
    {
        $response = $this->postJson('/api/tailor-companion/v1/auth/token', [
            'login' => 'ghost',
            'password' => 'whatever-123',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function testIssueTokenValidatesInput()
    {
        $response = $this->postJson('/api/tailor-companion/v1/auth/token', [
            'login' => 'someone',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'validation');
    }

    public function testIssueTokenWritesAuditLog()
    {
        $this->makeUser('pairme3', 'audit-pass-123');

        $this->postJson('/api/tailor-companion/v1/auth/token', [
            'login' => 'pairme3',
            'password' => 'audit-pass-123',
        ])->assertStatus(201);

        $log = \Renick\TailorCompanion\Models\AuditLog::orderByDesc('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('token_issued', $log->action);
    }
}
