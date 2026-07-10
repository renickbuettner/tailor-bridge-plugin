<?php namespace Renick\TailorCompanion\Tests\Classes\Middleware;

use Illuminate\Http\Request;
use PluginTestCase;
use Renick\TailorCompanion\Classes\Middleware\AuditRead;
use Renick\TailorCompanion\Models\AuditLog;
use Renick\TailorCompanion\Models\Setting;

class AuditReadTest extends PluginTestCase
{
    public function tearDown(): void
    {
        Setting::set('audit_reads_enabled', true);
        parent::tearDown();
    }

    protected function run200(string $path, string $method = 'GET', int $status = 200)
    {
        return (new AuditRead)->handle(Request::create($path, $method), function () use ($status) {
            return response()->json(['ok' => true], $status);
        });
    }

    public function testSuccessfulGetIsLoggedAsRead()
    {
        $before = AuditLog::count();

        $this->run200('/api/tailor-companion/v1/entries/1e6f-uuid/12');

        $this->assertSame($before + 1, AuditLog::count());
        $log = AuditLog::orderBy('id', 'desc')->first();
        $this->assertSame('read', $log->action);
        $this->assertArrayHasKey('endpoint', (array) $log->getAttribute('diff'));
    }

    public function testPullSyncGetsSyncAction()
    {
        $this->run200('/api/tailor-companion/v1/sync/changes?since=42');

        $log = AuditLog::orderBy('id', 'desc')->first();
        $this->assertSame('sync', $log->action);
        $diff = (array) $log->getAttribute('diff');
        $this->assertSame('42', $diff['query']['since'] ?? null);
    }

    public function testMutationsAreNotLoggedHere()
    {
        $before = AuditLog::count();

        $this->run200('/api/tailor-companion/v1/sync/batch', 'POST');

        $this->assertSame($before, AuditLog::count(), 'POST must not be audited by AuditRead');
    }

    public function testUnsuccessfulReadIsNotLogged()
    {
        $before = AuditLog::count();

        $this->run200('/api/tailor-companion/v1/entries/nope', 'GET', 404);

        $this->assertSame($before, AuditLog::count());
    }

    public function testDisabledSettingSkipsLogging()
    {
        Setting::set('audit_reads_enabled', false);
        $before = AuditLog::count();

        $this->run200('/api/tailor-companion/v1/schema');

        $this->assertSame($before, AuditLog::count());
    }
}
