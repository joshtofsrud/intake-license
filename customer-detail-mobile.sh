#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Customer detail page — mobile polish.
#
# Three issues visible in screenshot:
#   1. Page head: desktop has "← Back" + "+ New appointment" inline. The
#      mobile top-bar already shows ‹ Schedule, so the body-level Back is
#      redundant. Hide it on mobile and let the New appointment button
#      stretch to full-width.
#   2. .ia-card-head used by "Memberships & Packs" and "Activity" sections
#      tries to keep title + actions on one line. On mobile this crushes
#      the title to wrap awkwardly while the buttons stay inline. Force
#      stack: title on one line, actions below on a new line.
#   3. .act-row uses `grid-template-columns: 28px 60px 1fr auto auto` (5
#      cols). On phones, reflow to 2-row: row 1 has icon + date + title,
#      row 2 has status pill + amount on the right.
#
# Plus: add @section('mobile-back') so the top-bar shows "‹ Customers"
# instead of nothing (currently uses dashboard.tenant or no fallback).
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== customer detail mobile polish ==="

# ─────────────────────────────────────────────────────────────────────────────
# 1. Add @section('mobile-back') to customer detail page.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/customers/show.blade.php')
s = p.read_text()
if "@section('mobile-back'" in s:
    print("SKIP 1 (mobile-back already declared)")
else:
    old = "@section('content')"
    new = "@section('mobile-back', 'Customers|' . route('tenant.customers.index'))\n\n@section('content')"
    if s.count(old) != 1:
        print(f"SKIP 1 (section('content') count={s.count(old)}, expected 1)")
    else:
        p.write_text(s.replace(old, new))
        print("OK 1 (mobile-back declared)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 2. Append mobile polish CSS to the existing <style> block.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/customers/show.blade.php')
s = p.read_text()
marker = "CUSTOMER-MOBILE-POLISH v1"
if marker in s:
    print("SKIP 2 (mobile CSS already present)")
else:
    # Anchor: the existing @media (max-width: 900px) block. Inject after it.
    old = '''@media (max-width: 900px) {
  .cust-layout { grid-template-columns: 1fr; }
  .cust-info-grid { grid-template-columns: 1fr; }
}'''
    new = '''@media (max-width: 900px) {
  .cust-layout { grid-template-columns: 1fr; }
  .cust-info-grid { grid-template-columns: 1fr; }
}

/* CUSTOMER-MOBILE-POLISH v1 — phone polish at ≤600px */
@media (max-width: 600px) {

  /* Hide the page-level Back; top-bar already has ‹ Customers chevron */
  .ia-page-actions .ia-btn--ghost { display: none; }

  /* "+ New appointment" goes full-width on phones */
  .ia-page-actions .ia-btn--primary {
    width: 100%;
    justify-content: center;
  }

  /* Card headers (Memberships & Packs, Activity): stack title above actions */
  .ia-card-head {
    flex-direction: column !important;
    align-items: flex-start !important;
    gap: 8px;
  }
  .ia-card-head > div[style*="display:flex"] {
    width: 100%;
    flex-wrap: wrap;
    gap: 8px !important;
  }
  .ia-card-head .ia-btn--sm {
    flex: 1;
    justify-content: center;
    min-width: 0;
  }
  /* The Activity filter select stretches to fill the row */
  .ia-card-head #activity-filter {
    flex: 1;
    min-width: 0;
  }

  /* Activity rows: reflow 5-col grid into a compact 2-row layout */
  .act-row {
    grid-template-columns: 28px 1fr auto !important;
    grid-template-rows: auto auto;
    gap: 6px 10px !important;
    padding: 12px 4px !important;
  }
  .act-icon { grid-row: 1 / 3; align-self: start; }
  .act-date {
    grid-column: 2 / 4;
    grid-row: 1;
    font-size: 10px;
    margin-bottom: -2px;
  }
  .act-main {
    grid-column: 2;
    grid-row: 2;
    min-width: 0;
  }
  .act-title { font-size: 13px; }
  .act-id { display: block; margin-left: 0; margin-top: 1px; font-size: 11px; }
  .act-sub { font-size: 11px; }
  .act-pill {
    grid-column: 3;
    grid-row: 2;
    align-self: center;
    font-size: 10px !important;
    padding: 2px 6px !important;
  }
  .act-amount {
    grid-column: 3;
    grid-row: 1;
    text-align: right;
    align-self: center;
    font-size: 12px;
    font-weight: 500;
  }
}'''
    assert s.count(old) == 1, f"anchor count={s.count(old)}, expected 1"
    p.write_text(s.replace(old, new))
    print("OK 2 (mobile polish CSS appended)")
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
verify "resources/views/tenant/customers/show.blade.php"  "CUSTOMER-MOBILE-POLISH v1"      "mobile CSS marker"
verify "resources/views/tenant/customers/show.blade.php"  "mobile-back"                    "mobile-back declared"
verify "resources/views/tenant/customers/show.blade.php"  ".ia-card-head > div"            "card-head action stack rule"
verify "resources/views/tenant/customers/show.blade.php"  ".act-row {"                     "act-row reflow rule"

# Blade balance
python3 <<'PY'
src = open('resources/views/tenant/customers/show.blade.php').read()
checks = [('@if','@endif'), ('@foreach','@endforeach'), ('@php','@endphp')]
import sys
ok = True
for o, c in checks:
    no, nc = src.count(o), src.count(c)
    if no != nc:
        print(f'  ✗ {o}({no}) != {c}({nc})')
        ok = False
    else:
        print(f'  ✓ {o}/{c}: {no}')
if not ok: sys.exit(1)
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
echo "  git add -A && git commit -m 'mobile: customer detail polish — card heads stack, act-row reflows, hide duplicate back'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== customer detail polish complete ==="
