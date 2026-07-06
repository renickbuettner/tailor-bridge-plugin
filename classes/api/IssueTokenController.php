<?php namespace Renick\TailorCompanion\Classes\Api;

use BackendAuth;
use Illuminate\Http\Request;
use Renick\TailorCompanion\Classes\Auth\TokenManager;
use Renick\TailorCompanion\Models\AuditLog;
use Response;
use Url;
use Validator;

/**
 * IssueTokenController creates an access token from admin credentials —
 * the manual pairing path (the primary path is QR from the backend UI,
 * which issues the token server-side). Route is hard-throttled.
 */
class IssueTokenController
{
    public function __invoke(Request $request)
    {
        if (!\Renick\TailorCompanion\Models\Setting::get('api_enabled', true)) {
            return Response::json([
                'error' => ['code' => 'api_disabled', 'message' => 'The companion API is disabled in the backend settings.'],
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'login' => 'required|string',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return Response::json([
                'error' => ['code' => 'validation', 'message' => $validator->errors()->first()],
            ], 422);
        }

        // Stateless credential check — never opens a session.
        $user = BackendAuth::findUserByLogin($request->input('login'));

        // Run a dummy hash check for unknown users to avoid a timing oracle
        // that would reveal which logins exist.
        if (!$user) {
            \Hash::check($request->input('password'), '$2y$10$usesomesillystringforsalttcYQ6C6.Xq0z9WZK3oM.i0m2Ck3Bq');
            return Response::json([
                'error' => ['code' => 'invalid_credentials', 'message' => 'Login or password is incorrect.'],
            ], 401);
        }

        if (!$user->checkPassword($request->input('password'))) {
            return Response::json([
                'error' => ['code' => 'invalid_credentials', 'message' => 'Login or password is incorrect.'],
            ], 401);
        }

        if (!$user->is_activated) {
            return Response::json([
                'error' => ['code' => 'forbidden', 'message' => 'This account is deactivated.'],
            ], 403);
        }

        if (!$user->hasAccess('renick.tailorcompanion.access_api')) {
            return Response::json([
                'error' => ['code' => 'forbidden', 'message' => 'The user is not permitted to use the companion API.'],
            ], 403);
        }

        $expiryDays = (int) \Renick\TailorCompanion\Models\Setting::get('token_expiry_days', 0);
        $expiresAt = $expiryDays > 0 ? \Date::now()->addDays($expiryDays) : null;

        $result = (new TokenManager)->issue($user, $request->input('device_name'), $expiresAt);

        AuditLog::record('token_issued', [
            'token_id' => $result['model']->id,
            'backend_user_id' => $user->id,
        ]);

        return Response::json([
            'data' => [
                'token' => $result['token'],
                'token_prefix' => $result['model']->token_prefix,
                'name' => $result['model']->name,
                'expires_at' => optional($result['model']->expires_at)->toIso8601String(),
                'url' => Url::to('/'),
                'user' => [
                    'login' => $user->login,
                    'name' => trim($user->first_name . ' ' . $user->last_name),
                ],
            ],
        ], 201);
    }
}
