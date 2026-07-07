<?php namespace Renick\TailorCompanion\Classes\Api;

use Response;
use System\Classes\SiteManager;

/**
 * SitesController lists the OctoberCMS sites the app can switch between.
 * Single-site installs report one site with multisite=false.
 */
class SitesController
{
    public function __invoke()
    {
        $manager = SiteManager::instance();

        $sites = collect($manager->listSites())->map(function ($site) use ($manager) {
            return [
                'id' => (int) $site->id,
                'name' => (string) $site->name,
                'code' => (string) $site->code,
                'locale' => (string) $site->locale,
                'is_primary' => (bool) $site->is_primary,
                'is_enabled' => (bool) $site->is_enabled,
            ];
        })->values();

        return Response::json([
            'data' => $sites,
            'meta' => [
                'multisite' => $manager->hasMultiSite(),
                'primary_site_id' => optional($manager->getPrimarySite())->id,
            ],
        ]);
    }
}
