<?php namespace Renick\TailorCompanion\Classes\Auth;

use Backend\Models\User;
use Carbon\CarbonInterface;
use Date;
use Renick\TailorCompanion\Models\AccessToken;

/**
 * TokenManager issues, verifies and revokes API access tokens.
 *
 * Raw tokens are 43-char base64url strings (256 bit entropy) and are never
 * stored — only their SHA-256 hash. The plugin owns this mechanism entirely;
 * October core auth is not involved beyond the user a token belongs to.
 */
class TokenManager
{
    const TOKEN_PREFIX_LENGTH = 8;

    /**
     * issue creates a new token for a backend user. Returns the raw token
     * (shown once, never recoverable) and the persisted model.
     *
     * @return array{token: string, model: AccessToken}
     */
    public function issue(User $user, ?string $name = null, ?CarbonInterface $expiresAt = null): array
    {
        $raw = $this->generateTokenValue();

        $model = new AccessToken;
        $model->name = $name;
        $model->token_hash = $this->hashToken($raw);
        $model->token_prefix = substr($raw, 0, self::TOKEN_PREFIX_LENGTH);
        $model->backend_user_id = $user->id;
        $model->expires_at = $expiresAt;
        $model->save();

        return ['token' => $raw, 'model' => $model];
    }

    /**
     * verify resolves a raw token to its active AccessToken model,
     * or null when unknown, revoked or expired. Stamps last_used_at.
     */
    public function verify(?string $raw): ?AccessToken
    {
        if (!$raw) {
            return null;
        }

        $token = AccessToken::where('token_hash', $this->hashToken($raw))->first();

        if (!$token || !$token->isActive()) {
            return null;
        }

        $token->touchLastUsed();

        return $token;
    }

    /**
     * revoke disables a token permanently while keeping it for the audit trail.
     */
    public function revoke(AccessToken $token): void
    {
        $token->revoked_at = Date::now();
        $token->save();
    }

    /**
     * generateTokenValue returns a 43-char base64url token (256 bit).
     */
    public function generateTokenValue(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * hashToken returns the hex SHA-256 digest used for storage and lookup.
     */
    public function hashToken(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
