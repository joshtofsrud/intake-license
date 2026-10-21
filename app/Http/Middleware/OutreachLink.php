<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MARKER-EMAIL-OUTREACH: a prospect's own link, intake.works/<page>?p=<code>.
 *
 * Remembers the code in a cookie and redirects to the same page without it,
 * tagged utm_source=outreach so Marketing traffic shows where the visit came
 * from. Clean URL means a link they forward or bookmark isn't credited to them.
 * Nothing is recorded here: visits are written when the page's tracker
 * reports in (ProspectOutreach::recordVisit), so link scanners don't count.
 */
class OutreachLink
{
    public function handle(Request $request, Closure $next): Response
    {
        $code = $request->query('p');
        if (! $request->isMethod('GET') || ! is_string($code) || ! preg_match('/^[a-z0-9]{8}$/', $code)) {
            return $next($request);
        }

        $query = $request->query();
        unset($query['p']);
        $query += ['utm_source' => 'outreach', 'utm_medium' => 'email'];

        return redirect()->to($request->url() . '?' . http_build_query($query), 302)
            ->withCookie(cookie('mkt_p', $code, 60 * 24 * 90, '/', null, $request->isSecure(), true, false, 'lax'));
    }
}
