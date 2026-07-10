<?php namespace Renick\TailorCompanion\Models;

use Model;
use Renick\TailorCompanion\Plugin;

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
     * getDeployedBuildAttribute surfaces the running plugin's code-level build
     * marker in the backend so you can confirm at a glance whether a deploy went
     * live (matches the `build` field of the `GET /version` API endpoint). It is
     * read from the constant compiled into the code, not from storage, so it
     * reflects the actual running bytecode.
     */
    public function getDeployedBuildAttribute(): string
    {
        return Plugin::BUILD;
    }

    /**
     * initSettingsData defaults.
     */
    public function initSettingsData()
    {
        $this->api_enabled = true;
        $this->token_expiry_days = 0;
        $this->journal_retention_days = 60;
        $this->logs_enabled = true;
        $this->pages_enabled = true;
        $this->audit_reads_enabled = true;
    }
}
