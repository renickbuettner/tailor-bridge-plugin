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
     * BUILD is a code-level deploy marker. Bump it on every deploy-relevant
     * change so `GET /version` confirms which code is actually RUNNING —
     * independent of DB migrations and PHP OPcache. If `/version` 404s or still
     * reports an old build after a deploy, the new code is NOT live yet (e.g.
     * OPcache not cleared / PHP-FPM not restarted, or the wrong branch shipped).
     */
    const BUILD = '2026-07-10.3';

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
        $this->registerFatalErrorLogger();
    }

    /**
     * registerFatalErrorLogger records uncatchable fatals (OOM, timeout, stack
     * overflow) to the application log so they surface via GET /logs.
     *
     * Some failures — e.g. a runaway recursion resolving a malformed static-page
     * builder — die outside Laravel's exception handler, producing an empty-body
     * 500 with nothing logged. A shutdown hook is the only place to catch those.
     * It is deliberately tiny and self-guarding so it can never itself fail a
     * request (and does nothing on a normal shutdown).
     */
    protected function registerFatalErrorLogger(): void
    {
        register_shutdown_function(function () {
            $error = error_get_last();
            $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

            if (!$error || !in_array($error['type'] ?? 0, $fatal, true)) {
                return;
            }

            try {
                \Log::error(sprintf(
                    'TailorCompanion: fatal shutdown — %s in %s:%d',
                    $error['message'] ?? '',
                    $error['file'] ?? '',
                    $error['line'] ?? 0
                ));
            }
            catch (\Throwable $ignored) {
                // Never let diagnostics break shutdown.
            }
        });
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
