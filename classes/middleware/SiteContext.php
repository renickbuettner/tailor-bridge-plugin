<?php namespace Renick\TailorCompanion\Classes\Middleware;

use Closure;
use Illuminate\Http\Request;
use System\Classes\SiteManager;

/**
 * SiteContext scopes the request to a specific OctoberCMS site.
 *
 * The app sends `X-Tailor-Site: <id>` (falling back to a `site` query param).
 * When valid, that site becomes active for the request, so Tailor's multisite
 * scope filters reads and directs writes to it. Without it, the primary site
 * is used. The resolved id is exposed for controllers that need it.
 */
class SiteContext
{
    const REQUEST_SITE_KEY = 'tailorCompanionSiteId';

    public function handle(Request $request, Closure $next)
    {
        $manager = SiteManager::instance();

        $requested = $request->header('X-Tailor-Site') ?? $request->query('site');
        $siteId = null;

        if ($requested !== null && ($site = $manager->getSiteFromId((int) $requested))) {
            $siteId = (int) $site->id;
        } elseif ($primary = $manager->getPrimarySite()) {
            $siteId = (int) $primary->id;
        }

        if ($siteId !== null) {
            $manager->setActiveSiteId($siteId);
        }

        $request->attributes->set(self::REQUEST_SITE_KEY, $siteId);

        return $next($request);
    }
}
