<?php namespace Renick\TailorCompanion\Classes\Api;

use Backend;
use BackendAuth;
use Redirect;
use Renick\TailorCompanion\Classes\Auth\SessionNonce;
use Renick\TailorCompanion\Models\AuditLog;

/**
 * BackendLoginController redeems a one-time session nonce (minted by
 * SessionController) and starts a real backend session for the token's user,
 * then redirects into the admin. Registered with the `web` middleware in
 * Plugin::boot so the session cookie is actually set.
 *
 * Security: the nonce is single-use and expires within a minute; only the
 * token's own user is logged in; every redemption is audited. An invalid or
 * stale nonce simply lands on the normal backend login page.
 */
class BackendLoginController
{
    public function login($nonce)
    {
        $userId = SessionNonce::consume((string) $nonce);

        if (!$userId) {
            return Redirect::to(Backend::url());
        }

        $user = BackendAuth::findUserById($userId);

        if (!$user || !$user->is_activated) {
            return Redirect::to(Backend::url());
        }

        BackendAuth::login($user, true);

        AuditLog::record('session_start', [
            'backend_user_id' => $user->id,
        ]);

        return Redirect::to(Backend::url());
    }
}
