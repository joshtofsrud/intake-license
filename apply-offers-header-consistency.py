#!/usr/bin/env python3
"""Offers page: follow the rental tab convention.

Desk, Fleet, Availability and Settings all render the rental nav FIRST
and the title block after it, so the heading reads as belonging to the
tab you're on. The Offers page I added did the reverse — header, then
nav — which is why it looked like the only rentals page with a page
header sitting above everything.

Also adopts .ia-page-head-left, which the other four use and this one
didn't, so the head lays out identically.
Run from repo root: python3 apply-offers-header-consistency.py
"""
import sys

VIEW = 'resources/views/tenant/rentals/extension-activity.blade.php'

s = open(VIEW).read()

if 'MARKER-RENTAL-EXT-HEADORDER' in s:
    print("SKIP (already applied)"); sys.exit(0)

OLD = """<div class="ia-page-head">
  <div>
    <h1 class="ia-page-title">Last-minute offers</h1>
    <p class="ia-page-subtitle">Auto-extension activity · last 30 days.</p>
  </div>
  <a href="{{ route('tenant.rentals.extension.activity', ['filter' => $filter, 'export' => 'csv']) }}" class="ia-btn">Export CSV</a>
</div>

@include('layouts.tenant._rental-nav', ['active' => 'offers'])"""

NEW = """{{-- MARKER-RENTAL-EXT-HEADORDER — nav first, then the title block, which
     is what Desk / Fleet / Availability / Settings all do. Reversed, this
     page looked like the only one in rentals with a page header. --}}
@include('layouts.tenant._rental-nav', ['active' => 'offers'])

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Last-minute offers</h1>
    <p class="ia-page-subtitle">Auto-extension activity · last 30 days.</p>
  </div>
  <a href="{{ route('tenant.rentals.extension.activity', ['filter' => $filter, 'export' => 'csv']) }}" class="ia-btn">Export CSV</a>
</div>"""

if OLD not in s:
    print("FAIL: header block anchor not found"); sys.exit(1)

open(VIEW, 'w').write(s.replace(OLD, NEW, 1))
print("OK: nav above the title block")
print("OK: ia-page-head-left matches the other tabs")
print("Done. No migration needed. view:clear after deploy.")
