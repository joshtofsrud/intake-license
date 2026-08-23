#!/usr/bin/env python3
"""Impersonation: the second PIN gate, and the banner that blocks the page.

Two leftovers from the first impersonation fix:

  1. EnsurePinFresh was exempted, but PinGateService::requirePin() — the
     per-action gate behind switch_location and anything else gated in
     future — was NOT. With a sticky window of 0 that gate fires on
     EVERY attempt, so an impersonated session still hits a PIN prompt
     it can never satisfy. Both gates now consult ONE helper, so a third
     gate added later can't quietly reintroduce the same hole.

  2. Two impersonation banners rendered at once — a sticky bar pinned at
     top:0 (z-index 200) plus a duplicate card inside the content area.
     The sticky one covers the top of the page, which is where page
     headers and primary actions live, and on mobile it eats scarce
     vertical space. Both are replaced by a marker in the sidebar user
     block — persistent chrome that never overlaps content — plus a
     compact fixed chip on mobile, where the sidebar isn't visible.

Deliberately NOT made subtle: acting on a real tenant's data without
realising it is the failure the banner exists to prevent, so the
sidebar block is tinted amber for the whole session and Stop is always
one click away.
Run from repo root: python3 apply-impersonation-gate-and-banner.py
"""
import sys

def read(p):
    with open(p) as f: return f.read()
def write(p, s):
    with open(p, 'w') as f: f.write(s)
def sub(p, old, new, label):
    s = read(p)
    # An empty `new` means removal — "already applied" is then the ABSENCE of
    # old, not the presence of new (an empty string matches everything).
    if new == '':
        if old not in s:
            print(f"SKIP (already applied): {label}"); return
    elif new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    write(p, s.replace(old, new, 1))
    print(f"OK: {label}")

GATE    = 'app/Services/PinGateService.php'
MW      = 'app/Http/Middleware/EnsurePinFresh.php'
HELPERS = 'app/helpers.php'
SIDEBAR = 'resources/views/layouts/tenant/_sidebar.blade.php'
LAYOUT  = 'resources/views/layouts/tenant/app.blade.php'

# ============================================================
# 1) One helper both gates agree on
# ============================================================
sub(HELPERS,
    """if (! function_exists('tlocal_date')) {""",
    """if (! function_exists('is_impersonating')) {
    /**
     * MARKER-IMPERSONATION-PIN — is this session a platform operator acting
     * as a tenant user? Every PIN gate must consult this: the operator
     * cannot know the tenant's PIN, so enforcing one locks them out of a
     * session they legitimately hold. Reaching impersonation already
     * required a master admin login, which is the stronger check.
     */
    function is_impersonating(): bool
    {
        return session()->has('impersonating_from');
    }
}

if (! function_exists('tlocal_date')) {""",
    "helper: is_impersonating")

# ============================================================
# 2) Action gate exemption
# ============================================================
sub(GATE,
    """        $tenant = app('tenant') ?? null;
        if (! $tenant || ! $tenant->pin_tier_active) {
            return false;
        }

        $stickySec = \\App\\Services\\TenantAuthPolicy::actionStickySec($tenant, $action);""",
    """        $tenant = app('tenant') ?? null;
        if (! $tenant || ! $tenant->pin_tier_active) {
            return false;
        }

        // MARKER-IMPERSONATION-PIN — the per-action gate has the same problem
        // the idle lock did: with a sticky window of 0 it fires on every
        // attempt, and an impersonating operator has no PIN to give.
        if (is_impersonating()) {
            return false;
        }

        $stickySec = \\App\\Services\\TenantAuthPolicy::actionStickySec($tenant, $action);""",
    "gate: exemption")

# ============================================================
# 3) Middleware uses the shared helper
# ============================================================
sub(MW,
    """        if ($request->session()->has('impersonating_from')) {""",
    """        if (is_impersonating()) {""",
    "middleware: use helper")

# ============================================================
# 4) Sidebar — badge on the user block, Stop link in the menu
# ============================================================
sub(SIDEBAR,
    """      <summary class="ia-sb-user-row" aria-haspopup="menu" aria-label="Account menu">""",
    """      <summary class="ia-sb-user-row {{ is_impersonating() ? 'is-impersonating' : '' }}" aria-haspopup="menu" aria-label="Account menu">""",
    "sidebar: row class")

sub(SIDEBAR,
    """          <div class="ia-sb-user-role">{{ ucfirst($authUser->role) }}</div>""",
    """          <div class="ia-sb-user-role">{{ ucfirst($authUser->role) }}</div>
          {{-- MARKER-IMPERSONATION-PIN — persistent chrome, so the state is
               always visible without a bar sitting on top of the page. --}}
          @if(is_impersonating())
            <div class="ia-sb-imp-badge">Impersonating</div>
          @endif""",
    "sidebar: badge")

sub(SIDEBAR,
    """      <div class="ia-sb-user-menu" role="menu">""",
    """      <div class="ia-sb-user-menu" role="menu">
        @if(is_impersonating())
          <a href="{{ config('app.url') }}/admin/impersonate/stop" class="ia-sb-imp-stop" role="menuitem">
            <span class="ia-sb-imp-dot" aria-hidden="true"></span>
            <span>
              <span class="ia-sb-imp-title">Stop impersonating</span>
              <span class="ia-sb-imp-sub">Their PIN lock is bypassed</span>
            </span>
          </a>
        @endif
""",
    "sidebar: stop link in menu")

# ============================================================
# 5) Styles live in base.css, not an inline block
# ============================================================
CSS = """

/* MARKER-IMPERSONATION-PIN — impersonation reads in the sidebar user block
   instead of a sticky bar that covered page headers and primary actions. */
.ia-sb-user-row.is-impersonating {
  background: rgba(133,79,11,.22);
  box-shadow: inset 2px 0 0 #FBBF24;
}
.ia-sb-imp-badge {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: 9.5px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase;
  color: #FBBF24; margin-top: 3px;
}
.ia-sb-imp-badge::before {
  content: ''; width: 5px; height: 5px; border-radius: 50%; background: #FBBF24; flex: none;
}
.ia-sb-imp-stop {
  display: flex; align-items: center; gap: 9px;
  padding: 9px 10px; margin-bottom: 4px;
  border-radius: 7px; text-decoration: none;
  background: rgba(133,79,11,.25); color: #FAEEDA;
}
.ia-sb-imp-stop:hover { background: rgba(133,79,11,.4); }
.ia-sb-imp-dot { width: 7px; height: 7px; border-radius: 50%; background: #FBBF24; flex: none; }
.ia-sb-imp-title { display: block; font-size: 12.5px; font-weight: 600; }
.ia-sb-imp-sub { display: block; font-size: 10.5px; opacity: .7; margin-top: 1px; }

/* The sidebar isn't on screen below 900px, so a compact chip carries it. */
.ia-imp-chip {
  position: fixed; left: 12px; bottom: 76px; z-index: 190;
  display: none; align-items: center; gap: 7px;
  background: #854F0B; color: #FAEEDA;
  border-radius: 99px; padding: 7px 13px;
  font-size: 11.5px; font-weight: 600; text-decoration: none;
  box-shadow: 0 4px 14px rgba(0,0,0,.4);
}
.ia-imp-chip::before {
  content: ''; width: 6px; height: 6px; border-radius: 50%; background: #FBBF24; flex: none;
}
@media (max-width: 900px) { .ia-imp-chip { display: inline-flex; } }
"""

CSSFILE = 'public/css/tenant/base.css'
css = read(CSSFILE)
if 'MARKER-IMPERSONATION-PIN' in css:
    print("SKIP (already applied): base.css styles")
else:
    write(CSSFILE, css + CSS)
    print("OK: base.css styles")

# ============================================================
# 6) Layout — drop both banners, add the mobile chip
# ============================================================
sub(LAYOUT,
    """    {{-- Impersonation banner --}}
    @if(session('impersonating_from'))
      <div style="background:#854F0B;color:#fff;padding:8px 20px;font-size:13px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:200">
        <span>⚠ You are impersonating this tenant as an admin.</span>
        <a href="{{ config('app.url') }}/admin/impersonate/stop" style="color:#FCD34D;font-weight:600">Stop impersonating →</a>
      </div>
    @endif
""",
    """    {{-- MARKER-IMPERSONATION-PIN — the sticky bar sat at top:0 over page
         headers and primary actions, and a second copy rendered inside the
         content area below. Both are gone; the state now lives in the
         sidebar user block, with a fixed chip on mobile. --}}
    @if(is_impersonating())
      <a href="{{ config('app.url') }}/admin/impersonate/stop" class="ia-imp-chip">
        Impersonating · stop
      </a>
    @endif
""",
    "layout: sticky bar to chip")

# The in-content duplicate goes too — one impersonation signal, not three.
sub(LAYOUT,
    """      {{-- Impersonation banner --}}
      @if(session('impersonating_tenant_name') || session()->has('impersonating_from'))
        <div style="background:#854F0B;color:#FAEEDA;padding:10px 16px;border-radius:var(--ia-r-md);margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;font-size:13px">
          <span>
            👤 You are impersonating <strong>{{ session('impersonating_tenant_name', 'this tenant') }}</strong>.
            All actions you take are real.
            {{-- MARKER-IMPERSONATION-PIN — be explicit about which of the
                 tenant's protections are not in force right now. --}}
            <span style="display:block;opacity:.8;font-size:12px;margin-top:2px">
              Their PIN lock is bypassed for this session.
            </span>
          </span>
          <a href="{{ config('app.url') }}/admin/impersonate/stop"
             style="background:rgba(0,0,0,.2);color:#FAEEDA;padding:5px 14px;border-radius:6px;font-weight:600;font-size:12px">
            Stop impersonating →
          </a>
        </div>
      @endif
""",
    "",
    "layout: drop in-content duplicate")


print("\\nDone. No migration needed. view:clear after deploy.")
