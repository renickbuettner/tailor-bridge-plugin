<?php namespace Renick\TailorCompanion\Tests\Classes\Auth;

use Backend\Models\User;
use Date;
use PluginTestCase;
use Renick\TailorCompanion\Classes\Auth\TokenManager;
use Renick\TailorCompanion\Models\AccessToken;

class TokenManagerTest extends PluginTestCase
{
    protected TokenManager $manager;

    public function setUp(): void
    {
        parent::setUp();
        $this->manager = new TokenManager;
    }

    protected function makeUser(string $login = 'tokentester'): User
    {
        $user = new User;
        $user->first_name = 'Token';
        $user->last_name = 'Tester';
        $user->login = $login;
        $user->email = $login . '@example.com';
        $user->password = 'secret-password-123';
        $user->password_confirmation = 'secret-password-123';
        $user->is_superuser = true;
        $user->is_activated = true;
        $user->save();

        return $user;
    }

    public function testIssueReturnsRawTokenAndPersistsHash()
    {
        $user = $this->makeUser();

        $result = $this->manager->issue($user, 'Test Device');

        $this->assertSame(43, strlen($result['token']));
        $this->assertInstanceOf(AccessToken::class, $result['model']);
        $this->assertNotSame($result['token'], $result['model']->token_hash);
        $this->assertSame(hash('sha256', $result['token']), $result['model']->token_hash);
        $this->assertSame(substr($result['token'], 0, 8), $result['model']->token_prefix);
        $this->assertSame($user->id, $result['model']->backend_user_id);
        $this->assertSame('Test Device', $result['model']->name);
        $this->assertTrue($result['model']->exists);
    }

    public function testVerifyReturnsModelForValidToken()
    {
        $user = $this->makeUser();
        $result = $this->manager->issue($user);

        $verified = $this->manager->verify($result['token']);

        $this->assertNotNull($verified);
        $this->assertSame($result['model']->id, $verified->id);
    }

    public function testVerifyStampsLastUsedAt()
    {
        $user = $this->makeUser();
        $result = $this->manager->issue($user);

        $this->assertNull($result['model']->last_used_at);

        $verified = $this->manager->verify($result['token']);

        $this->assertNotNull($verified->last_used_at);
    }

    public function testVerifyRejectsUnknownToken()
    {
        $this->assertNull($this->manager->verify('definitely-not-a-token'));
        $this->assertNull($this->manager->verify(null));
        $this->assertNull($this->manager->verify(''));
    }

    public function testVerifyRejectsRevokedToken()
    {
        $user = $this->makeUser();
        $result = $this->manager->issue($user);

        $this->manager->revoke($result['model']);

        $this->assertNull($this->manager->verify($result['token']));
    }

    public function testVerifyRejectsExpiredToken()
    {
        $user = $this->makeUser();
        $result = $this->manager->issue($user, null, Date::now()->subMinute());

        $this->assertNull($this->manager->verify($result['token']));
    }

    public function testVerifyAcceptsFutureExpiry()
    {
        $user = $this->makeUser();
        $result = $this->manager->issue($user, null, Date::now()->addDay());

        $this->assertNotNull($this->manager->verify($result['token']));
    }

    public function testGeneratedTokensAreUnique()
    {
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $token = $this->manager->generateTokenValue();
            $this->assertArrayNotHasKey($token, $seen);
            $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $token, 'Token must be base64url');
            $seen[$token] = true;
        }
    }
}
