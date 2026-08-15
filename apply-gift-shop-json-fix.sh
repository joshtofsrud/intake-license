#!/bin/bash
# apply-gift-shop-json-fix.sh
#
# MARKER-GC-JSONFIX — URGENT, customer-facing. The public gift card buy page
# (/gift-cards) cannot render.
#
# _gift_shop.blade.php line ~156 does:
#
#     var CFG = @json([
#       'presets' => ..., 'min' => ..., 'max' => ...,      <- 6 commas
#     ]);
#
# Blade's compileJson does `explode(',', $expression)` and keeps only parts
# 0/1/2 as value/options/depth. Everything after the second comma is thrown
# away, so the compiled PHP is truncated mid-array and the view fatals —
# the same defect that took /admin/pages down, found by that patch's sweep.
#
# WORSE THAN THE ADMIN ONE, for two reasons:
#   - it is a CUSTOMER-facing page, not a staff screen;
#   - the @if($stripePk) wrapper does NOT protect it. Blade compiles the
#     whole template regardless of runtime branches, so the syntax error
#     exists whether or not the shop has Stripe keys.
#
# It has gone unnoticed because the page is gated behind the gift_cards
# add-on, which Ground Control only just enabled, so nobody had loaded it.
#
# MY VERIFICATION MISSED IT, specifically: my node --check pass stubs
# `@json( ... )` out to `({})` before parsing the JS, which is exactly the
# construct that was broken. The check was blind to it by design.
#
# FIX: build the array in a @php block and hand @json a single variable.
set -e

MARKER="MARKER-GC-JSONFIX"
V="resources/views/public/sections/_gift_shop.blade.php"

[ -f "$V" ] || { echo "ERROR: run from the repo root"; exit 1; }
if grep -q "$MARKER" "$V" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

python3 - <<'PY'
import io
p = 'resources/views/public/sections/_gift_shop.blade.php'
src = io.open(p, encoding='utf-8').read()

old = """  // MARKER-GC-SETTINGS -- shop config drives the defaults and the client checks.
  var CFG = @json([
    'presets'         => $gift['presets'],
    'min'             => $gift['min_cents'],
    'max'             => $gift['max_cents'],
    'egift'           => $gift['online_egift'],
    'physical'        => $gift['online_physical'],
    'default_message' => $gift['default_message'],
  ]);"""
assert src.count(old) == 1, 'CFG json block not found'

src = src.replace(old, """  // MARKER-GC-SETTINGS -- shop config drives the defaults and the client checks.
  // MARKER-GC-JSONFIX -- the array is built above and passed as ONE variable:
  // Blade splits a @json argument on commas and keeps only three parts, so an
  // inline array literal here silently truncated and fataled the page.
  var CFG = @json($gcShopCfg);""", 1)

# the @php block goes immediately before the <script> that uses it
a = """@if($stripePk)
<script>
(function () {"""
assert src.count(a) == 1, 'script anchor'
src = src.replace(a, """@if($stripePk)
@php
  // MARKER-GC-JSONFIX
  $gcShopCfg = [
      'presets'         => $gift['presets'],
      'min'             => $gift['min_cents'],
      'max'             => $gift['max_cents'],
      'egift'           => $gift['online_egift'],
      'physical'        => $gift['online_physical'],
      'default_message' => $gift['default_message'],
  ];
@endphp
<script>
(function () {""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: gift shop CFG passes a single variable')
PY

echo ""
echo "-- re-sweep: fatal @json arguments anywhere in the app --"
python3 - <<'SWEEP'
import re, glob

fatal = []
for path in glob.glob('resources/views/**/*.blade.php', recursive=True):
    src = open(path, encoding='utf-8', errors='replace').read()
    for m in re.finditer(r'@json\s*\(', src):
        i = m.end() - 1
        depth = 0
        j = i
        while j < len(src):
            if src[j] == '(':
                depth += 1
            elif src[j] == ')':
                depth -= 1
                if depth == 0:
                    break
            j += 1
        arg = src[i + 1:j]
        if arg.count(',') > 2:
            fatal.append(f'{path}:{src[:i].count(chr(10)) + 1}  ({arg.count(",")} commas)')

if fatal:
    print('  STILL FATAL:')
    for b in fatal:
        print('   ', b)
else:
    print('  clean — no view has a fatal @json argument')
SWEEP

echo ""
echo "== gift shop json fix applied =="
echo "Post-deploy: php artisan optimize:clear (the broken compiled view is cached)"
