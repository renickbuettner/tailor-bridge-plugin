<?php namespace Renick\TailorCompanion\Classes\Middleware;

use Closure;
use Illuminate\Http\Request;
use Renick\TailorCompanion\Classes\Pages\PagesFeature;
use Response;

/**
 * StaticPagesEnabled gates every /pages/* endpoint. RainLab.Pages is an
 * optional integration: when the plugin is missing, disabled or turned off
 * via the pages_enabled setting, all pages routes answer with one uniform
 * error the app can key on (it also learns the state via /ping).
 */
class StaticPagesEnabled
{
    public function handle(Request $request, Closure $next)
    {
        if (!PagesFeature::isAvailable()) {
            return Response::json([
                'error' => [
                    'code' => 'feature_unavailable',
                    'message' => 'Static pages are not available on this server (RainLab.Pages missing or disabled).',
                ],
            ], 404);
        }

        return $next($request);
    }
}
