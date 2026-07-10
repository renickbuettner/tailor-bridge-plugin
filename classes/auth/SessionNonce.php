<?php namespace Renick\TailorCompanion\Classes\Auth;

use Cache;

/**
 * SessionNonce mints and consumes single-use, short-lived nonces that let a
 * paired app hand off its (header-only) Bearer identity to a browser/WebView
 * navigation — which cannot carry the Authorization header — to start a real
 * backend session.
 *
 * A nonce is random, stored hashed, expires quickly, and is consumed atomically
 * (Cache::pull = get + forget), so it can be redeemed exactly once.
 */
class SessionNonce
{
    /** How long a freshly minted nonce stays redeemable. */
    public const TTL_SECONDS = 60;

    protected const PREFIX = 'tc_login_';

    /**
     * mint issues a nonce for a backend user id and returns the raw token
     * (the hash is what's stored, never the raw value).
     */
    public static function mint(int $userId): string
    {
        $nonce = bin2hex(random_bytes(32));
        Cache::put(self::key($nonce), $userId, self::TTL_SECONDS);

        return $nonce;
    }

    /**
     * consume redeems a nonce exactly once, returning its user id or null when
     * the nonce is missing, already used or expired.
     */
    public static function consume(string $nonce): ?int
    {
        if ($nonce === '') {
            return null;
        }

        $userId = Cache::pull(self::key($nonce));

        return $userId !== null ? (int) $userId : null;
    }

    protected static function key(string $nonce): string
    {
        return self::PREFIX . hash('sha256', $nonce);
    }
}
