<?php namespace Renick\TailorCompanion\Tests\Classes\Middleware;

use Illuminate\Http\Request;
use PluginTestCase;
use Renick\TailorCompanion\Classes\Middleware\StaticPagesEnabled;
use Renick\TailorCompanion\Classes\Pages\PagesFeature;

class StaticPagesEnabledTest extends PluginTestCase
{
    public function tearDown(): void
    {
        PagesFeature::forceAvailability(null);
        parent::tearDown();
    }

    public function testUnavailableFeatureShortCircuitsWith404()
    {
        PagesFeature::forceAvailability(false);

        $response = (new StaticPagesEnabled)->handle(Request::create('/pages/tree'), function () {
            $this->fail('Next middleware must not run when the feature is unavailable.');
        });

        $this->assertSame(404, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        $this->assertSame('feature_unavailable', $payload['error']['code']);
    }

    public function testAvailableFeaturePassesThrough()
    {
        PagesFeature::forceAvailability(true);

        $called = false;
        (new StaticPagesEnabled)->handle(Request::create('/pages/tree'), function () use (&$called) {
            $called = true;
            return response()->json(['ok' => true]);
        });

        $this->assertTrue($called);
    }
}
