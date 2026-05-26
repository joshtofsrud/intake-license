#!/usr/bin/env python3
"""
Patch 149-fix — repair FunnelTrackController.

Bug: declared store(string $subdomain, Request $request) but the route
is in the public tenant group which resolves tenant via middleware,
not a URL parameter. Result was "Too few arguments to function" 500.

Fix: drop the $subdomain param, key the rate-limiter on tenant id.

Idempotent.
"""

import argparse
import pathlib
import sys


OLD = """    /**
     * POST /funnel/track
     */
    public function store(string $subdomain, Request $request)
    {
        // Rate limit: 60 events / minute per IP per tenant. Generous
        // enough for legitimate use (page_view per nav, booking events
        // per step) but blocks abusive scripted floods.
        $key = 'funnel-track:' . $request->ip() . ':' . $subdomain;
        if (RateLimiter::tooManyAttempts($key, 60)) {
            return response()->json(['ok' => false, 'reason' => 'rate_limited'], 429);
        }
        RateLimiter::hit($key, 60);

        $tenant = tenant();
        if (! $tenant) {
            return response()->json(['ok' => false, 'reason' => 'no_tenant'], 404);
        }"""

NEW = """    /**
     * POST /funnel/track
     */
    public function store(Request $request)
    {
        $tenant = tenant();
        if (! $tenant) {
            return response()->json(['ok' => false, 'reason' => 'no_tenant'], 404);
        }

        // Rate limit: 60 events / minute per IP per tenant. Generous
        // enough for legitimate use (page_view per nav, booking events
        // per step) but blocks abusive scripted floods.
        $key = 'funnel-track:' . $request->ip() . ':' . $tenant->id;
        if (RateLimiter::tooManyAttempts($key, 60)) {
            return response()->json(['ok' => false, 'reason' => 'rate_limited'], 429);
        }
        RateLimiter::hit($key, 60);"""

MARKER = 'public function store(Request $request)'


def main():
    ap = argparse.ArgumentParser(); ap.add_argument('root'); ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    root = pathlib.Path(a.root)
    p = root / 'app' / 'Http' / 'Controllers' / 'Tenant' / 'FunnelTrackController.php'
    if not p.exists():
        print('ERROR: FunnelTrackController not found — apply patch 149 first', file=sys.stderr)
        sys.exit(2)
    t = p.read_text()
    if MARKER in t:
        print('  already_applied: 149-fix')
        return
    if OLD not in t:
        print('  ERROR: anchor not found', file=sys.stderr)
        sys.exit(2)
    if a.apply:
        p.write_text(t.replace(OLD, NEW, 1))
        print('  applied: 149-fix')
        print('\nDeploy: git pull && php artisan optimize:clear && systemctl restart php8.3-fpm')
    else:
        print('  would_apply: 149-fix')


if __name__ == '__main__':
    main()
