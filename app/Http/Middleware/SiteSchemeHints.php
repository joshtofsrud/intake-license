<?php

namespace App\Http\Middleware;

use App\Support\SiteLook;
use Closure;
use Illuminate\Http\Request;

/**
 * MARKER-WEB-TEMPLATES: on a site set to Match visitor's device, ask the browser to send its
 * light/dark preference with every request (Chrome does, and retries the first page with it),
 * and tell caches the page differs by that preference.
 */
class SiteSchemeHints
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if (SiteLook::$autoServed && method_exists($response, 'header')) {
            $response->headers->set('Accept-CH', 'Sec-CH-Prefers-Color-Scheme');
            $response->headers->set('Critical-CH', 'Sec-CH-Prefers-Color-Scheme');
            $response->headers->set('Vary', trim(implode(', ', array_filter([$response->headers->get('Vary'), 'Sec-CH-Prefers-Color-Scheme', 'Cookie'])), ', '));
        }
        SiteLook::$autoServed = false;
        return $response;
    }
}
