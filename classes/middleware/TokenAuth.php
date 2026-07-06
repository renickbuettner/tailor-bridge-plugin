<?php namespace Renick\TailorCompanion\Classes\Middleware;

use BackendAuth;
use Closure;
use Illuminate\Http\Request;
use Renick\TailorCompanion\Classes\Auth\TokenManager;
use Response;

/**
 * TokenAuth authenticates API requests via `Authorization: Bearer <token>`.
 *
 * On success the token's backend user is bound to BackendAuth (in-memory,
 * no session) so permission checks and user footprints (created_user_id etc.)
 * work exactly as if the admin performed the action.
 */
class TokenAuth
{
    const REQUEST_TOKEN_KEY = 'tailorCompanionToken';

    public function handle(Request $request, Closure $next)
    {
        if (!\Renick\TailorCompanion\Models\Setting::get('api_enabled', true)) {
            return $this->deny('api_disabled', 'The companion API is disabled in the backend settings.', 403);
        }

        $token = (new TokenManager)->verify($request->bearerToken());

        if (!$token) {
            return $this->deny('invalid_token', 'The access token is missing, invalid, revoked or expired.');
        }

        // belongsTo excludes soft-deleted users automatically
        $user = $token->user;

        if (!$user || !$user->is_activated) {
            return $this->deny('user_unavailable', 'The user this token belongs to is unavailable or deactivated.');
        }

        if (!$user->hasAccess('renick.tailorcompanion.access_api')) {
            return $this->deny('forbidden', 'The user is not permitted to use the companion API.', 403);
        }

        BackendAuth::setUser($user);
        $request->attributes->set(self::REQUEST_TOKEN_KEY, $token);

        return $next($request);
    }

    /**
     * deny builds the JSON error response.
     */
    protected function deny(string $code, string $message, int $status = 401)
    {
        return Response::json([
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }
}
