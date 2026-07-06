<?php namespace Renick\TailorCompanion;

use Renick\TailorCompanion\Classes\Sync\ChangeJournal;
use System\Classes\PluginBase;

/**
 * TailorCompanion plugin — token-authenticated REST API that lets the native
 * companion app read and sync Tailor content. See docs/architecture/ in the
 * repository root for the full design.
 */
class Plugin extends PluginBase
{
    /**
     * pluginDetails about this plugin.
     */
    public function pluginDetails()
    {
        return [
            'name' => 'Tailor Companion',
            'description' => 'API backend for the native Tailor Companion app: token auth, schema and entry sync, audit log.',
            'author' => 'Renick Büttner',
            'icon' => 'icon-mobile',
        ];
    }

    /**
     * register method, called when the plugin is first registered.
     */
    public function register()
    {
    }

    /**
     * boot method, called right before the request route.
     */
    public function boot()
    {
        ChangeJournal::registerHooks();
    }

    /**
     * registerSettings adds the Tailor Companion tab to backend Settings.
     */
    public function registerSettings()
    {
        return [
            'appconnect' => [
                'label' => 'App Connect',
                'description' => 'Pair the companion app: create connection tokens and show QR codes.',
                'category' => 'Tailor Companion',
                'icon' => 'icon-qrcode',
                'url' => \Backend::url('renick/tailorcompanion/appconnect'),
                'permissions' => ['renick.tailorcompanion.manage_tokens'],
                'order' => 500,
            ],
            'auditlogs' => [
                'label' => 'Audit Log',
                'description' => 'Every change made through the companion app.',
                'category' => 'Tailor Companion',
                'icon' => 'icon-list-alt',
                'url' => \Backend::url('renick/tailorcompanion/auditlogs'),
                'permissions' => ['renick.tailorcompanion.view_audit_log'],
                'order' => 510,
            ],
            'settings' => [
                'label' => 'Settings',
                'description' => 'API master switch, token expiry, journal retention.',
                'category' => 'Tailor Companion',
                'icon' => 'icon-cog',
                'class' => \Renick\TailorCompanion\Models\Setting::class,
                'permissions' => ['renick.tailorcompanion.manage_settings'],
                'order' => 520,
            ],
        ];
    }

    /**
     * registerSchedule prunes the change journal daily per retention setting.
     */
    public function registerSchedule($schedule)
    {
        $schedule->call(function () {
            $days = (int) \Renick\TailorCompanion\Models\Setting::get('journal_retention_days', 60);
            if ($days > 0) {
                (new ChangeJournal)->prune($days);
            }
        })->daily();
    }

    /**
     * registerPermissions used by the backend.
     */
    public function registerPermissions()
    {
        return [
            'renick.tailorcompanion.access_api' => [
                'tab' => 'Tailor Companion',
                'label' => 'Access the companion app API',
            ],
            'renick.tailorcompanion.manage_tokens' => [
                'tab' => 'Tailor Companion',
                'label' => 'Manage app connection tokens',
            ],
            'renick.tailorcompanion.view_audit_log' => [
                'tab' => 'Tailor Companion',
                'label' => 'View the app audit log',
            ],
            'renick.tailorcompanion.manage_settings' => [
                'tab' => 'Tailor Companion',
                'label' => 'Manage companion app settings',
            ],
        ];
    }
}
