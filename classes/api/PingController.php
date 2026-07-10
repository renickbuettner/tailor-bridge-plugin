<?php namespace Renick\TailorCompanion\Classes\Api;

use BackendAuth;
use Date;
use Renick\TailorCompanion\Classes\Pages\LayoutSchemaSerializer;
use Renick\TailorCompanion\Classes\Pages\PagesFeature;
use Renick\TailorCompanion\Classes\Schema\SchemaFingerprint;
use Renick\TailorCompanion\Models\Setting;
use Response;
use System;
use System\Classes\VersionManager;

/**
 * PingController validates the token and returns server + schema metadata.
 * The app calls this at every sync start; `schema_version` drives the
 * schema-change flow (docs/architecture/04-sync-protocol.md).
 */
class PingController
{
    public function __invoke()
    {
        $user = BackendAuth::getUser();

        return Response::json([
            'data' => [
                'api_version' => 1,
                'plugin_version' => (string) VersionManager::instance()->getLatestVersion('Renick.TailorCompanion'),
                'october_version' => System::VERSION,
                'user' => [
                    'login' => $user->login,
                    'name' => trim($user->first_name . ' ' . $user->last_name),
                ],
                'schema_version' => (new SchemaFingerprint)->globalVersion(),
                'server_time' => Date::now()->toIso8601String(),
                // Optional capabilities — clients treat a missing key as "off"
                'features' => [
                    'static_pages' => $this->staticPagesFeature(),
                ],
                'branding' => $this->branding(),
            ],
        ]);
    }

    /**
     * branding returns the customizable app presentation (title, logo, preview
     * website). All fields are nullable — an unset value means "use the app
     * default". Wrapped so a misconfigured logo path can never 500 /ping.
     */
    protected function branding(): array
    {
        try {
            $title = trim((string) Setting::get('brand_title', ''));
            $preview = trim((string) Setting::get('preview_url', ''));
            $logo = trim((string) Setting::get('brand_logo', ''));

            return [
                'title' => $title !== '' ? $title : null,
                'logo_url' => $logo !== '' ? $this->logoUrl($logo) : null,
                'preview_url' => $preview !== '' ? $preview : null,
            ];
        }
        catch (\Throwable $ex) {
            return ['title' => null, 'logo_url' => null, 'preview_url' => null];
        }
    }

    /**
     * logoUrl resolves a media-library path to an absolute URL, or null when
     * it can't be resolved (missing media module, bad path).
     */
    protected function logoUrl(string $path): ?string
    {
        try {
            return \Media\Classes\MediaLibrary::url($path);
        }
        catch (\Throwable $ex) {
            return null;
        }
    }

    /**
     * staticPagesFeature reports the optional RainLab.Pages capability.
     *
     * /ping is called at the start of every sync, so it must NEVER fail because
     * of an optional feature: any error introspecting static pages (e.g. no
     * resolvable active theme, a broken layout, a RainLab version mismatch)
     * degrades to "unavailable" instead of taking the whole endpoint — and thus
     * the whole app — down with a 500.
     */
    protected function staticPagesFeature(): array
    {
        try {
            if (!PagesFeature::isAvailable()) {
                return ['available' => false, 'schema_version' => null];
            }

            return [
                'available' => true,
                'schema_version' => (new LayoutSchemaSerializer)->schemaVersion(),
            ];
        }
        catch (\Throwable $ex) {
            return ['available' => false, 'schema_version' => null];
        }
    }
}
