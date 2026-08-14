#!/bin/bash
# apply-history-mobile-css-order.sh
#
# MARKER-HIST-CSSORDER — the ledger layout rendered wrong: rows ~370px tall,
# fat padding, a horizontal rule under every cell. Not a layout bug — a
# cascade bug, mine.
#
# I put the @media block near the top of the stylesheet (right after
# .h-count), but the base table rules are declared ~120 lines LOWER:
#
#     .h-table tbody td { padding:12px 14px; border-bottom:.5px solid ... }
#
# Media queries carry NO specificity of their own. `.h-table td` inside a
# media query and `.h-table tbody td` outside it are (0,0,2,1) vs (0,0,2,2)
# — the base rule wins on specificity AND on source order. So the padding
# and cell borders I "removed" were never removed at all.
#
# Fix, both halves — either alone would still lose:
#   1. the whole mobile block moves to the END of the <style>, after every
#      base rule it needs to override;
#   2. cell selectors become `.h-table tbody td` to match the specificity of
#      the rules they are overriding.
#
# No layout values change. This is purely about the rules taking effect.
set -e

MARKER="MARKER-HIST-CSSORDER"
H="resources/views/tenant/register/history.blade.php"

[ -f "$H" ] || { echo "ERROR: missing $H — run from the repo root"; exit 1; }
grep -q "MARKER-HIST-LEDGER" "$H" || { echo "ERROR: requires apply-history-mobile-ledger.sh"; exit 1; }
if grep -q "$MARKER" "$H" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

python3 - <<'PY'
import io, re
p = 'resources/views/tenant/register/history.blade.php'
src = io.open(p, encoding='utf-8').read()

# ---------------------------------------------------------------
# 1. Lift the whole MARKER-HIST-MOBILE block out of its current spot
# ---------------------------------------------------------------
start_marker = "  /* MARKER-HIST-MOBILE ------------------------------------------------ */"
assert src.count(start_marker) == 1, 'mobile block start'
start = src.index(start_marker)

# the block ends at the closing brace of its @media, immediately before the
# next top-level rule. Find the media query and walk its braces.
media_at = src.index('@media (max-width: 760px){', start)
i = src.index('{', media_at)
depth = 0
while True:
    ch = src[i]
    if ch == '{':
        depth += 1
    elif ch == '}':
        depth -= 1
        if depth == 0:
            break
    i += 1
end = i + 1

block = src[start:end]
src = src[:start] + src[end:]

# ---------------------------------------------------------------
# 2. Match the base rules' specificity: td -> tbody td
# ---------------------------------------------------------------
block = block.replace('.h-table td', '.h-table tbody td')
block = block.replace('.h-table tr{', '.h-table tbody tr{')
block = block.replace('.h-table tr[data-status', '.h-table tbody tr[data-status')
block = block.replace('.h-table, .h-table tbody{', '.h-table, .h-table tbody{')

block = block.replace(
    "  /* MARKER-HIST-MOBILE ------------------------------------------------ */",
    "  /* MARKER-HIST-MOBILE ------------------------------------------------ */\n"
    "  /* MARKER-HIST-CSSORDER — this block MUST stay last in the stylesheet:\n"
    "     media queries add no specificity, so it has to out-order the base\n"
    "     .h-table tbody td rules above, and match their specificity. */",
    1
)

# the base rule kills the last row's border; the ledger wants it back
block = block.replace(
    "    .h-table tbody tr{\n",
    "    .h-table tbody tr:last-child td{border-bottom:0}\n    .h-table tbody tr{\n",
    1
)

# ---------------------------------------------------------------
# 3. Drop it back in immediately before </style>
# ---------------------------------------------------------------
close = src.rindex('</style>')
src = src[:close] + block.rstrip() + "\n" + src[close:]

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: mobile block moved last, selectors matched to base specificity')
PY

echo ""
echo "-- order check: the mobile block must come AFTER the BASE td rule --"
echo "   (first match only: the block contains its own .h-table tbody td rules)"
awk '/\.h-table tbody td\{/ && !base {base=NR} /MARKER-HIST-MOBILE ---/ && !mob {mob=NR} END{print "   base:", base, "| mobile block:", mob, (mob>base ? "OK" : "STILL WRONG")}' \
  resources/views/tenant/register/history.blade.php

echo ""
echo "== history css order fixed =="
echo "Post-deploy: php artisan optimize:clear"
