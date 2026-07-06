<?php namespace Renick\TailorCompanion\Models;

use Model;

/**
 * State is a minimal plugin-owned key-value store (deliberately without any
 * static/process cache — values must be reliable within a single request and
 * across test cases).
 */
class State extends Model
{
    /**
     * @var string table associated with the model
     */
    protected $table = 'renick_tailorcompanion_state';

    /**
     * @var bool timestamps disabled
     */
    public $timestamps = false;

    /**
     * get a value by key.
     */
    public static function get(string $key, $default = null)
    {
        $record = static::where('key', $key)->first();

        return $record ? $record->value : $default;
    }

    /**
     * put a value by key (upsert).
     */
    public static function put(string $key, $value): void
    {
        $record = static::where('key', $key)->first() ?: new static;
        $record->key = $key;
        $record->value = $value;
        $record->save();
    }
}
