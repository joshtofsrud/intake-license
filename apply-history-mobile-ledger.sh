#!/bin/bash
# apply-history-mobile-ledger.sh
#
# MARKER-HIST-LEDGER — replaces the labelled mobile cards from
# MARKER-HIST-MOBILE with style B from the mockup Josh approved.
#
# What was wrong with what I shipped: I labelled every field, so eight
# labelled rows meant one transaction filled the screen — worse than the
# squeezed table it replaced. Style B drops the labels entirely and uses
# hierarchy instead:
#
#     │ John Hays                            $48.00
#     │ S-20260813-005                       Aug 13
#     │ 1 item · Main                          Josh
#
# with a colored stripe down the left edge carrying the status. Customer
# leads, money is right-aligned in tabular figures, everything else is a
# quiet second and third line.
#
# ONE HONEST DIFFERENCE FROM THE MOCKUP: the mockup showed two lines; this
# is three. In the mockup I was free to merge sale number, item count and
# location into one line, but on the real page those are three separate
# <td>s and merging them would mean restructuring the table the desktop
# view and the sort JS depend on. Three lines still fits ~6 per screen
# against the ~2 you have now.
#
# ALSO HIDDEN ON MOBILE (visible as always on desktop): the status chip
# (the stripe carries it), the Tx group column, and the customer's email
# sub-line — long, and rarely what you are scanning a list for.
#
# Desktop is untouched: same markup, same data attributes, same client-side
# filter/search/sort operating on the same rows.
set -e

MARKER="MARKER-HIST-LEDGER"
H="resources/views/tenant/register/history.blade.php"

[ -f "$H" ] || { echo "ERROR: missing $H — run from the repo root"; exit 1; }
grep -q "MARKER-HIST-MOBILE" "$H" || { echo "ERROR: requires apply-history-mobile.sh"; exit 1; }
if grep -q "$MARKER" "$H" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

python3 - <<'PY'
import io
p = 'resources/views/tenant/register/history.blade.php'
src = io.open(p, encoding='utf-8').read()

old = """    /* table -> cards. Desktop markup is unchanged; only the box model is. */
    .h-table-wrap{overflow:visible}
    .h-table, .h-table tbody, .h-table tr, .h-table td{display:block;width:auto}
    .h-table thead{display:none}
    .h-table tr{
      border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);
      padding:12px 14px;margin-bottom:10px;background:var(--ia-surface)
    }
    .h-table td{
      display:flex;justify-content:space-between;align-items:baseline;gap:14px;
      padding:3px 0;border:0;text-align:left;font-size:13px
    }
    .h-table td::before{
      content:attr(data-label);flex:0 0 auto;font-size:10.5px;font-weight:600;
      letter-spacing:.05em;text-transform:uppercase;color:var(--ia-text-dim)
    }
    /* the identifying pair reads as the card's heading */
    .h-table td[data-label="Sale #"]{padding-bottom:6px;font-size:15px;font-weight:600}
    .h-table td[data-label="Total"]{font-size:15px;font-weight:600}
    .h-table td .h-meta{text-align:right}
    .h-table td.h-empty-search{display:block;text-align:center}
    .h-table td.h-empty-search::before{content:none}
  }"""
assert src.count(old) == 1, 'card css block not found'

new = """    /* MARKER-HIST-LEDGER — table -> ledger rows. No labels: a 3x2 grid over
       the same <td>s, with status carried by a stripe on the row. Desktop
       markup, data attributes and the sort JS are all untouched. */
    .h-table-wrap{overflow:visible}
    .h-table, .h-table tbody{display:block;width:auto}
    .h-table thead{display:none}

    .h-table tr{
      display:grid;grid-template-columns:1fr auto;
      column-gap:12px;row-gap:3px;align-items:baseline;
      padding:11px 2px 11px 11px;
      border-bottom:.5px solid var(--ia-border);
      border-left:3px solid rgba(255,255,255,.10)
    }
    /* status, as a stripe — same palette as the chips it replaces */
    .h-table tr[data-status="paid"]{border-left-color:#78c878}
    .h-table tr[data-status="partial"],
    .h-table tr[data-status="unpaid"]{border-left-color:#FFB450}
    .h-table tr[data-status="refunded"]{border-left-color:#F09595}
    .h-table tr[data-status="quote"]{border-left-color:var(--ia-accent)}

    .h-table td{display:block;padding:0;border:0;text-align:left;min-width:0}
    .h-table td::before{content:none}          /* labels off */

    .h-table td[data-label="Customer"]{
      grid-column:1;grid-row:1;font-size:13.5px;font-weight:600;
      overflow:hidden;text-overflow:ellipsis;white-space:nowrap
    }
    .h-table td[data-label="Total"]{
      grid-column:2;grid-row:1;text-align:right;
      font-size:14.5px;font-weight:650;letter-spacing:-.01em
    }
    .h-table td[data-label="Sale #"]{
      grid-column:1;grid-row:2;font-size:11.5px;color:var(--ia-text-dim);
      overflow:hidden;text-overflow:ellipsis;white-space:nowrap
    }
    .h-table td[data-label="Date"]{
      grid-column:2;grid-row:2;text-align:right;font-size:11px;
      color:var(--ia-text-dim)
    }
    .h-table td[data-label="Items"]{
      grid-column:1;grid-row:3;font-size:11.5px;color:var(--ia-text-dim);
      overflow:hidden;text-overflow:ellipsis;white-space:nowrap
    }
    .h-table td[data-label="Staff"]{
      grid-column:2;grid-row:3;text-align:right;font-size:11px;
      color:var(--ia-text-dim)
    }
    /* location sits inline after the item count rather than on its own line */
    .h-table td[data-label="Items"] .h-meta{display:inline;font-size:11.5px}
    .h-table td[data-label="Items"] .h-meta::before{content:" \\00b7 "}

    /* carried elsewhere on this row, or too long to scan */
    .h-table td[data-label="Status"],
    .h-table td[data-label="Tx group"],
    .h-table td[data-label="Customer"] .h-meta{display:none}

    .h-table td.h-empty-search{grid-column:1 / -1;text-align:center}
  }"""

src = src.replace(old, new, 1)
io.open(p, 'w', encoding='utf-8').write(src)
print('ok: ledger rows replace labelled cards')
PY

echo ""
echo "== history ledger layout applied =="
echo "Post-deploy: php artisan optimize:clear"
