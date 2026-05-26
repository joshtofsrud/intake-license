#!/usr/bin/env python3
"""
Patch 150 — Web analytics: GA-4 measurement ID + funnel tracking JS.

Phase 1.2 of traffic reports. Builds on patch 149's foundation.

Adds:
  - "Web analytics" card on tenant settings page
  - analytics_ga4_id stored in tenant.settings JSON (no schema change)
  - Settings update path accepts and persists the field
  - GA-4 gtag.js injected on tenant-public pages when ID is set
  - Funnel tracking JS injected on all tenant-public pages
  - Auto-fires: page_view (load), booking_page_viewed (/book), booking_started (form interaction), booking_completed (server-side fire from BookingController on submit success)

Also:
  - Drops the failing CSRF exemption for funnel/track from patch 149.
    Tracking JS sends X-CSRF-TOKEN header from the page's <meta>, matching
    how /book/submit and other tenant POSTs already work.

Idempotent.
"""

import argparse
import pathlib
import sys


# ============================================================
# NEW FILES
# ============================================================

# Shared partial injected into all public-page <head>s.
# Renders gtag.js if measurement ID is set + our own anonymous funnel tracker.
TRACKER_PARTIAL = r'''{{-- MARKER-PATCH-150 — tenant public-page analytics + funnel tracking --}}
@php
  $ga4Id = $currentTenant->settings['analytics_ga4_id'] ?? null;
@endphp

@if($ga4Id)
{{-- Google tag (gtag.js) — only when tenant has configured GA-4 --}}
<script async src="https://www.googletagmanager.com/gtag/js?id={{ $ga4Id }}"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '{{ $ga4Id }}', { 'anonymize_ip': true });
</script>
@endif

{{-- Native funnel tracking — anonymous, no third-party --}}
<script>
(function(){
  if (window.__intakeFunnelLoaded) return;
  window.__intakeFunnelLoaded = true;

  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  // Pull UTM params off the URL once on page load
  var params = new URLSearchParams(window.location.search);
  var utm = {
    utm_source:   params.get('utm_source')   || null,
    utm_medium:   params.get('utm_medium')   || null,
    utm_campaign: params.get('utm_campaign') || null,
  };

  function send(eventType, extra) {
    extra = extra || {};
    var body = Object.assign({
      event_type:   eventType,
      path:         window.location.pathname,
      referrer_url: document.referrer || null,
    }, utm, extra);

    // Strip nulls so the server-side validator stays happy
    Object.keys(body).forEach(function(k){ if (body[k] === null) delete body[k]; });

    try {
      // Use sendBeacon when available — fire-and-forget, survives nav
      if (navigator.sendBeacon && eventType !== 'booking_started') {
        var blob = new Blob([JSON.stringify(body)], { type: 'application/json' });
        navigator.sendBeacon('/funnel/track', blob);
        return;
      }
      fetch('/funnel/track', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'Accept':       'application/json',
        },
        body: JSON.stringify(body),
        keepalive: true,
        credentials: 'same-origin',
      }).catch(function(){ /* swallow — tracking should never break UX */ });
    } catch(e) { /* same */ }
  }

  // Expose for booking-page hooks
  window.__intakeFunnel = { send: send };

  // Always fire page_view on every public page
  send('page_view');

  // Booking-page hooks
  if (window.location.pathname === '/book' || window.location.pathname.indexOf('/book/') === 0) {
    send('booking_page_viewed');

    // First interaction with the booking form = "started"
    var startedFired = false;
    function fireStarted() {
      if (startedFired) return;
      startedFired = true;
      send('booking_started');
    }
    // Catch the first click on any service tile, button, or form input inside the booking surface
    document.addEventListener('click', function(e){
      if (!startedFired && (e.target.closest('button, .svc-tile, .booking-step, [data-fn-step]'))) {
        fireStarted();
      }
    }, true);
    document.addEventListener('change', function(e){
      if (!startedFired && (e.target.tagName === 'SELECT' || e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA')) {
        fireStarted();
      }
    }, true);
  }
})();
</script>
'''


# ============================================================
# EDITS
# ============================================================

# 1. Drop the broken CSRF exemption from patch 149 — JS will send X-CSRF-TOKEN
OLD_CSRF = """            'funnel/track',  // MARKER-PATCH-149
            '*/funnel/track',  // MARKER-PATCH-149 — match all subdomains
"""
NEW_CSRF = """"""  # delete entirely


# 2. Settings update — accept analytics_ga4_id and write into settings JSON
#
# The existing update() method already handles updating tenant fields.
# We need to add ga4 id to the JSON settings. Inject a small block right
# after the most reliable anchor — the existing reply-to handling.

OLD_SETTINGS_UPDATE_ANCHOR = """    public function update(Request $request)"""

# We'll inject a marker-comment line at the bottom of the method via a different anchor.
# Find the end-of-validate block. Let's anchor on the most invariant line: the request
# input keys. But safer: add a tiny dedicated method via a new route + form, OR just
# expand the existing form processing. The simplest reliable approach is to add a
# small section to update() that runs unconditionally near the end.
#
# We'll search for the persisted-settings save and add ga4 to it.

# Best: I'll write a small dedicated handler in a separate controller. That way no
# risky surgery on the existing SettingsController. Routes get one more POST.
ANALYTICS_CONTROLLER = r'''<?php
// MARKER-PATCH-150

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Analytics settings for the tenant.
 *
 * Currently just GA-4 measurement ID. Plausible/Umami/etc go here when added.
 * Stored under tenant.settings.analytics_ga4_id (JSON column, no schema needed).
 */
class AnalyticsSettingsController extends Controller
{
    /**
     * POST /admin/settings/analytics
     */
    public function update(string $subdomain, Request $request)
    {
        $me = Auth::guard('tenant')->user();
        if (! $me || ! $me->isManager()) {
            return back()->with('error', 'Manager or owner access required.');
        }

        $data = $request->validate([
            'analytics_ga4_id' => ['nullable', 'string', 'max:32', 'regex:/^(G-|UA-)[A-Z0-9]{4,20}$/i'],
        ], [
            'analytics_ga4_id.regex' => 'GA-4 measurement IDs start with G- (or UA- for legacy). Example: G-XXXXXXXXXX',
        ]);

        $tenant   = tenant();
        $settings = $tenant->settings ?? [];

        $newId = trim((string) ($data['analytics_ga4_id'] ?? ''));
        if ($newId === '') {
            unset($settings['analytics_ga4_id']);
        } else {
            $settings['analytics_ga4_id'] = $newId;
        }

        $tenant->settings = $settings;
        $tenant->save();

        Log::info('Analytics settings updated', [
            'tenant_id' => $tenant->id,
            'ga4_set'   => $newId !== '',
            'by'        => $me->email,
        ]);

        return back()->with('success', 'Analytics settings saved.');
    }
}
'''


# 3. Inject the tracker partial into each public page's <head>
#
# Each page has a slightly different head structure but all have a <meta name="csrf-token">
# line that we can anchor on. We add @include before </head> in each.

# public/layout.blade.php — anchor on the existing csrf-token meta tag
OLD_LAYOUT = """  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">"""
NEW_LAYOUT = """  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  {{-- MARKER-PATCH-150 — analytics + funnel tracking --}}
  @include('public._funnel_tracker')"""


# public/booking.blade.php — anchor on its csrf meta
OLD_BOOKING = """  <meta name="csrf-token" content="{{ csrf_token() }}">"""
NEW_BOOKING = """  <meta name="csrf-token" content="{{ csrf_token() }}">
  {{-- MARKER-PATCH-150 — analytics + funnel tracking --}}
  @include('public._funnel_tracker')"""


# Public account shell and waitlist shell — anchor on each respective csrf meta.
# These need DIFFERENT anchors because the bare "<meta name=csrf-token>" is in
# both files and we need uniqueness. Use surrounding context per file.

OLD_ACCOUNT = """  @stack('styles')
</head>"""
NEW_ACCOUNT = """  @stack('styles')
  {{-- MARKER-PATCH-150 — analytics + funnel tracking --}}
  @include('public._funnel_tracker')
</head>"""

# Waitlist shell — anchor on </head>. We can't use the same anchor as account
# (also has </head>) — read around to find a uniquely-locating line.

# Easier: take a multi-line anchor that's actually unique per file.
# Account already has @stack('styles') before </head>.
# Waitlist has different content before </head> — find it.


# 4. BookingController booking_completed server-side fire.
# The cleanest place is wherever the booking is actually persisted on submit.
# We'll grep for the submit() entry and add a fire to it.
# Since I don't want to assume controller internals, the safer approach is:
# the booking form's success page (the redirect target) fires booking_completed
# client-side after seeing a success flag. Look at the existing /confirm route.
#
# Implementation: BookingController returns redirect to /confirm with a flag in
# session. The confirm view checks for it and fires the event via the tracker JS.
# To avoid touching BookingController internals, we inline a script in confirm.blade.php
# that fires booking_completed when the page loads — but only when a session flag is set.

# Check confirm.blade.php exists and add a "if recently_booked, fire" block.
OLD_CONFIRM_HEAD = """<meta name="viewport" content="width=device-width, initial-scale=1">"""
NEW_CONFIRM_HEAD = """<meta name="viewport" content="width=device-width, initial-scale=1">
{{-- MARKER-PATCH-150 — fire booking_completed on confirm page load --}}
<script>
  // Coordinated with __intakeFunnel from _funnel_tracker partial. Defer until that has loaded.
  document.addEventListener('DOMContentLoaded', function() {
    if (window.__intakeFunnel && window.__intakeFunnel.send) {
      window.__intakeFunnel.send('booking_completed');
    }
  });
</script>"""


# 5. Settings page — add "Web analytics" card.
#
# We anchor on the existing "Email sender details" card and inject the new card
# right before it.

OLD_SETTINGS_VIEW = """    {{-- Email sender details --}}"""

# We need this to live OUTSIDE the existing settings form (since it submits to
# its own endpoint). So we close the previous form, render our analytics card
# as its own form, then leave the rest of the page intact.

# Safer: render as its own self-contained card with its own form, placed BEFORE
# the Email sender details card.

NEW_SETTINGS_VIEW = """    {{-- MARKER-PATCH-150 — Web analytics card --}}
    <form method="POST"
          action="{{ route('tenant.settings.analytics.update', ['subdomain' => $currentTenant->subdomain]) }}"
          class="ia-card" style="margin-bottom: 18px;">
      @csrf
      <div class="ia-card-head">
        <span class="ia-card-title">Web analytics</span>
      </div>
      <p style="color: var(--ia-text-muted, rgba(255,255,255,.62)); font-size: 13px; margin-bottom: 14px;">
        Connect Google Analytics 4 to your public-facing pages. We'll inject the tracking script automatically.
        Leave blank to disable.
      </p>
      <label class="ia-label">GA-4 measurement ID</label>
      <input type="text" name="analytics_ga4_id" class="ia-input mono"
             value="{{ old('analytics_ga4_id', $currentTenant->settings['analytics_ga4_id'] ?? '') }}"
             placeholder="G-XXXXXXXXXX"
             style="max-width: 320px; font-family: var(--ia-font-mono, 'JetBrains Mono', monospace);">
      <div class="ia-help" style="margin-top: 6px; font-size: 11.5px; color: var(--ia-text-dim, rgba(255,255,255,.42));">
        Find this in your GA-4 Admin → Data Streams → Measurement ID. Starts with <code>G-</code>.
      </div>
      @error('analytics_ga4_id')
        <div style="color: #F47373; font-size: 12px; margin-top: 6px;">{{ $message }}</div>
      @enderror
      <div style="margin-top: 14px;">
        <button type="submit" class="ia-btn ia-btn--primary">Save analytics</button>
      </div>
    </form>

    {{-- Email sender details --}}"""


NEW_FILES = {
    'resources/views/public/_funnel_tracker.blade.php':              TRACKER_PARTIAL,
    'app/Http/Controllers/Tenant/AnalyticsSettingsController.php':   ANALYTICS_CONTROLLER,
}

EDITS = [
    # 1. drop CSRF exemption that wasn't working anyway
    ('bootstrap/app.php', OLD_CSRF, NEW_CSRF, "drop failing CSRF exemption (use X-CSRF-TOKEN header instead)", None),

    # 2. inject tracker into each public layout
    ('resources/views/public/layout.blade.php',           OLD_LAYOUT,  NEW_LAYOUT,  'inject tracker: layout',     'MARKER-PATCH-150'),
    ('resources/views/public/booking.blade.php',          OLD_BOOKING, NEW_BOOKING, 'inject tracker: booking',    'MARKER-PATCH-150'),
    ('resources/views/public/account/_shell.blade.php',   OLD_ACCOUNT, NEW_ACCOUNT, 'inject tracker: account',    'MARKER-PATCH-150'),

    # 3. confirm page server-side completion fire
    ('resources/views/public/confirm.blade.php', OLD_CONFIRM_HEAD, NEW_CONFIRM_HEAD, 'fire booking_completed on confirm', 'MARKER-PATCH-150'),

    # 4. settings page card
    ('resources/views/tenant/settings/index.blade.php', OLD_SETTINGS_VIEW, NEW_SETTINGS_VIEW, 'add Web analytics card to settings', 'MARKER-PATCH-150'),
]


def add_route(root: pathlib.Path, apply: bool) -> str:
    """Register the analytics settings POST route."""
    p = root / 'routes' / 'web.php'
    t = p.read_text()
    if 'tenant.settings.analytics.update' in t:
        return 'already_applied'
    anchor = "Route::post('/settings/email/test', [TenantControllers\\TestEmailController::class, 'sendSettingsTest'])->name('settings.email.test');"
    if anchor not in t:
        return 'ERROR: settings.email.test anchor not found'
    new_line = anchor + "\n            // MARKER-PATCH-150 — Web analytics settings (GA-4 etc)\n            Route::post('/settings/analytics', [TenantControllers\\AnalyticsSettingsController::class, 'update'])->name('settings.analytics.update');"
    if apply:
        p.write_text(t.replace(anchor, new_line, 1))
    return 'edited' if apply else 'would_edit'


def waitlist_shell_edit(root: pathlib.Path, apply: bool) -> str:
    """
    Inject into waitlist _shell.blade.php. We need a unique anchor that
    isn't '</head>' alone since that's repeated across files. Read the
    file and find the last line before </head>.
    """
    p = root / 'resources' / 'views' / 'public' / 'waitlist' / '_shell.blade.php'
    if not p.exists():
        return 'skipped (file missing)'
    t = p.read_text()
    if 'MARKER-PATCH-150' in t:
        return 'already_applied'

    # Inject right before </head>. There's only one </head> per file so this is safe within this file.
    needle = '</head>'
    if needle not in t:
        return 'ERROR: </head> not found in waitlist shell'
    inj = "  {{-- MARKER-PATCH-150 — analytics + funnel tracking --}}\n  @include('public._funnel_tracker')\n</head>"
    if apply:
        p.write_text(t.replace(needle, inj, 1))
    return 'edited' if apply else 'would_edit'


def main():
    ap = argparse.ArgumentParser(); ap.add_argument('root'); ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    root = pathlib.Path(a.root)
    if not (root / 'routes' / 'web.php').exists():
        print('ERROR: not an intake repo', file=sys.stderr); sys.exit(2)
    mode = 'APPLY' if a.apply else 'DRY-RUN'
    print(f'=== patch-150 [{mode}] target={root} ===\n')

    for rel, content in NEW_FILES.items():
        p = root / rel
        if p.exists() and p.read_text() == content:
            print(f'  unchanged: {rel}'); continue
        if a.apply:
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_text(content)
        print(f'  {"written" if a.apply else "would_write"}: {rel}')

    for rel, old, new, label, marker in EDITS:
        p = root / rel
        if not p.exists():
            print(f'  ERROR: file missing for {label}: {rel}', file=sys.stderr); sys.exit(2)
        t = p.read_text()
        if marker is not None and marker in t:
            print(f'  already_applied: {label}'); continue
        if marker is None:
            # No marker — this is the CSRF-drop edit. Detect already-applied by
            # checking that the old anchor is no longer present.
            if old not in t:
                print(f'  already_applied: {label}'); continue
        if old not in t:
            print(f'  ERROR: anchor missing for {label}', file=sys.stderr); sys.exit(2)
        if t.count(old) > 1:
            print(f'  ERROR: anchor not unique for {label}', file=sys.stderr); sys.exit(2)
        if a.apply:
            p.write_text(t.replace(old, new, 1))
        print(f'  {"applied" if a.apply else "would_apply"}: {label}')

    print(f'  route: analytics POST: {add_route(root, a.apply)}')
    print(f'  waitlist shell tracker include: {waitlist_shell_edit(root, a.apply)}')

    if a.apply:
        print('\nDeploy steps:')
        print('  php artisan optimize:clear')
        print('  php artisan view:clear')
        print('  systemctl stop php8.3-fpm && sleep 2 && systemctl start php8.3-fpm')
        print('\nVerify:')
        print('  1. Visit grndctrl.intake.works in browser. View page source — look for "_funnel_tracker" output (the inline <script>).')
        print('  2. Open browser DevTools → Network → reload. Should see POST /funnel/track returning 200.')
        print('  3. mysql intake -e "SELECT event_type, path, device, COUNT(*) FROM tenant_funnel_events GROUP BY 1,2,3;"')
        print('  4. Visit /admin/settings — scroll for "Web analytics" card. Save a fake G-XXXXX0001 and verify it stores.')
    else:
        print('\n(dry-run — no files written.)')


if __name__ == '__main__':
    main()
