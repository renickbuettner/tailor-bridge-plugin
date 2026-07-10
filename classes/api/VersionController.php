<?php namespace Renick\TailorCompanion\Classes\Api;

use Date;
use Renick\TailorCompanion\Plugin;
use Response;
use System;
use System\Classes\VersionManager;

/**
 * VersionController reports which plugin code is actually deployed and running.
 *
 * Unlike /ping, this endpoint is deliberately DEPENDENCY-FREE: it touches no
 * Tailor schema, no static-pages/theme introspection, no DB rows beyond the
 * version lookup. So it stays a clean 200 even when those subsystems are
 * misconfigured — which is exactly when you need to know what's deployed.
 *
 * `build` is the authoritative signal: it is a constant compiled into the
 * plugin code (Plugin::BUILD), so it reflects the running PHP bytecode, not the
 * files on disk and not the applied DB migrations. After a deploy:
 *   - route 404      → old code (this endpoint didn't exist yet)
 *   - old `build`    → files updated but OPcache/PHP-FPM still serving old code
 *   - current `build`→ new code is live
 */
class VersionController
{
    public function __invoke()
    {
        return Response::json([
            'data' => [
                'api_version' => 1,
                'build' => Plugin::BUILD,
                'plugin_version' => $this->pluginVersion(),
                'october_version' => System::VERSION,
                'server_time' => Date::now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * pluginVersion is best-effort: the declared version from version.yaml.
     * Never let a version-lookup hiccup turn this diagnostic endpoint into a
     * 500 — the `build` marker above is the reliable signal either way.
     */
    protected function pluginVersion(): ?string
    {
        try {
            return (string) VersionManager::instance()->getLatestVersion('Renick.TailorCompanion');
        }
        catch (\Throwable $ex) {
            return null;
        }
    }
}
