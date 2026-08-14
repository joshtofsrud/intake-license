#!/bin/bash
# apply-register-picker-align.sh
#
# MARKER-REGPICKER-ALIGN — the register/display picker in the Register tab
# row rendered as an oversized box towering over the tabs.
#
# Cause: it's a .ia-input (padding 8px 12px, width:100%) sitting as a flex
# child of .reg-tabs-bar. Flex children default to align-items:stretch, so
# the select stretched to the full height of the bar and its bottom edge
# fell below the tab underline, next to 13px tab links.
#
# Fix is scoped to the picker only. NOT touching .reg-tabs-bar's alignment:
# .reg-tab-link relies on margin-bottom:-0.5px to sit exactly on the bar's
# bottom border, so centering the whole bar would lift the active tab's
# underline off the border.
#
# The <=760px rule (MARKER-OFFLINE-SYNC stage 3b) puts the picker on its own
# full-width row and is left alone — it already overrides these properties,
# and it is declared after this block so it still wins.
set -e

MARKER="MARKER-REGPICKER-ALIGN"
IDX="resources/views/tenant/register/index.blade.php"

[ -f "$IDX" ] || { echo "ERROR: missing $IDX — run from the repo root"; exit 1; }
if grep -q "$MARKER" "$IDX" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

python3 - <<'PY'
import io
p = 'resources/views/tenant/register/index.blade.php'
src = io.open(p, encoding='utf-8').read()

a = """  /* MARKER-OFFLINE-SYNC stage 3b — mobile: picker on its own full-width row
     instead of floating beside wrapped tabs */"""
assert src.count(a) == 1, 'mobile picker block not found'

src = src.replace(a, """  /* MARKER-REGPICKER-ALIGN — the picker is an .ia-input in a flex row, so it
     stretched to the bar's full height and sat below the tab underline.
     Centre it and size it to the tab links instead. Scoped to the picker:
     .reg-tab-link needs the bar to stay stretch-aligned so its -0.5px bottom
     margin keeps the active underline on the border. */
  .reg-tabs-bar #registerPicker{
    align-self:center;height:30px;padding:0 10px;line-height:1
  }

""" + a, 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: register picker aligned')
PY

echo ""
echo "== register picker alignment applied =="
echo "Post-deploy: php artisan optimize:clear"
