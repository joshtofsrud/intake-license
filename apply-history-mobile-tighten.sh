#!/bin/bash
# apply-history-mobile-tighten.sh
#
# MARKER-HIST-TIGHTEN — the ledger layout renders correctly now, Josh just
# wants more on screen. Three lines becomes two, ~6 rows becomes ~9.
#
# The third line existed only because sale number, item count and staff were
# three separate <td>s and a 2-column grid had nowhere to put them. Going to
# THREE columns fixes that without touching markup:
#
#     col1          col2              col3
#     Dudley Bowers ...............   $654.00     <- row 1 (customer spans 1-2)
#     DP-20260808-001  · 1 item       2026-08-08  <- row 2
#
# Sale number and items now share row 2 instead of stacking, and vertical
# padding drops from 11px to 9px with a tighter row gap.
#
# ONE THING LOST, deliberately: the staff name is hidden on mobile. It was
# the least-scanned field of the eight and it is what the third line was
# mostly carrying. Still on desktop, still on the sale itself. If you would
# rather keep staff and lose the item count, that is a one-line swap.
set -e

MARKER="MARKER-HIST-TIGHTEN"
H="resources/views/tenant/register/history.blade.php"

[ -f "$H" ] || { echo "ERROR: missing $H — run from the repo root"; exit 1; }
grep -q "MARKER-HIST-CSSORDER" "$H" || { echo "ERROR: requires apply-history-mobile-css-order.sh"; exit 1; }
if grep -q "$MARKER" "$H" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

python3 - <<'PY'
import io
p = 'resources/views/tenant/register/history.blade.php'
src = io.open(p, encoding='utf-8').read()

# --- row: three columns, tighter padding --------------------------------
a = """    .h-table tbody tr{
      display:grid;grid-template-columns:1fr auto;
      column-gap:12px;row-gap:3px;align-items:baseline;
      padding:11px 2px 11px 11px;"""
assert src.count(a) == 1, 'row rule'
src = src.replace(a, """    .h-table tbody tr{
      /* MARKER-HIST-TIGHTEN — 3 columns so sale # and items share row 2
         instead of stacking into a third line. */
      display:grid;grid-template-columns:auto 1fr auto;
      column-gap:8px;row-gap:2px;align-items:baseline;
      padding:9px 2px 9px 11px;""", 1)

# --- customer spans the two left columns --------------------------------
b = """    .h-table tbody td[data-label="Customer"]{
      grid-column:1;grid-row:1;font-size:13.5px;font-weight:600;"""
assert src.count(b) == 1, 'customer rule'
src = src.replace(b, """    .h-table tbody td[data-label="Customer"]{
      grid-column:1 / 3;grid-row:1;font-size:13.5px;font-weight:600;""", 1)

c = """    .h-table tbody td[data-label="Total"]{
      grid-column:2;grid-row:1;text-align:right;"""
assert src.count(c) == 1, 'total rule'
src = src.replace(c, """    .h-table tbody td[data-label="Total"]{
      grid-column:3;grid-row:1;text-align:right;""", 1)

# --- row 2: sale # | items | date ---------------------------------------
d = """    .h-table tbody td[data-label="Date"]{
      grid-column:2;grid-row:2;text-align:right;font-size:11px;
      color:var(--ia-text-dim)
    }"""
assert src.count(d) == 1, 'date rule'
src = src.replace(d, """    .h-table tbody td[data-label="Date"]{
      grid-column:3;grid-row:2;text-align:right;font-size:11px;
      color:var(--ia-text-dim)
    }""", 1)

e = """    .h-table tbody td[data-label="Items"]{
      grid-column:1;grid-row:3;font-size:11.5px;color:var(--ia-text-dim);"""
assert src.count(e) == 1, 'items rule'
src = src.replace(e, """    .h-table tbody td[data-label="Items"]{
      grid-column:2;grid-row:2;font-size:11.5px;color:var(--ia-text-dim);""", 1)

# --- staff joins the hidden set -----------------------------------------
f = """    .h-table tbody td[data-label="Staff"]{
      grid-column:2;grid-row:3;text-align:right;font-size:11px;
      color:var(--ia-text-dim)
    }"""
assert src.count(f) == 1, 'staff rule'
src = src.replace(f, "", 1)

g = """    .h-table tbody td[data-label="Status"],
    .h-table tbody td[data-label="Tx group"],
    .h-table tbody td[data-label="Customer"] .h-meta{display:none}"""
assert src.count(g) == 1, 'hidden set'
src = src.replace(g, """    .h-table tbody td[data-label="Status"],
    .h-table tbody td[data-label="Tx group"],
    .h-table tbody td[data-label="Staff"],
    .h-table tbody td[data-label="Customer"] .h-meta{display:none}""", 1)

# items sits beside the sale number now, so it leads with the separator
h = """    .h-table tbody td[data-label="Items"] .h-meta::before{content:" \\00b7 "}"""
assert src.count(h) == 1, 'items separator'
src = src.replace(h, """    .h-table tbody td[data-label="Items"] .h-meta::before{content:" \\00b7 "}
    /* items now sits beside the sale number, so it needs its own leading dot
       (the blanket td::before above turns labels off). */
    .h-table tbody td[data-label="Items"]::before{content:"\\00b7 ";color:var(--ia-text-dim)}""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: two-line ledger rows')
PY

echo ""
echo "== history rows tightened =="
echo "Post-deploy: php artisan optimize:clear"
