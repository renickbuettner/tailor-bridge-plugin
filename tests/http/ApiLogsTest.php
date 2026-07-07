<?php namespace Renick\TailorCompanion\Tests\Http;

use Backend\Models\User;
use Config;
use PluginTestCase;
use Renick\TailorCompanion\Classes\Auth\TokenManager;
use Renick\TailorCompanion\Models\Setting;

class ApiLogsTest extends PluginTestCase
{
    protected array $authHeader;
    protected string $tmpLog;

    public function setUp(): void
    {
        parent::setUp();
        $this->migrateTailor();

        $user = new User;
        $user->first_name = 'Log';
        $user->last_name = 'Tester';
        $user->login = 'logtester';
        $user->email = 'logtester@example.com';
        $user->password = 'log-pass-123456';
        $user->password_confirmation = 'log-pass-123456';
        $user->is_superuser = true;
        $user->is_activated = true;
        $user->save();

        $result = (new TokenManager)->issue($user);
        $this->authHeader = ['Authorization' => 'Bearer ' . $result['token']];

        // Point the single log channel at an isolated temp file so the tests
        // never touch (or depend on) the real application log.
        $this->tmpLog = tempnam(sys_get_temp_dir(), 'apilog_');
        Config::set('logging.channels.single.path', $this->tmpLog);

        Setting::set('logs_enabled', true);
    }

    public function tearDown(): void
    {
        @unlink($this->tmpLog);
        parent::tearDown();
    }

    public function testLogsRequireAuth()
    {
        $this->getJson('/api/tailor-companion/v1/logs')->assertStatus(401);
    }

    public function testLogsReturnsTailWithMeta()
    {
        $lines = [];
        for ($i = 1; $i <= 20; $i++) {
            $lines[] = "entry {$i}";
        }
        file_put_contents($this->tmpLog, implode("\n", $lines) . "\n");

        $response = $this->getJson('/api/tailor-companion/v1/logs?lines=5', $this->authHeader);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertSame(['entry 16', 'entry 17', 'entry 18', 'entry 19', 'entry 20'], $data);
        $this->assertSame(basename($this->tmpLog), $response->json('meta.file'));
        $this->assertTrue($response->json('meta.truncated'));
        $this->assertSame(10000, $response->json('meta.max_lines'));
    }

    public function testLinesParamIsClampedToMax()
    {
        file_put_contents($this->tmpLog, "only one line\n");

        $response = $this->getJson('/api/tailor-companion/v1/logs?lines=99999999', $this->authHeader);

        $response->assertStatus(200);
        // Server never returns more than MAX_LINES; here just the one line.
        $this->assertCount(1, $response->json('data'));
        $this->assertFalse($response->json('meta.truncated'));
    }

    public function testDisabledSettingReturns403()
    {
        Setting::set('logs_enabled', false);

        $this->getJson('/api/tailor-companion/v1/logs', $this->authHeader)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'logs_disabled');
    }
}
