<?php namespace Renick\TailorCompanion\Tests\Classes\Auth;

use PluginTestCase;
use Renick\TailorCompanion\Classes\Auth\SessionNonce;

class SessionNonceTest extends PluginTestCase
{
    public function testMintThenConsumeReturnsUserId()
    {
        $nonce = SessionNonce::mint(42);

        $this->assertNotEmpty($nonce);
        $this->assertSame(42, SessionNonce::consume($nonce));
    }

    public function testNonceIsSingleUse()
    {
        $nonce = SessionNonce::mint(7);

        $this->assertSame(7, SessionNonce::consume($nonce));
        $this->assertNull(SessionNonce::consume($nonce), 'A nonce must not be redeemable twice');
    }

    public function testUnknownAndEmptyNoncesYieldNull()
    {
        $this->assertNull(SessionNonce::consume('does-not-exist'));
        $this->assertNull(SessionNonce::consume(''));
    }

    public function testEachMintIsUnique()
    {
        $this->assertNotSame(SessionNonce::mint(1), SessionNonce::mint(1));
    }
}
