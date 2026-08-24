#!/usr/bin/env python3
"""Inventory reports: join the tab bar instead of standing outside it.

Inventory already has a unified tab bar — layouts/tenant/_inventory-tabs
— carrying Items / Uncategorized / Categories / Receiving / Import /
Catalog attention / Connection & sync. I didn't use it: I added a
"Reports" button to the action row and built a page with no tabs at all.
The result was a page that looks like it belongs to a different app, and
no way to reach Reports from any other inventory screen.

Fix: Reports becomes a tab in that partial, and the reports page renders
the partial like every other inventory page does. The action-row button
comes back out — the tab bar is the navigation.
Run from repo root: python3 apply-inventory-reports-tabs.py
"""
import sys

TABS  = 'resources/views/layouts/tenant/_inventory-tabs.blade.php'
VIEW  = 'resources/views/tenant/inventory/reports.blade.php'
INDEX = 'resources/views/tenant/inventory/index.blade.php'

def sub(p, old, new, label):
    s = open(p).read()
    if new == '':
        if old not in s:
            print(f"SKIP (already applied): {label}"); return
    elif new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    open(p, 'w').write(s.replace(old, new, 1))
    print(f"OK: {label}")

# ---------------------------------------------------------------- tab bar
# After Receiving, before the distributor tabs — reports are about your own
# stock, which is the same family as Items/Categories/Receiving.
sub(TABS,
    """  $invTabs[] = ['route' => 'tenant.inventory.receiving.index',  'label' => 'Receiving',         'match' => 'tenant.inventory.receiving'];""",
    """  $invTabs[] = ['route' => 'tenant.inventory.receiving.index',  'label' => 'Receiving',         'match' => 'tenant.inventory.receiving'];
  $invTabs[] = ['route' => 'tenant.inventory.reports',          'label' => 'Reports',           'match' => 'tenant.inventory.reports']; // MARKER-INV-REPORTS-TABS""",
    "tab bar: Reports tab")

# ---------------------------------------------------------------- the page
sub(VIEW,
    """<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Inventory reports</h1>""",
    """{{-- MARKER-INV-REPORTS-TABS — the shared inventory tab bar. The first
     version of this page rendered no tabs at all, so it read as a
     different app and Reports was unreachable from the other screens. --}}
@include('layouts.tenant._inventory-tabs')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Inventory reports</h1>""",
    "reports page: include tab bar")

# ---------------------------------------------------------------- index
# The button was my workaround for not having a tab. The tab replaces it.
sub(INDEX,
    """    {{-- MARKER-INV-REPORTS --}}
    <a href="{{ route('tenant.inventory.reports') }}" class="ia-btn">Reports</a>
""",
    "",
    "index: drop the stopgap button")

print("\\nDone. No migration needed. view:clear after deploy.")
