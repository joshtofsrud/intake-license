#!/usr/bin/env python3
"""Sidebar account menu: clock in / clock out where you already are.

Clocking out meant navigating to the time clock page. The account menu
already holds the person-scoped actions (theme, switch user, sign out),
so the punch belongs there too. The item is state-aware — it clocks you
out with elapsed time when a punch is open, clocks you in when it
isn't, and hides entirely for anyone flagged "never clocks in".
Run from repo root: python3 apply-sidebar-clock-toggle.py
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

SIDEBAR = 'resources/views/layouts/tenant/_sidebar.blade.php'

# The clock item sits at the top of the menu — it is the thing someone
# opens this menu for mid-shift, above theme and sign out.
sub(SIDEBAR,
    """      <div class="ia-sb-user-menu" role="menu">
        {{-- MARKER-USER-THEME-PREF — theme toggle writes THIS person's""",
    """      <div class="ia-sb-user-menu" role="menu">
        {{-- MARKER-SIDEBAR-CLOCK — punch without leaving the page. Hidden for
             anyone exempt from the clock; shows elapsed time when on shift. --}}
        @if(!$authUser->exempt_from_timeclock)
          @php
            $sbPunch = \\App\\Models\\Tenant\\TenantTimePunch::openFor($currentTenant->id, $authUser->id);
            $sbMins  = $sbPunch ? $sbPunch->minutes() : 0;
          @endphp
          <form method="POST" action="{{ $sbPunch ? route('tenant.timeclock.out') : route('tenant.timeclock.in') }}" style="margin:0">
            @csrf
            <button type="submit" class="ia-sb-user-menu-item" role="menuitem">
              @if($sbPunch)
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/>
                </svg>
                <span>Clock out<span style="opacity:.5"> · {{ intdiv($sbMins, 60) }}h {{ $sbMins % 60 }}m</span></span>
              @else
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <circle cx="12" cy="12" r="9"/><polyline points="12 8 12 12 14 15"/>
                </svg>
                <span>Clock in</span>
              @endif
            </button>
          </form>
        @endif

        {{-- MARKER-USER-THEME-PREF — theme toggle writes THIS person's""",
    "sidebar: clock in/out item")

print("Done. No migration needed. view:clear after deploy.")
