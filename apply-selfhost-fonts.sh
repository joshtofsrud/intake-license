#!/bin/bash
# apply-selfhost-fonts.sh
#
# MARKER-SELFHOST-FONTS — phase 1: stop the flash of text re-laying-out on
# the tenant admin, by serving Inter and JetBrains Mono from our own origin.
#
# WHAT CAUSES THE FLASH: Inter is fetched from Google Fonts with
# display=swap and falls back to -apple-system (San Francisco on iOS). The
# two have different metrics, so when Inter finally lands every line
# re-measures — text shifts, headings jump, columns re-wrap. The layout
# preconnects to fonts.googleapis.com but NOT fonts.gstatic.com, where the
# font FILES live, so the browser pays a fresh DNS + TLS handshake to a
# second host before it can even start downloading. On cell data that is
# easily a few hundred ms of visible fallback text.
#
# Self-hosting removes the third-party hop entirely and lets the font be
# preloaded, so it is normally present before first paint — no swap to see.
# It also keeps the admin working if Google is unreachable, and stops
# sending staff IPs to Google on every page load.
#
# ---------------------------------------------------------------------------
# YOU MUST ADD THE FONT FILES FIRST. A patch cannot carry binaries.
# Copy the five .woff2 files into:  public/fonts/
#     inter-latin-400-normal.woff2
#     inter-latin-500-normal.woff2
#     inter-latin-600-normal.woff2
#     jetbrains-mono-latin-400-normal.woff2
#     jetbrains-mono-latin-500-normal.woff2
# Both families are SIL Open Font License, so self-hosting is permitted.
# If the files are missing the page still works — @font-face simply fails
# and the system font is used — but the flash will not be fixed.
# ---------------------------------------------------------------------------
#
# SCOPE, deliberately narrow: the tenant admin layout only
# (resources/views/layouts/tenant/app.blade.php + public/css/tenant/base.css).
# 24 templates across the app reference Google Fonts — auth screens, the
# public storefront, marketing, platform, invoices, error pages. Converting
# all of them at once is a wide blast radius, and storefront pages may use
# tenant-chosen fonts that need separate thought. They keep working exactly
# as they do now; we convert them once this is confirmed.
set -e

MARKER="MARKER-SELFHOST-FONTS"
CSS="public/css/tenant/base.css"
LAYOUT="resources/views/layouts/tenant/app.blade.php"

for f in "$CSS" "$LAYOUT"; do
  [ -f "$f" ] || { echo "ERROR: missing $f — run from the repo root"; exit 1; }
done
if grep -q "$MARKER" "$CSS" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

# ---------------------------------------------------------------
# 1. @font-face declarations at the top of base.css
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'public/css/tenant/base.css'
src = io.open(p, encoding='utf-8').read()

a = """/* --------------------------------------------------------------------------
   CSS custom properties — overridden per theme
   -------------------------------------------------------------------------- */"""
assert src.count(a) == 1, 'base.css header anchor'

face = """/* --------------------------------------------------------------------------
   MARKER-SELFHOST-FONTS — self-hosted webfonts (SIL OFL).
   Served from our own origin so they can be preloaded and are normally
   present before first paint; previously these came from Google, and the
   metric difference against the -apple-system fallback re-laid-out the
   whole page the moment they arrived.

   unicode-range limits these to latin: anything outside it (a customer
   writing in Greek or Cyrillic, say) falls back to the system font instead
   of downloading a face that has no glyph for it.
   -------------------------------------------------------------------------- */
@font-face {
  font-family: 'Inter';
  font-style: normal;
  font-weight: 400;
  font-display: swap;
  src: url('/fonts/inter-latin-400-normal.woff2') format('woff2');
  unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA,
    U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+2074, U+20AC, U+2122, U+2191,
    U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
}
@font-face {
  font-family: 'Inter';
  font-style: normal;
  font-weight: 500;
  font-display: swap;
  src: url('/fonts/inter-latin-500-normal.woff2') format('woff2');
  unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA,
    U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+2074, U+20AC, U+2122, U+2191,
    U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
}
@font-face {
  font-family: 'Inter';
  font-style: normal;
  font-weight: 600;
  font-display: swap;
  src: url('/fonts/inter-latin-600-normal.woff2') format('woff2');
  unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA,
    U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+2074, U+20AC, U+2122, U+2191,
    U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
}
@font-face {
  font-family: 'JetBrains Mono';
  font-style: normal;
  font-weight: 400;
  font-display: swap;
  src: url('/fonts/jetbrains-mono-latin-400-normal.woff2') format('woff2');
  unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA,
    U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+2074, U+20AC, U+2122, U+2191,
    U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
}
@font-face {
  font-family: 'JetBrains Mono';
  font-style: normal;
  font-weight: 500;
  font-display: swap;
  src: url('/fonts/jetbrains-mono-latin-500-normal.woff2') format('woff2');
  unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA,
    U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+2074, U+20AC, U+2122, U+2191,
    U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
}

""" + a

src = src.replace(a, face, 1)
io.open(p, 'w', encoding='utf-8').write(src)
print('ok: @font-face added to base.css')
PY

# ---------------------------------------------------------------
# 2. Layout: preload our own files, drop the Google links
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'resources/views/layouts/tenant/app.blade.php'
src = io.open(p, encoding='utf-8').read()

a = """  {{-- Fonts --}}
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">"""
assert src.count(a) == 1, 'google fonts block'

src = src.replace(a, """  {{-- Fonts — MARKER-SELFHOST-FONTS. Self-hosted from public/fonts; the
       @font-face rules live in base.css. Preloading the two weights that
       carry almost all of the UI means they are normally decoded before
       first paint, so there is no metric swap to watch. crossorigin is
       required on font preloads even same-origin, or the browser fetches
       the file twice. 600 and the mono faces are not preloaded — they are
       used sparsely enough that a late swap is not noticeable. --}}
  <link rel="preload" as="font" type="font/woff2" crossorigin
        href="{{ asset('fonts/inter-latin-400-normal.woff2') }}">
  <link rel="preload" as="font" type="font/woff2" crossorigin
        href="{{ asset('fonts/inter-latin-500-normal.woff2') }}">""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: layout preloads own fonts, Google links removed')
PY

# ---------------------------------------------------------------
# 3. Tell the truth about whether the files are actually there
# ---------------------------------------------------------------
echo ""
missing=0
for f in inter-latin-400-normal.woff2 inter-latin-500-normal.woff2 \
         inter-latin-600-normal.woff2 jetbrains-mono-latin-400-normal.woff2 \
         jetbrains-mono-latin-500-normal.woff2; do
  if [ -f "public/fonts/$f" ]; then
    echo "  found  public/fonts/$f"
  else
    echo "  MISSING public/fonts/$f"
    missing=1
  fi
done

if [ "$missing" -eq 1 ]; then
  echo ""
  echo "!! Font files are not in place yet. The admin will still load — it"
  echo "!! falls back to the system font — but the flash will NOT be fixed"
  echo "!! and text will look different until you add them. Copy the five"
  echo "!! .woff2 files into public/fonts/ before deploying."
fi

echo ""
echo "== self-hosted fonts applied (tenant admin only) =="
echo "Post-deploy: php artisan optimize:clear"
