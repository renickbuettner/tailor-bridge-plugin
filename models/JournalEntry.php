<?php namespace Renick\TailorCompanion\Models;

use Model;

/**
 * JournalEntry is one row of the change journal. The auto-increment `id` is
 * the monotonic cursor used by the app for incremental pull sync — it captures
 * creates, updates AND (hard) deletes of canonical Tailor records regardless
 * of where the change originated (API, backend UI, console).
 */
class JournalEntry extends Model
{
    const ACTION_CREATED = 'created';
    const ACTION_UPDATED = 'updated';
    const ACTION_DELETED = 'deleted';

    /**
     * @var string table associated with the model
     */
    protected $table = 'renick_tailorcompanion_journal';

    /**
     * @var bool timestamps disabled; created_at is set explicitly
     */
    public $timestamps = false;

    /**
     * @var array dates attributes that should be mutated to dates
     */
    protected $dates = ['created_at'];
}
