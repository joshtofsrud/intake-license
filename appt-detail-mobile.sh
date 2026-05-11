#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Appointment detail — mobile polish + bottom-sheet conversion.
#
# Four changes, all scoped to ≤700px:
#   1. Status pipeline: render as horizontal scrollable pill chain on phones
#      (instead of vertical chain). Saves ~150px of vertical scroll.
#   2. Action stack: render as 2-column grid on phones (Mark in-progress |
#      Reschedule on row 1, Cancel full-width on row 2). Saves ~150px scroll.
#   3. Booking-notes modal → bottom sheet with drag handle.
#   4. Reschedule modal → bottom sheet with drag handle.
#
# Plus: declare @section('mobile-back', ...) so the back-button slot lights up
# on the detail page (back to schedule), and declare @section('mobile-fab',
# 'walk-in') for consistency with Today/Schedule.
#
# All changes scoped to ≤700px via @media — desktop and tablet (700-900px)
# untouched. The Layout B rail still stacks above main at ≤900px (existing
# behavior), but at ≤700px the rail contents reflow to mobile-optimized.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== appt detail mobile polish starting ==="

# ─────────────────────────────────────────────────────────────────────────────
# 1. Declare mobile-back + mobile-fab sections on the detail page.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
if "@section('mobile-fab'" in s:
    print("SKIP 1 (sections already declared)")
else:
    # Anchor on the first @section('content') after @extends.
    old = "@section('content')"
    # Build the new prelude. mobile-back goes to /admin/calendar with label "Schedule".
    # We do NOT use route() since this needs to feel like "back to where I came from"
    # and that's always Schedule for now (could later use HTTP referer).
    new = """@section('mobile-back', 'Schedule|' . route('tenant.calendar.index'))
@section('mobile-fab', 'walk-in')

@section('content')"""
    assert s.count(old) == 1, f"section-content anchor count={s.count(old)}, expected 1"
    p.write_text(s.replace(old, new))
    print("OK 1 (mobile-back + mobile-fab declared)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 2. Append the mobile polish CSS to the existing <style> block in show.blade.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
marker = '/* APPT-DETAIL-MOBILE v1 */'
if marker in s:
    print("SKIP 2 (mobile CSS already present)")
else:
    closer = "</style>"
    assert s.count(closer) == 1, f"</style> count={s.count(closer)}, expected 1"
    css = '''
/* APPT-DETAIL-MOBILE v1 — phone polish at ≤700px */
@media (max-width: 700px) {

  /* Tighten the hero band on phones */
  .appt-b-when {
    padding: 12px 14px;
  }
  .appt-b-when-time {
    font-size: 20px;
  }

  /* ── Status pipeline: vertical → horizontal pill chain ── */
  .appt-b-rail .appt-progress-card {
    padding: 10px 12px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }
  .appt-b-rail .appt-progress-bar {
    flex-direction: row !important;
    gap: 6px;
    align-items: center;
    min-width: max-content;
  }
  .appt-b-rail .appt-progress-step {
    flex-direction: row !important;
    padding: 4px 10px !important;
    border-radius: 99px;
    background: var(--ia-surface-2, rgba(255,255,255,.04));
    border: 0.5px solid var(--ia-border);
    flex-shrink: 0;
    gap: 6px;
  }
  .appt-b-rail .appt-progress-step::after {
    display: none !important;  /* no connecting line in horizontal mode */
  }
  .appt-b-rail .appt-progress-step.is-done {
    background: var(--ia-accent-soft);
    border-color: rgba(190,242,100,.3);
    color: var(--ia-accent);
  }
  .appt-b-rail .appt-progress-step.is-current {
    background: var(--ia-accent);
    border-color: var(--ia-accent);
    color: var(--ia-accent-text);
    font-weight: 600;
  }
  .appt-b-rail .appt-progress-dot {
    width: 12px; height: 12px;
  }
  .appt-b-rail .appt-progress-label {
    font-size: 12px;
    white-space: nowrap;
  }

  /* ── Action stack: vertical → 2-col grid ── */
  .appt-b-actions {
    display: grid !important;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
    padding: 6px;
  }
  .appt-b-actions .ia-btn {
    width: 100%;
    justify-content: center !important;
    padding: 10px 8px !important;
    font-size: 13px;
  }
  /* The "Reschedule shipping tomorrow" hint is no longer present, but keep
     the rule defensive for any future inline hint row. */
  .appt-b-action-coming-soon { display: none; }
  /* Divider spans full row */
  .appt-b-actions-divider { grid-column: 1 / -1; }
  /* Cancel button spans full row */
  .appt-b-cancel-btn { grid-column: 1 / -1; }

  /* ── Reschedule modal → bottom sheet ── */
  .resch-modal {
    align-items: flex-end !important;
    padding: 0 !important;
  }
  .resch-modal-card {
    max-width: 100% !important;
    width: 100%;
    border-top-left-radius: 18px;
    border-top-right-radius: 18px;
    border-bottom-left-radius: 0;
    border-bottom-right-radius: 0;
    max-height: 88vh;
    padding-bottom: env(safe-area-inset-bottom, 0);
    animation: appt-sheet-up 280ms cubic-bezier(.2, .8, .2, 1);
  }
  /* Drag handle */
  .resch-modal-card::before {
    content: '';
    display: block;
    width: 36px;
    height: 4px;
    background: var(--ia-text-dim, rgba(255,255,255,.18));
    border-radius: 2px;
    margin: 10px auto 0;
  }
  .resch-modal-head { padding-top: 10px; }
  .resch-modal-foot {
    flex-wrap: wrap;
    gap: 6px;
  }
  .resch-modal-foot .ia-btn { flex: 1; min-width: 0; }

  /* ── Booking-notes modal → bottom sheet ── */
  .appt-b-cust-modal {
    align-items: flex-end !important;
    padding: 0 !important;
  }
  .appt-b-cust-modal-card {
    max-width: 100% !important;
    width: 100%;
    border-top-left-radius: 18px;
    border-top-right-radius: 18px;
    border-bottom-left-radius: 0;
    border-bottom-right-radius: 0;
    max-height: 88vh;
    padding-bottom: env(safe-area-inset-bottom, 0);
    animation: appt-sheet-up 280ms cubic-bezier(.2, .8, .2, 1);
  }
  .appt-b-cust-modal-card::before {
    content: '';
    display: block;
    width: 36px;
    height: 4px;
    background: var(--ia-text-dim, rgba(255,255,255,.18));
    border-radius: 2px;
    margin: 10px auto 0;
  }
  .appt-b-cust-modal-head { padding-top: 10px; }
}

@keyframes appt-sheet-up {
  from { transform: translateY(100%); }
  to   { transform: translateY(0); }
}
'''
    s = s.replace(closer, css + closer)
    p.write_text(s)
    print("OK 2 (mobile polish CSS appended)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# Verification
# ─────────────────────────────────────────────────────────────────────────────
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

verify "resources/views/tenant/appointments/show.blade.php" "APPT-DETAIL-MOBILE v1"     "mobile CSS marker"
verify "resources/views/tenant/appointments/show.blade.php" "mobile-back"               "mobile-back declared"
verify "resources/views/tenant/appointments/show.blade.php" "@section('mobile-fab'"     "mobile-fab declared"
verify "resources/views/tenant/appointments/show.blade.php" "appt-sheet-up"             "sheet keyframes"

# Blade balance — make sure nothing is now broken.
python3 <<'PY'
src = open('resources/views/tenant/appointments/show.blade.php').read()
checks = [('@if','@endif'), ('@unless','@endunless'), ('@foreach','@endforeach'), ('@php','@endphp'), ('@push','@endpush')]
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

# <div balance
python3 <<'PY'
src = open('resources/views/tenant/appointments/show.blade.php').read()
opens = src.count('<div')
closes = src.count('</div')
import sys
if opens != closes:
    print(f'  ✗ <div MISMATCH: {opens}/{closes}')
    sys.exit(1)
print(f'  ✓ <div balance: {opens}/{closes}')
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
echo "  git add -A && git commit -m 'appt detail: mobile polish + bottom-sheet modals + back-button + FAB'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== appt detail mobile polish complete ==="
