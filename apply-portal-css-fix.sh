#!/usr/bin/env bash
set -euo pipefail
# apply-portal-css-fix.sh — MARKER-PORTAL-CSS
# Two live bugs on the deployed portal:
#
# 1) UNSTYLED LISTS. Eight classes the v2 views rely on — ac-list, ac-list-row,
#    ac-list-name, ac-list-meta, ac-list-right, ac-pill (+ status variants),
#    ac-empty, ac-section-title — were defined ONLY in the old
#    public/account/portal.blade.php, which v2 orphaned. So Home / Bookings /
#    Orders / Rentals rendered as a wall of stacked text while Messages and
#    Account (which lean on _shell's classes) looked right. Definitions move
#    into _portal-css so every section shares one source.
#
# 2) DOUBLE HEADER. _shell renders the builder nav via _chrome-inline AND its
#    own .ac-top bar underneath it, so the shop's logo and name appeared
#    twice. The builder nav now carries the account link (MARKER-NAV-ACCOUNT),
#    so .ac-top only renders when there is no builder nav — sign-in and reset
#    pages on a tenant with no site keep their header.

CSSV=resources/views/public/account/portal/_portal-css.blade.php
SHELL=resources/views/public/account/_shell.blade.php

for f in "$CSSV" "$SHELL"; do
  [ -f "$f" ] || { echo "MISSING $f — deploy apply-customer-portal-v2.sh first"; exit 1; }
done

if grep -q "MARKER-PORTAL-CSS" "$CSSV"; then
  echo "Already applied (MARKER-PORTAL-CSS present) — no-op."
  exit 0
fi

# ---------------------------------------------------------------- list CSS
python3 - "$CSSV" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """<style>
.ac-nav{"""
new = """<style>
/* MARKER-PORTAL-CSS — list primitives. These lived in the old single-page
   portal.blade.php, which v2 orphaned; every section uses them, so they
   belong here. Values carried over unchanged from that view. */
.ac-section-title{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;opacity:.4;margin-bottom:10px}
.ac-list{border:1px solid var(--p-border);border-radius:var(--p-r-lg);overflow:hidden;margin-bottom:20px}
.ac-list-row{padding:12px 15px;border-bottom:1px solid var(--p-border);display:flex;align-items:center;justify-content:space-between;font-size:14px;gap:12px;color:inherit;text-decoration:none}
.ac-list-row:last-child{border-bottom:none}
a.ac-list-row:hover{background:var(--p-surface)}
.ac-list-name{font-weight:500}
.ac-list-meta{font-size:12px;opacity:.5;margin-top:2px}
.ac-list-right{text-align:right;flex-shrink:0;margin-left:12px}
.ac-empty{padding:28px;text-align:center;font-size:14px;opacity:.35}
.ac-pill{display:inline-flex;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:500;white-space:nowrap}
.ac-pill--registered,.ac-pill--checked_in,.ac-pill--confirmed,.ac-pill--active,.ac-pill--paid{background:#EAF3DE;color:#3B6D11}
.ac-pill--pending{background:#E6F1FB;color:#185FA5}
.ac-pill--waitlisted,.ac-pill--in_progress,.ac-pill--due{background:#FAEEDA;color:#633806}
.ac-pill--no_show,.ac-pill--cancelled,.ac-pill--completed,.ac-pill--returned{background:var(--p-surface);color:rgba(0,0,0,.4)}
.ac-pill--refunded{background:#FCEBEB;color:#A32D2D}

.ac-nav{"""
n = src.count(old)
if n != 1:
    print(f"FAIL list CSS: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   list CSS restored")

open(path, 'w').write(src)
PY

# ---------------------------------------------------------------- one header
python3 - "$SHELL" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """<div class="ac-top">
  <a href="{{ route('tenant.home') }}" class="ac-logo">"""
new = """{{-- MARKER-PORTAL-CSS — the builder nav above already shows the logo and the
     signed-in customer, so this bar would be a second copy of both. It still
     renders for a tenant with no site chrome. --}}
@php $acHasChrome = (bool) \\App\\Services\\Tenant\\SiteChromeService::parts($currentTenant)['nav']; @endphp
@unless($acHasChrome)
<div class="ac-top">
  <a href="{{ route('tenant.home') }}" class="ac-logo">"""
n = src.count(old)
if n != 1:
    print(f"FAIL header open: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   header guarded")

old_close = """      <a href="{{ route('tenant.customer.register') }}" class="ac-top-link">Create account</a>
    @endif
  </div>
</div>"""
new_close = """      <a href="{{ route('tenant.customer.register') }}" class="ac-top-link">Create account</a>
    @endif
  </div>
</div>
@endunless"""
n = src.count(old_close)
if n != 1:
    print(f"FAIL header close: anchor found {n} times"); sys.exit(1)
src = src.replace(old_close, new_close, 1)
print("ok   header close")

open(path, 'w').write(src)
PY

echo ""
echo "SUCCESS — apply-portal-css-fix applied."
echo "Deploy's optimize covers the view cache."
