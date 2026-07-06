<?php namespace Renick\TailorCompanion\Models;

use Backend\Models\User;
use Model;
use Request;

/**
 * AuditLog records every mutation performed through the companion app API.
 *
 * The field-level diff lives in the `diff` column — deliberately NOT named
 * `changes`: Eloquent has an internal protected $changes property that would
 * shadow the attribute for any access from inside the model class.
 */
class AuditLog extends Model
{
    /**
     * @var string table associated with the model
     */
    protected $table = 'renick_tailorcompanion_audit_logs';

    /**
     * @var array jsonable attribute names that are json encoded and decoded from the database
     */
    protected $jsonable = ['diff'];

    /**
     * @var array belongsTo relations
     */
    public $belongsTo = [
        'user' => [User::class, 'key' => 'backend_user_id'],
        'token' => [AccessToken::class, 'key' => 'token_id'],
    ];

    /**
     * getBlueprintHandleAttribute resolves the uuid for display purposes.
     */
    public function getBlueprintHandleAttribute(): ?string
    {
        if (!$this->blueprint_uuid) {
            return null;
        }

        $blueprint = \Tailor\Classes\BlueprintIndexer::instance()->find($this->blueprint_uuid);

        return $blueprint ? (string) $blueprint->handle : $this->blueprint_uuid;
    }

    /**
     * getDiffPrettyAttribute renders the diff as readable JSON.
     */
    public function getDiffPrettyAttribute(): string
    {
        return json_encode($this->getAttribute('diff') ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * record writes an audit entry, capturing request context automatically.
     * Uses setAttribute explicitly — plain property assignment from inside
     * the class would bypass __set and hit internal Eloquent properties.
     */
    public static function record(string $action, array $attributes = []): static
    {
        $log = new static;
        $log->setAttribute('action', $action);

        foreach ($attributes as $key => $value) {
            $log->setAttribute($key, $value);
        }

        if (!$log->getAttribute('ip') && Request::instance()) {
            $log->setAttribute('ip', Request::ip());
            $log->setAttribute('user_agent', substr((string) Request::header('User-Agent'), 0, 255));
        }

        $log->save();

        return $log;
    }
}
