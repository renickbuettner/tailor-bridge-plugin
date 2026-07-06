<?php namespace Renick\TailorCompanion\Classes\Auth;

use Backend\Models\User;
use Url;

/**
 * PairingPayload is the QR-code / manual-entry contract between backend and
 * app: { v, url, login, token }. Version bumps when the shape changes.
 */
class PairingPayload
{
    const VERSION = 1;

    /**
     * build the payload array for a freshly issued token.
     */
    public static function build(User $user, string $rawToken, ?string $baseUrl = null): array
    {
        return [
            'v' => self::VERSION,
            'url' => $baseUrl ?: Url::to('/'),
            'login' => (string) $user->login,
            'token' => $rawToken,
        ];
    }

    /**
     * toJson — exact string rendered into the QR code.
     */
    public static function toJson(User $user, string $rawToken, ?string $baseUrl = null): string
    {
        return json_encode(static::build($user, $rawToken, $baseUrl), JSON_UNESCAPED_SLASHES);
    }
}
