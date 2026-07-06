<?php namespace Renick\TailorCompanion\Tests\Classes\Auth;

use Backend\Models\User;
use PluginTestCase;
use Renick\TailorCompanion\Classes\Auth\PairingPayload;

class PairingPayloadTest extends PluginTestCase
{
    public function testPayloadShape()
    {
        $user = new User;
        $user->login = 'pairuser';

        $payload = PairingPayload::build($user, 'raw-token-value', 'https://cms.example.com');

        $this->assertSame([
            'v' => 1,
            'url' => 'https://cms.example.com',
            'login' => 'pairuser',
            'token' => 'raw-token-value',
        ], $payload);
    }

    public function testJsonIsStableAndUnescaped()
    {
        $user = new User;
        $user->login = 'pairuser';

        $json = PairingPayload::toJson($user, 'tok', 'https://cms.example.com/sub');

        $this->assertSame(
            '{"v":1,"url":"https://cms.example.com/sub","login":"pairuser","token":"tok"}',
            $json
        );

        $decoded = json_decode($json, true);
        $this->assertSame('tok', $decoded['token']);
    }
}
