<?php namespace Renick\TailorCompanion\Classes\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * ForceJson makes every API request negotiate JSON so exceptions and
 * validation errors render as JSON instead of HTML.
 */
class ForceJson
{
    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
