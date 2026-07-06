<?php namespace Renick\TailorCompanion\Models;

use Backend\Models\User;
use Date;
use Model;

/**
 * AccessToken authenticates the companion app against the API.
 *
 * The raw token is only known at creation time; the database stores a
 * SHA-256 hash (`token_hash`) plus a display prefix (`token_prefix`).
 */
class AccessToken extends Model
{
    use \October\Rain\Database\Traits\Validation;

    /**
     * @var string table associated with the model
     */
    protected $table = 'renick_tailorcompanion_tokens';

    /**
     * @var array rules for validation
     */
    public $rules = [
        'token_hash' => 'required|unique:renick_tailorcompanion_tokens',
        'token_prefix' => 'required',
        'backend_user_id' => 'required',
    ];

    /**
     * @var array dates attributes that should be mutated to dates
     */
    protected $dates = ['last_used_at', 'expires_at', 'revoked_at'];

    /**
     * @var array belongsTo relations
     */
    public $belongsTo = [
        'user' => [User::class, 'key' => 'backend_user_id'],
    ];

    /**
     * scopeActive filters tokens that are usable for authentication.
     */
    public function scopeActive($query)
    {
        return $query
            ->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', Date::now());
            });
    }

    /**
     * isActive returns true when the token can authenticate requests.
     */
    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && !$this->expires_at->isFuture()) {
            return false;
        }

        return true;
    }

    /**
     * touchLastUsed stamps last_used_at, throttled to avoid a write per request.
     */
    public function touchLastUsed(int $minIntervalSeconds = 60): void
    {
        if ($this->last_used_at !== null && $this->last_used_at->diffInSeconds(Date::now()) < $minIntervalSeconds) {
            return;
        }

        $this->newQuery()
            ->where('id', $this->id)
            ->update(['last_used_at' => Date::now()]);

        $this->last_used_at = Date::now();
    }
}
