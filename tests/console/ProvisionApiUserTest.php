<?php namespace Renick\TailorCompanion\Tests\Console;

use Artisan;
use Backend\Models\User;
use PluginTestCase;

class ProvisionApiUserTest extends PluginTestCase
{
    public function testCreatesAnActivatedApiUser()
    {
        $code = Artisan::call('tailor-companion:api-user', [
            '--login' => 'reviewer1',
            '--password' => 'review-pass-1234',
        ]);
        $this->assertSame(0, $code);

        $user = User::where('login', 'reviewer1')->first();
        $this->assertNotNull($user);
        $this->assertTrue((bool) $user->is_activated);
        $this->assertTrue($user->checkPassword('review-pass-1234'));
        $this->assertSame(1, (int) ($user->permissions['renick.tailorcompanion.access_api'] ?? 0));
        $this->assertSame('reviewer1@example.com', $user->email);
    }

    public function testIsIdempotentAndResetsThePassword()
    {
        Artisan::call('tailor-companion:api-user', [
            '--login' => 'reviewer2',
            '--password' => 'first-pass-1234',
        ]);
        Artisan::call('tailor-companion:api-user', [
            '--login' => 'reviewer2',
            '--password' => 'second-pass-1234',
        ]);

        $this->assertSame(1, User::where('login', 'reviewer2')->count(), 'No duplicate user');
        $user = User::where('login', 'reviewer2')->first();
        $this->assertTrue($user->checkPassword('second-pass-1234'), 'Password was reset');
        $this->assertFalse($user->checkPassword('first-pass-1234'));
    }

    public function testFailsWithoutAPassword()
    {
        $code = Artisan::call('tailor-companion:api-user', [
            '--login' => 'reviewer3',
        ]);

        $this->assertSame(1, $code);
        $this->assertNull(User::where('login', 'reviewer3')->first());
    }
}
