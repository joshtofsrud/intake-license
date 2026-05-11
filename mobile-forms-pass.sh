#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Form pages — generic mobile CSS pass.
#
# Many tenant admin pages (Resources, Services, Settings, Waitlist, Register)
# use inline `style="display:grid;grid-template-columns:1.2fr 1fr ..."` for
# multi-column form layouts. These were written assuming a wide desktop
# viewport. On phones they collapse to absurd narrow cells, causing the
# Resources color picker to render as a vertical column of swatches and
# generally making forms unusable.
#
# Fix: global override targeting inline-style grids. At ≤600px any inline-
# styled grid with 2+ columns collapses to a single column. Uses an
# attribute selector + !important to defeat inline-style specificity.
#
# Also adds:
#   - .ia-input / .ia-select get full-width on phones (they often have
#     style="max-width:260px" or similar that's wrong for phones)
#   - Form rows with a `style="display:flex"` get wrapped on phones
#   - Submit buttons stretch to full-width when on their own row
#
# Scoped to ≤600px so tablets and desktop keep their existing layouts.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== form-pages mobile CSS pass ==="

# Create a new dedicated stylesheet (cleaner than appending to mobile-nav.css
# which is getting long). Linked from app.blade.php.
cat > public/css/tenant/mobile-forms.css <<'CSS'
/* ================================================================
   Mobile form-pages override (≤600px).

   Targets inline-style grids on admin pages that were authored for
   desktop. The attribute selector matches any element whose style
   contains "grid-template-columns" — which on tenant admin pages
   means a multi-column form row.

   The `!important` is required to override inline styles. Acceptable
   here because the inline styles assume desktop geometry that's wrong
   on phones; this is a corrective, page-agnostic safety net.
   ================================================================ */

@media (max-width: 600px) {

  /* Any inline-styled grid collapses to 1 column on phones */
  [style*="grid-template-columns"] {
    grid-template-columns: 1fr !important;
    gap: 12px !important;
  }

  /* Resources & similar pages: existing rows with drag handle + N inputs
     get the same single-column treatment. The drag handle row should still
     show — just at the top, not as a left column. */
  .resource-row {
    grid-template-columns: 1fr !important;
    gap: 10px !important;
    padding: 14px 16px !important;
  }
  .resource-row .drag-handle {
    display: none; /* hide drag — tenants will re-order on desktop */
  }

  /* Inputs that had a fixed max-width go full-width on phones */
  .ia-input, .ia-select, select.ia-input {
    max-width: 100% !important;
    width: 100% !important;
  }

  /* Inline-styled flex rows (commonly used for toolbars) wrap on phones */
  [style*="display:flex"][style*="gap"] {
    flex-wrap: wrap;
  }

  /* "Add" / "Save" buttons at the end of a form row become full-width */
  .ia-card form .ia-btn--primary,
  .ia-card form button[type="submit"] {
    width: 100%;
    justify-content: center;
  }

  /* Color picker: now has full width since parent grid collapsed.
     Adjust the swatch wrap to render as a tidy 8-col grid instead of
     a flex-wrap that breaks at random spots. */
  .cp-swatches {
    display: grid !important;
    grid-template-columns: repeat(8, 1fr) !important;
    gap: 6px !important;
    max-width: 100%;
  }
  .cp-swatch {
    width: 100% !important;
    aspect-ratio: 1 / 1;
    height: auto !important;
  }

  /* Section labels (the small UPPERCASE "COLOR", "SUBTITLE" headings the
     Resources screenshot showed centered) get aligned to start */
  .ia-label, .ia-form-label {
    text-align: left;
  }

  /* Page head: stack title and any header actions */
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

  /* Cards: tighten padding so content has more room */
  .ia-card {
    padding: 14px 16px !important;
  }

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
echo "OK 1 (mobile-forms.css created)"

# Link the stylesheet in app.blade.php right after mobile-schedule.css
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/layouts/tenant/app.blade.php')
s = p.read_text()
if "mobile-forms.css" in s:
    print("SKIP 2 (CSS already linked)")
else:
    old = '<link rel="stylesheet" href="{{ asset(\'css/tenant/mobile-schedule.css\') }}?v={{ filemtime(public_path(\'css/tenant/mobile-schedule.css\')) }}">'
    new = old + '\n  <link rel="stylesheet" href="{{ asset(\'css/tenant/mobile-forms.css\') }}?v={{ filemtime(public_path(\'css/tenant/mobile-forms.css\')) }}">'
    assert s.count(old) == 1, f"mobile-schedule CSS link count={s.count(old)}, expected 1"
    p.write_text(s.replace(old, new))
    print("OK 2 (CSS linked)")
PY

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

verify "public/css/tenant/mobile-forms.css"             "grid-template-columns"        "inline-grid override"
verify "public/css/tenant/mobile-forms.css"             ".cp-swatches"                 "color picker grid rule"
verify "public/css/tenant/mobile-forms.css"             ".ia-toolbar"                  "toolbar wrap rule"
verify "resources/views/layouts/tenant/app.blade.php"   "mobile-forms.css"             "linked in layout"

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL"
  exit 1
fi

echo ""
echo "✓ all green"
echo ""
echo "Next:"
echo "  git add -A && git commit -m 'mobile: generic form-pages CSS pass — fixes Resources color picker + 15+ inline grids across 4 pages'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== form-pages CSS pass complete ==="
