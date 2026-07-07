<?php namespace Renick\TailorCompanion\Models;

use Model;

/**
 * Setting stores plugin configuration (Settings → Tailor Companion → Settings).
 * Persisted in October's system_settings storage via the SettingsModel behavior.
 */
class Setting extends Model
{
    public $implement = [\System\Behaviors\SettingsModel::class];

    /**
     * @var string settingsCode unique to this plugin
     */
    public $settingsCode = 'renick_tailorcompanion_settings';

    /**
     * @var string settingsFields form definition
     */
    public $settingsFields = 'fields.yaml';

    /**
     * initSettingsData defaults.
     */
    public function initSettingsData()
    {
        $this->api_enabled = true;
        $this->token_expiry_days = 0;
        $this->journal_retention_days = 60;
        $this->logs_enabled = true;
    }
}
