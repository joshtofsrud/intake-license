#!/bin/bash
# apply-inbox-mobile-revert-layout.sh
#
# MARKER-INBOX-MOBILE-2 — back out the LAYOUT half of MARKER-INBOX-MOBILE.
#
# What I shipped made the page worse than before: a large dead gap under the
# New conversation button, and the first conversation clipped behind the
# controls. Both come from the layout changes — the sticky strip (which
# repositions the controls out of normal flow) and the .ia-page-head
# overrides. I wrote that CSS against a structure I had read but never seen
# render, which is exactly the mistake to stop repeating.
#
# REMOVED: the .ib-sticky wrapper and its rules, the .ib-scroll
# overflow:visible !important override, and the .ia-page-head /
# .ia-page-subtitle rules. The list container goes back to exactly the markup
# it had before.
#
# KEPT: the Show-more chunking and the 100-row cap notice. Those are
# behavioural, they are what Josh actually complained about ("scrolls
# endlessly"), and nothing in the screenshot suggests they misbehaved. The
# id stays on the container so the script keeps working.
set -e

MARKER="MARKER-INBOX-MOBILE-2"
V="resources/views/tenant/inbox/index.blade.php"

[ -f "$V" ] || { echo "ERROR: missing $V — run from the repo root"; exit 1; }
grep -q "MARKER-INBOX-MOBILE" "$V" || { echo "ERROR: MARKER-INBOX-MOBILE not present — nothing to back out"; exit 1; }
if grep -q "$MARKER" "$V" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

python3 - <<'PY'
import io
p = 'resources/views/tenant/inbox/index.blade.php'
src = io.open(p, encoding='utf-8').read()

# ---------------------------------------------------------------
# 1. Drop the layout rules, keep the show-more / cap-note rules
# ---------------------------------------------------------------
old_css = """
  @media (max-width: 980px) {
    /* the app header is sticky at 52px on <=1023px; sit directly under it */
    .ib-sticky { position:sticky; top:52px; z-index:20;
      background:var(--ia-bg); padding-top:8px; }
    /* the list scroller must not clip the sticky child */
    .ib-scroll { overflow:visible !important; }

    /* header: title and action on one line, subtitle gone */
    .ia-page-head { align-items:center; gap:10px; }
    .ia-page-subtitle { display:none; }
    .ia-page-actions .ia-btn { white-space:nowrap; padding:9px 14px; font-size:13px; }
  }"""
assert src.count(old_css) == 1, 'layout css block'
src = src.replace(old_css, """
  /* MARKER-INBOX-MOBILE-2 — the sticky controls and header overrides that
     lived here are removed: they left a dead gap under the header and clipped
     the first conversation. The list keeps its original layout. */""", 1)

# ---------------------------------------------------------------
# 2. Unwrap the sticky div
# ---------------------------------------------------------------
old_open = """    {{-- MARKER-INBOX-MOBILE — search and pills stay reachable while the
         list scrolls; they used to scroll away with it. --}}
    <div class="ib-sticky">
    {{-- MARKER-INBOX-SEARCH --}}"""
assert src.count(old_open) == 1, 'sticky open'
src = src.replace(old_open, """    {{-- MARKER-INBOX-SEARCH --}}""", 1)

old_close = """    </div>{{-- /ib-sticky MARKER-INBOX-MOBILE --}}
    <div class="ib-scroll" id="ib-scroll" style="overflow-y:auto;flex:1">"""
assert src.count(old_close) == 1, 'sticky close'
src = src.replace(old_close, """    {{-- MARKER-INBOX-MOBILE-2 — original container; the id is retained only
         so the chunking script can find it. --}}
    <div id="ib-scroll" style="overflow-y:auto;flex:1">""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: sticky strip and header overrides removed; chunking kept')
PY

echo ""
echo "-- remaining MARKER-INBOX-MOBILE bits (should be chunking + cap note only) --"
grep -n "ib-more\|ib-capnote\|ib-sticky\|ia-page-subtitle" resources/views/tenant/inbox/index.blade.php | head

echo ""
echo "== inbox layout revert applied =="
echo "Post-deploy: php artisan optimize:clear"
