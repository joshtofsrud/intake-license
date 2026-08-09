#!/usr/bin/env bash
set -euo pipefail
# apply-nav-account-link.sh — MARKER-NAV-ACCOUNT
# Puts a way into the customer portal on the public site:
#   - desktop: person icon + "Sign in" (or the customer's first name) in the
#     nav's right cluster, between shop search and the CTA. Styled off the
#     nav's own $linkColor at the same weight/opacity as a nav link, so it
#     never competes with the CTA.
#   - mobile: first row of the menu overlay, with a subline.
#   - header editor gains a "Show account link" toggle (default ON).
# The portal already wears the tenant's chrome (PATCH-581), so the round trip
# stays on-brand.

PUBNAV=resources/views/public/sections/_nav.blade.php
LAYOUT=resources/views/public/layout.blade.php
EDITOR=resources/views/tenant/pages/sections/_nav.blade.php
PBCTRL=app/Http/Controllers/Tenant/PageBuilderController.php

for f in "$PUBNAV" "$LAYOUT" "$EDITOR" "$PBCTRL"; do
  [ -f "$f" ] || { echo "MISSING $f — run from the repo root"; exit 1; }
done

if grep -q "MARKER-NAV-ACCOUNT" "$PUBNAV"; then
  echo "Already applied (MARKER-NAV-ACCOUNT present) — no-op."
  exit 0
fi

# ---------------------------------------------------------------- public nav
python3 - "$PUBNAV" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

def edit(old, new, label):
    global src
    n = src.count(old)
    if n != 1:
        print(f"FAIL {label}: anchor found {n} times"); sys.exit(1)
    src = src.replace(old, new, 1)
    print(f"ok   {label}")

# 1) CSS — sits with the other end-cluster rules, uses the nav's own colors
edit(""".{{ $instId }} .p-nav-end {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-shrink: 0;
}""",
""".{{ $instId }} .p-nav-end {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-shrink: 0;
}

/* MARKER-NAV-ACCOUNT — reads as a nav link, not a second CTA */
.{{ $instId }} .p-nav-account {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 10px;
  font-size: 14px;
  font-weight: 500;
  border-radius: 6px;
  color: {{ $linkColor }};
  opacity: .7;
  text-decoration: none;
  transition: opacity .15s;
  white-space: nowrap;
}
.{{ $instId }} .p-nav-account:hover { opacity: 1; }
.{{ $instId }} .p-nav-account svg { flex: 0 0 auto; }
@media (max-width: 860px) { .{{ $instId }} .p-nav-account { display: none; } }""",
"public nav CSS")

# 2) markup — between search and the CTA
edit("""        @if($showCta)
          <a href="{{ $ctaUrl }}" class="p-nav-cta p-nav-cta--{{ $ctaStyle }}">""",
"""        {{-- MARKER-NAV-ACCOUNT — the only route into the customer portal --}}
        @php
          $navShowAccount = (bool) ($c['show_account'] ?? true);
          $navCustomer    = \\Illuminate\\Support\\Facades\\Auth::guard('customer')->user();
        @endphp
        @if($navShowAccount)
          <a href="{{ $navCustomer ? route('tenant.customer.portal') : route('tenant.customer.login') }}"
             class="p-nav-account">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="5.2" r="3" stroke="currentColor" stroke-width="1.6"/><path d="M2.6 14c.8-2.6 2.9-4 5.4-4s4.6 1.4 5.4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            {{ $navCustomer ? $navCustomer->first_name : 'Sign in' }}
          </a>
        @endif
        @if($showCta)
          <a href="{{ $ctaUrl }}" class="p-nav-cta p-nav-cta--{{ $ctaStyle }}">""",
"public nav markup")

open(path, 'w').write(src)
PY

# ---------------------------------------------------------------- mobile menu
python3 - "$LAYOUT" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """  @foreach($navItems as $item)
    <a href="{{ $item->url }}" onclick="closeMobileNav()">{{ $item->label }}</a>
  @endforeach"""
new = """  {{-- MARKER-NAV-ACCOUNT — first row, so the portal is reachable on a phone --}}
  @php
    $mnNav          = $sections->firstWhere('section_type', 'nav');
    $mnShowAccount  = (bool) (($mnNav?->content['show_account']) ?? true);
    $mnCustomer     = \\Illuminate\\Support\\Facades\\Auth::guard('customer')->user();
  @endphp
  @if($mnShowAccount)
    <a href="{{ $mnCustomer ? route('tenant.customer.portal') : route('tenant.customer.login') }}"
       onclick="closeMobileNav()" class="p-mobile-account">
      <svg width="22" height="22" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="5.2" r="3" stroke="currentColor" stroke-width="1.6"/><path d="M2.6 14c.8-2.6 2.9-4 5.4-4s4.6 1.4 5.4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
      <span>{{ $mnCustomer ? $mnCustomer->first_name : 'Sign in' }}
        <small>{{ $mnCustomer ? 'My account' : 'Bookings, orders, rentals & messages' }}</small></span>
    </a>
  @endif
  @foreach($navItems as $item)
    <a href="{{ $item->url }}" onclick="closeMobileNav()">{{ $item->label }}</a>
  @endforeach"""
n = src.count(old)
if n != 1:
    print(f"FAIL mobile markup: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   mobile menu markup")

old_css = """    .p-mobile-nav.open { display: flex; }"""
new_css = """    .p-mobile-nav.open { display: flex; }
    /* MARKER-NAV-ACCOUNT */
    .p-mobile-account { display: flex; align-items: center; gap: 10px; font-size: 17px; font-weight: 600; }
    .p-mobile-account small { display: block; font-size: 12.5px; font-weight: 400; opacity: .5; margin-top: 1px; }"""
n = src.count(old_css)
if n != 1:
    print(f"FAIL mobile css: anchor found {n} times"); sys.exit(1)
src = src.replace(old_css, new_css, 1)
print("ok   mobile menu CSS")

open(path, 'w').write(src)
PY

# ---------------------------------------------------------------- editor toggle
python3 - "$EDITOR" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """  <div class="pb2-group">
    <div class="pb2-group-title">CTA button</div>"""
new = """  {{-- MARKER-NAV-ACCOUNT --}}
  <div class="pb2-group">
    <div class="pb2-group-title">Customer account</div>

    <label class="pb2-checkbox-row">
      <input type="checkbox" data-field="show_account" value="1" {{ $get('show_account', true) ? 'checked' : '' }}>
      <span>Show account link</span>
    </label>

    <div class="pb2-field-hint" style="margin-top:6px">Lets customers sign in to see their bookings, orders, rentals and messages.</div>
  </div>

  <div class="pb2-group">
    <div class="pb2-group-title">CTA button</div>"""
n = src.count(old)
if n != 1:
    print(f"FAIL editor toggle: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   editor toggle")

open(path, 'w').write(src)
PY

# ---------------------------------------------------------------- default
python3 - "$PBCTRL" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """            // CTA
            'show_cta'        => true,"""
new = """            // Customer account — MARKER-NAV-ACCOUNT
            'show_account'    => true,
            // CTA
            'show_cta'        => true,"""
n = src.count(old)
if n != 1:
    print(f"FAIL nav default: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   nav default show_account")

open(path, 'w').write(src)
PY

php -l "$PBCTRL"

echo ""
echo "SUCCESS — apply-nav-account-link applied."
echo "Deploy's optimize covers the view cache."
