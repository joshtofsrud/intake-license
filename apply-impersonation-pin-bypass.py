#!/usr/bin/env python3
"""Impersonation vs the PIN lock.

Impersonating a tenant logs you in as their owner, and EnsurePinFresh
then demands that owner's PIN — which the platform operator has no way
of knowing. The session is effectively bricked: every page render, and
every AJAX call, comes back locked.

The PIN is a shared-terminal control for tenant staff (stop someone
walking up to an unattended register). It was never meant as an access
control against the platform operator, who already cleared a stronger
bar — a master admin login — to get here. So an impersonated session
skips the lock entirely, and says so in the banner that's already on
screen, so it's never ambiguous whose protections are in force.

Two layers, because the middleware alone still leaves the client-side
idle timer free to throw the overlay up locally:
  1. Middleware — no staleness check while impersonating.
  2. Overlay — not rendered, which also stands idle-lock.js down: it
     already returns early when #ia-lock-overlay is absent.
Run from repo root: python3 apply-impersonation-pin-bypass.py
"""
import sys

def sub(p, old, new, label):
    s = open(p).read()
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    open(p, 'w').write(s.replace(old, new, 1))
    print(f"OK: {label}")

MW      = 'app/Http/Middleware/EnsurePinFresh.php'
LAYOUT  = 'resources/views/layouts/tenant/app.blade.php'
OVERLAY = 'resources/views/layouts/tenant/_lock-overlay.blade.php'
JS      = 'public/js/tenant/idle-lock.js'

# ============================================================
# 1) Middleware — the authoritative bypass
# ============================================================
sub(MW,
    """        if (! $tenant || ! $tenant->pin_tier_active) {
            return $next($request);
        }""",
    """        if (! $tenant || ! $tenant->pin_tier_active) {
            return $next($request);
        }

        // MARKER-IMPERSONATION-PIN — impersonation signs you in as the tenant
        // owner, whose PIN the platform operator does not have; enforcing it
        // would brick the session outright. Reaching this point already
        // required a master admin login, which is the stronger check. The
        // impersonation banner stays visible the whole time, and start/stop
        // are recorded in the debug log.
        if ($request->session()->has('impersonating_from')) {
            view()->share('pinLockPending', false);
            view()->share('pinBypassImpersonating', true);
            return $next($request);
        }""",
    "middleware: bypass")

# ============================================================
# 2) Overlay — don't render it while impersonating
# ============================================================
sub(LAYOUT,
    """@include('layouts.tenant._lock-overlay')""",
    """{{-- MARKER-IMPERSONATION-PIN — omitted while impersonating so the client
     idle timer has nothing to open. --}}
@unless(session()->has('impersonating_from'))
  @include('layouts.tenant._lock-overlay')
@endunless""",
    "layout: skip overlay")

# ============================================================
# 3) Banner — say which protections are off
# ============================================================
sub(LAYOUT,
    """            All actions you take are real.
          </span>""",
    """            All actions you take are real.
            {{-- MARKER-IMPERSONATION-PIN — be explicit about which of the
                 tenant's protections are not in force right now. --}}
            <span style="display:block;opacity:.8;font-size:12px;margin-top:2px">
              Their PIN lock is bypassed for this session.
            </span>
          </span>""",
    "layout: banner names the bypass")

print("\\nDone. No migration needed. view:clear after deploy.")
