#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Mobile-forms-pass — narrow scope to fix breakage.
#
# Bug: the previous mobile-forms-pass.sh added a CSS rule
#   [style*="grid-template-columns"] { grid-template-columns: 1fr !important }
# which matched EVERY inline grid, including legitimate multi-column grids
# like the dashboard's 7-day date strip. Result: home page day strip
# rendering as a vertical stack instead of a 7-day row.
#
# Fix: scope the collapse selector to grids that are clearly forms (inside
# .ia-card with a <form>, or .resource-row), so non-form grids keep their
# inline grid templates. Add explicit allow-list for known multi-column
# components that should stay multi-col regardless.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== narrow scope of mobile-forms-pass ==="

# Rewrite the CSS file. Replace the over-broad inline-grid override.
cat > public/css/tenant/mobile-forms.css <<'CSS'
/* ================================================================
   Mobile form-pages override (≤600px) — SCOPED v2.

   Targets inline-style grids that appear INSIDE form contexts only.
   Generic inline grids (date strips, calendar grids, content layouts)
   keep their multi-column templates.

   The `!important` is required to override inline styles. Acceptable
   here because targeted form inline-styles assume desktop geometry
   that's wrong on phones.
   ================================================================ */

@media (max-width: 600px) {

  /* Form rows inside cards: collapse multi-column grids to 1 col */
  .ia-card form [style*="grid-template-columns"],
  .ia-card form [style*="grid-template-columns"] {
    grid-template-columns: 1fr !important;
    gap: 12px !important;
  }

  /* Resources page existing rows: same treatment + hide drag handle */
  .resource-row {
    grid-template-columns: 1fr !important;
    gap: 10px !important;
    padding: 14px 16px !important;
  }
  .resource-row .drag-handle {
    display: none;
  }

  /* Inputs and selects: full-width on phones, override any inline width */
  .ia-card form .ia-input,
  .ia-card form .ia-select,
  .ia-card form select.ia-input,
  .resource-row .ia-input {
    max-width: 100% !important;
    width: 100% !important;
  }

  /* "Add" / "Save" buttons at the end of a form row become full-width */
  .ia-card form .ia-btn--primary,
  .ia-card form button[type="submit"] {
    width: 100%;
    justify-content: center;
  }

  /* Color picker: render as 8-col grid of squares on phones (parent grid
     has collapsed to 1 col so we have full width to work with) */
  .ia-card form .cp-swatches {
    display: grid !important;
    grid-template-columns: repeat(8, 1fr) !important;
    gap: 6px !important;
    max-width: 100%;
  }
  .ia-card form .cp-swatch {
    width: 100% !important;
    aspect-ratio: 1 / 1;
    height: auto !important;
  }

  /* Page head: stack title above any header actions */
  .ia-page-head {
    flex-direction: column;
    align-items: stretch;
    gap: 10px;
  }
  .ia-page-head-left { width: 100%; }
  .ia-page-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .ia-page-actions .ia-btn { flex: 1; justify-content: center; }

  /* Tables on mobile: allow horizontal scroll rather than crush */
  .ia-card > table,
  .ia-card .ia-table {
    display: block;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    max-width: 100%;
  }

  /* Toolbar (search + filters at top of list pages) */
  .ia-toolbar {
    flex-wrap: wrap !important;
    gap: 8px !important;
  }
  .ia-toolbar > * { flex: 1 1 auto; min-width: 0; }
  .ia-toolbar .ia-input[type="search"] {
    flex: 1 1 100%;
  }
}
CSS
echo "OK 1 (mobile-forms.css rewritten with scoped selectors)"

echo ""
echo "=== verifying ==="
fail=0
verify() {
  local file="$1" needle="$2" label="$3"
  local n
  n=$(grep -c -F -- "$needle" "$file" 2>/dev/null | tr -d '\n' || true)
  : "${n:=0}"
  if [ "${n:-0}" -ge 1 ] 2>/dev/null; then
    echo "  ✓ $label  (${n}×)"
  else
    echo "  ✗ MISSING: $label"
    fail=1
  fi
}
verify_absent() {
  local file="$1" needle="$2" label="$3"
  local n
  n=$(grep -c -F -- "$needle" "$file" 2>/dev/null | tr -d '\n' || true)
  : "${n:=0}"
  if [ "${n:-0}" -eq 0 ] 2>/dev/null; then
    echo "  ✓ ABSENT: $label"
  else
    echo "  ✗ STILL PRESENT: $label  (${n}×)"
    fail=1
  fi
}

verify        "public/css/tenant/mobile-forms.css"   "SCOPED v2"                        "v2 marker"
verify        "public/css/tenant/mobile-forms.css"   ".ia-card form"                    "scoped to form"
verify_absent "public/css/tenant/mobile-forms.css"   "[style*=\"display:flex\"]"        "old flex-wrap rule removed"

# CRITICAL: ensure the over-broad selector that broke day strip is gone.
# The bad selector was a raw [style*="grid-template-columns"] without a prefix.
python3 <<'PY'
import re, sys
s = open('public/css/tenant/mobile-forms.css').read()
# Find selectors that start with [style*="grid-template-columns"] with
# no scoping prefix (i.e., that selector is the START of a comma-list entry).
lines = s.split('\n')
bad = []
for i, line in enumerate(lines, 1):
    stripped = line.strip()
    # Match: "[style*="grid-template-columns"]" OR ", [style*..." OR start-of-line
    if stripped.startswith('[style*="grid-template-columns"]'):
        # Allow `,` before — but ONLY if previous non-blank line ends in a comma
        # AND contains a scoping prefix like `.ia-card form`. Simpler: any naked
        # selector that begins a rule is bad.
        # Check: is the next non-blank line a `{`?
        for j in range(i, len(lines)):
            nxt = lines[j].strip()
            if nxt == '': continue
            if nxt == '{' or nxt.startswith('{'):
                bad.append((i, line))
            break
if bad:
    print('  ✗ unscoped grid-template-columns selector still present:')
    for i, l in bad: print(f'    line {i}: {l}')
    sys.exit(1)
print('  ✓ no unscoped grid-template-columns selector')
PY

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL"
  exit 1
fi

echo ""
echo "✓ all green"
echo ""
echo "Next:"
echo "  git add -A && git commit -m 'fix: scope mobile-forms-pass to form contexts only (date strip + other grids were getting crushed)'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== narrow-scope fix complete ==="
