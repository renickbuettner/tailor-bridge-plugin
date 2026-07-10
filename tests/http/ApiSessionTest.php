<?php namespace Renick\TailorCompanion\Tests\Http;

use Backend\Models\User;
use PluginTestCase;
use Renick\TailorCompanion\Classes\Auth\SessionNonce;
use Renick\TailorCompanion\Classes\Auth\TokenManager;

/**
 * POST /session mints a one-time backend-login URL for the token's user.
 */
class ApiSessionTest extends PluginTestCase
{
    protected array $authHeader;

    public function setUp(): void
    {
        parent::setUp();

        $user = new User;
        $user->first_name = 'Session';
        $user->last_name = 'Tester';
        $user->login = 'sessiontester';
        $user->email = 'sessiontester@example.com';
        $user->password = 'session-pass-1234';
        $user->password_confirmation = 'session-pass-1234';
        $user->is_superuser = true;
        $user->is_activated = true;
        $user->save();

        $result = (new TokenManager)->issue($user);
        $this->authHeader = ['Authorization' => 'Bearer ' . $result['token']];
    }

    public function testReturnsOneTimeLoginUrl()
    {
        $response = $this->postJson('/api/tailor-companion/v1/session', [], $this->authHeader);

        $response->assertStatus(200);
        $response->assertJsonPath('data.expires_in', SessionNonce::TTL_SECONDS);

        $url = $response->json('data.url');
        $this->assertIsString($url);
        $this->assertStringContainsString('tailor-companion/session/', $url);
    }

    public function testMintedNonceRedeemsToTheTokenUser()
    {
        $response = $this->postJson('/api/tailor-companion/v1/session', [], $this->authHeader);
        $url = $response->json('data.url');
        $nonce = substr($url, strrpos($url, '/') + 1);

        // The nonce resolves to the authenticated user and is single-use.
        $userId = SessionNonce::consume($nonce);
        $this->assertNotNull($userId);
        $this->assertSame('sessiontester', User::find($userId)->login);
        $this->assertNull(SessionNonce::consume($nonce));
    }

    public function testRequiresToken()
    {
        $response = $this->postJson('/api/tailor-companion/v1/session', []);

        $response->assertStatus(401);
    }
}
