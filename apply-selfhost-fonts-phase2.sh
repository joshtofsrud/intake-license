#!/bin/bash
# apply-selfhost-fonts-phase2.sh
#
# MARKER-SELFHOST-FONTS-2 — phase 2: every remaining template that asks
# Google for a FIXED font now uses our own files.
#
# STRUCTURE CHANGE: phase 1 put @font-face inside base.css, which only the
# tenant admin loads. Auth screens, marketing, errors, invoices and the
# platform pages have their own <head> and never load base.css, so the
# declarations move to a standalone public/css/fonts.css that every
# template links. base.css keeps working — it just no longer owns the
# font declarations, and nothing declares them twice.
#
# WEIGHTS: these pages ask for more than the admin does — 300 on the
# investor page, 700 and 800 on marketing, errors and onboarding. fonts.css
# declares Inter 300/400/500/600/700/800 plus JetBrains Mono 400/500.
# Declaring a face costs nothing; a browser downloads only the weights a
# page actually uses.
#
# ---------------------------------------------------------------------------
# THREE MORE FONT FILES ARE NEEDED, alongside the five from phase 1:
#     inter-latin-300-normal.woff2
#     inter-latin-700-normal.woff2
#     inter-latin-800-normal.woff2
# into public/fonts/. The script reports exactly which are missing.
# ---------------------------------------------------------------------------
#
# NOT CONVERTED, on purpose — four storefront templates build the font name
# from the shop's own settings ($currentTenant->font_heading / font_body,
# $fontFamilies):
#     public/layout.blade.php, public/_booking-shell.blade.php,
#     public/account/_shell.blade.php, public/waitlist/_shell.blade.php
# A shop can pick any Google family there, so self-hosting would mean
# shipping fonts we do not have. They keep using Google — but they get the
# fonts.gstatic.com preconnect they were missing, which is the same round
# trip that made the admin flash so visible.
set -e

MARKER="MARKER-SELFHOST-FONTS-2"
FONTSCSS="public/css/fonts.css"

[ -f "public/css/tenant/base.css" ] || { echo "ERROR: run from the repo root"; exit 1; }
grep -q "MARKER-SELFHOST-FONTS" public/css/tenant/base.css || { echo "ERROR: requires apply-selfhost-fonts.sh (phase 1)"; exit 1; }
if [ -f "$FONTSCSS" ] && grep -q "$MARKER" "$FONTSCSS" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

# ---------------------------------------------------------------
# 1. Shared stylesheet with every weight the app uses
# ---------------------------------------------------------------
python3 - <<'PY'
import io

RANGE = ("U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, "
         "U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+2074, U+20AC, "
         "U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD")

faces = []
for w in (300, 400, 500, 600, 700, 800):
    faces.append(f"""@font-face {{
  font-family: 'Inter';
  font-style: normal;
  font-weight: {w};
  font-display: swap;
  src: url('/fonts/inter-latin-{w}-normal.woff2') format('woff2');
  unicode-range: {RANGE};
}}""")
for w in (400, 500):
    faces.append(f"""@font-face {{
  font-family: 'JetBrains Mono';
  font-style: normal;
  font-weight: {w};
  font-display: swap;
  src: url('/fonts/jetbrains-mono-latin-{w}-normal.woff2') format('woff2');
  unicode-range: {RANGE};
}}""")

io.open('public/css/fonts.css', 'w', encoding='utf-8').write(
"""/* ==========================================================================
   MARKER-SELFHOST-FONTS-2 — self-hosted webfonts, shared by every template.

   Phase 1 put these in base.css, which only the tenant admin loads; the auth
   screens, marketing, errors, invoices and platform pages have their own
   <head> and never see it. Living here means one declaration set for the
   whole app.

   Both families are SIL Open Font License. unicode-range keeps them to
   latin — text outside that range falls back to the system font rather than
   downloading a face with no glyph for it.

   Declaring a weight costs nothing: a browser downloads only what a page
   actually renders.
   ========================================================================== */

""" + "\n\n".join(faces) + "\n")
print('ok: public/css/fonts.css written (8 faces)')
PY

# ---------------------------------------------------------------
# 2. base.css hands its declarations over
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'public/css/tenant/base.css'
src = io.open(p, encoding='utf-8').read()

start = src.index("/* --------------------------------------------------------------------------\n   MARKER-SELFHOST-FONTS — self-hosted webfonts (SIL OFL).")
end = src.index(":root {", start)

src = src[:start] + """/* --------------------------------------------------------------------------
   MARKER-SELFHOST-FONTS-2 — the @font-face rules that were here moved to
   public/css/fonts.css so every template can share one declaration set.
   The tenant layout links it just before this file.
   -------------------------------------------------------------------------- */

""" + src[end:]

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: base.css font declarations moved out')
PY

# ---------------------------------------------------------------
# 3. Every template that asks for a FIXED family
# ---------------------------------------------------------------
python3 - <<'PY'
import io, re

FIXED = [
  'layouts/tenant/app.blade.php',
  'tenant/auth/login.blade.php', 'tenant/auth/forgot.blade.php',
  'tenant/auth/reset.blade.php', 'tenant/auth/setup.blade.php',
  'tenant/auth/switch.blade.php', 'tenant/auth/select-location.blade.php',
  'tenant/invoices/web-branded.blade.php',
  'tenant/register/checkout-success.blade.php',
  'tenant/register/checkout-cancel.blade.php',
  'tenant/onboarding/_layout.blade.php',
  'tenant/pages/edit.blade.php',
  'layouts/admin/page-editor.blade.php',
  'platform/signup.blade.php', 'platform/login.blade.php',
  'public/confirm.blade.php',
  'invest/page.blade.php',
  'errors/_shell.blade.php',
  'marketing/layout.blade.php', 'marketing/page.blade.php',
]

# tenant-chosen fonts: cannot self-host a family we do not ship
DYNAMIC = [
  'public/layout.blade.php',
  'public/_booking-shell.blade.php',
  'public/account/_shell.blade.php',
  'public/waitlist/_shell.blade.php',
]

LINK = "<link rel=\"stylesheet\" href=\"{{ asset('css/fonts.css') }}\">{{-- MARKER-SELFHOST-FONTS-2 --}}"

goog = re.compile(r'^[ \t]*<link[^>]*fonts\.(?:googleapis|gstatic)\.com[^>]*>[ \t]*\n', re.M)

converted = skipped = 0
for rel in FIXED:
    p = 'resources/views/' + rel
    try:
        src = io.open(p, encoding='utf-8').read()
    except FileNotFoundError:
        print('  ?  missing, skipped:', rel); continue

    hits = goog.findall(src)
    if not hits:
        # phase 1 already cleared the tenant layout
        skipped += 1
        if 'fonts.css' not in src and rel == 'layouts/tenant/app.blade.php':
            anchor = "  {{-- Base + theme CSS --}}"
            assert src.count(anchor) == 1, 'layout css anchor'
            src = src.replace(anchor, "  " + LINK + "\n\n" + anchor, 1)
            io.open(p, 'w', encoding='utf-8').write(src)
            print('  +  linked fonts.css:', rel)
        continue

    indent = re.match(r'[ \t]*', hits[0]).group(0)
    src = goog.sub('', src, count=len(hits))
    # put our link where the first Google link used to be
    m = re.search(r'^[ \t]*<link[^>]*rel=["\']stylesheet["\'][^>]*>|^[ \t]*<style', src, re.M)
    if m:
        src = src[:m.start()] + indent + LINK + "\n" + src[m.start():]
    else:
        src = src.replace('</head>', '  ' + LINK + '\n</head>', 1)
    io.open(p, 'w', encoding='utf-8').write(src)
    converted += 1
    print('  ->', rel, f'({len(hits)} google link(s) removed)')

print(f'ok: {converted} templates converted, {skipped} already clean')

# --- storefront: keep Google, but stop paying for the missing preconnect
fixed_pre = 0
for rel in DYNAMIC:
    p = 'resources/views/' + rel
    src = io.open(p, encoding='utf-8').read()
    if 'fonts.gstatic.com' in src:
        continue
    m = re.search(r'^([ \t]*)<link rel="preconnect" href="https://fonts\.googleapis\.com">[ \t]*\n', src, re.M)
    if not m:
        print('  !  no preconnect to pair with, left alone:', rel); continue
    ind = m.group(1)
    src = src[:m.end()] + ind + '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' \
          + '{{-- MARKER-SELFHOST-FONTS-2 — the font FILES come from gstatic;'  \
          + ' without this the browser pays a second DNS+TLS handshake --}}\n' + src[m.end():]
    io.open(p, 'w', encoding='utf-8').write(src)
    fixed_pre += 1
    print('  ~  gstatic preconnect added (still Google):', rel)
print(f'ok: {fixed_pre} storefront templates preconnected')
PY

# ---------------------------------------------------------------
# 4. Font file inventory
# ---------------------------------------------------------------
echo ""
missing=0
for f in inter-latin-300-normal.woff2 inter-latin-400-normal.woff2 \
         inter-latin-500-normal.woff2 inter-latin-600-normal.woff2 \
         inter-latin-700-normal.woff2 inter-latin-800-normal.woff2 \
         jetbrains-mono-latin-400-normal.woff2 jetbrains-mono-latin-500-normal.woff2; do
  if [ -f "public/fonts/$f" ]; then echo "  found  $f"; else echo "  MISSING $f"; missing=1; fi
done
[ "$missing" -eq 1 ] && { echo ""; echo "!! Add the missing .woff2 files to public/fonts/ before deploying."; }

echo ""
echo "-- any fixed-family Google requests left? --"
grep -rl "fonts.googleapis" resources/views/ --include=*.blade.php 2>/dev/null \
  | grep -v -E "public/(layout|_booking-shell|account/_shell|waitlist/_shell)" || echo "   none — only the tenant-font storefront templates remain"

echo ""
echo "== phase 2 applied =="
echo "Post-deploy: php artisan optimize:clear"
