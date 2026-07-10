<?php namespace Renick\TailorCompanion\Classes\Api;

use BackendAuth;
use Renick\TailorCompanion\Classes\Auth\SessionNonce;
use Response;

/**
 * SessionController hands the paired app a one-time URL that logs the token's
 * user into the OctoberCMS backend. The app opens the URL in a web view: a
 * top-level navigation can't send the Bearer header, so we bridge identity via
 * a single-use nonce (see SessionNonce). The redeeming route lives in
 * Plugin::boot (it needs the stateful `web` middleware, not the JSON API stack).
 */
class SessionController
{
    public function __invoke()
    {
        $user = BackendAuth::getUser();
        $nonce = SessionNonce::mint((int) $user->id);

        return Response::json([
            'data' => [
                'url' => url('tailor-companion/session/' . $nonce),
                'expires_in' => SessionNonce::TTL_SECONDS,
            ],
        ]);
    }
}
