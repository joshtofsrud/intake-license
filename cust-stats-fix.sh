#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Customer detail mobile stats — two bugs:
#
# 1. "Since" stat shows a float like "5.3978510271794 mo" instead of "5 mo".
#    Carbon 3's diffInMonths() returns a float by default (Carbon 2 returned
#    int). Same v2→v3 footgun as the carbon3-diff-fix.sh patch from earlier.
#    Fix: floor() the value.
#
# 2. "Visits" stat showed 0 for a customer with 16 timeline events because
#    we were only counting appointments in status 'completed'/'confirmed'/
#    'in_progress'. For a fitness studio, most attendance is class
#    registrations not classic appointments, so the count was always 0 for
#    yoga/fitness tenants. Fix: count timeline events with kind 'appointment'
#    or 'class_registration' from $timelineMonths.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== customer detail stats fix starting ==="

python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/customers/show.blade.php')
s = p.read_text()
marker = "CUST-STATS-FIX v1"
if marker in s:
    print("SKIP (already fixed)")
else:
    old = """@php
  // Compute mobile hero data
  $mobActiveMembership = isset($customerMemberships) ? $customerMemberships->where('status','active')->first() : null;
  $mobActivePacks = isset($customerPacks) ? $customerPacks->where('status','active') : collect();
  $mobLastVisit = $lastService ? \\Carbon\\Carbon::parse($lastService) : null;
  $mobMonthsSince = $customer->created_at->diffInMonths(now());
  $mobSinceLabel = $mobMonthsSince < 1 ? '<1 mo' : ($mobMonthsSince < 12 ? $mobMonthsSince . ' mo' : floor($mobMonthsSince / 12) . ' yr');
  $mobVisitCount = $appointments->whereIn('status', ['completed','confirmed','in_progress'])->count();
@endphp"""

    new = """@php
  // CUST-STATS-FIX v1 — Carbon 3 returns floats; coerce to int. Visit count
  // unified across appointments + class registrations for fitness tenants.
  $mobActiveMembership = isset($customerMemberships) ? $customerMemberships->where('status','active')->first() : null;
  $mobActivePacks = isset($customerPacks) ? $customerPacks->where('status','active') : collect();
  $mobLastVisit = $lastService ? \\Carbon\\Carbon::parse($lastService) : null;

  // Use timestamp math instead of Carbon diffInMonths to avoid the Carbon 3
  // float-return footgun (cf. carbon3-diff-fix.sh for the analogous diffInMinutes
  // sign-flip bug).
  $mobMonthsSinceFloat = ((now()->getTimestamp() - $customer->created_at->getTimestamp()) / (60 * 60 * 24 * 30.44));
  $mobMonthsSince = (int) floor($mobMonthsSinceFloat);
  if ($mobMonthsSince < 1) {
    $mobSinceLabel = '<1 mo';
  } elseif ($mobMonthsSince < 12) {
    $mobSinceLabel = $mobMonthsSince . ' mo';
  } else {
    $mobSinceLabel = ((int) floor($mobMonthsSince / 12)) . ' yr';
  }

  // Visits count = appointments in attended states + class registrations.
  // Iterate $timelineMonths (already grouped collection passed from controller)
  // because the flat $timelineEvents isn't in scope here.
  $mobVisitCount = 0;
  foreach ($timelineMonths as $month) {
    foreach ($month['events'] as $e) {
      if ($e['kind'] === 'class_registration') {
        $mobVisitCount++;
      } elseif ($e['kind'] === 'appointment'
                && in_array(strtolower((string)($e['status_key'] ?? $e['status'] ?? '')),
                            ['completed', 'confirmed', 'in_progress', 'in progress', 'shipped', 'closed'])) {
        $mobVisitCount++;
      }
    }
  }
@endphp"""

    n = s.count(old)
    if n != 1:
        print(f"ABORT: anchor count = {n}, expected 1")
        raise SystemExit(1)
    s = s.replace(old, new)
    p.write_text(s)
    print("OK 1 (stats computation fixed)")
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

verify        "resources/views/tenant/customers/show.blade.php" "CUST-STATS-FIX v1"                              "marker"
verify        "resources/views/tenant/customers/show.blade.php" "(int) floor("                                    "int cast"
verify        "resources/views/tenant/customers/show.blade.php" "kind'] === 'class_registration'"                 "class registration count"
verify_absent "resources/views/tenant/customers/show.blade.php" "diffInMonths(now())"                             "old Carbon call removed"

# Blade balance
python3 <<'PY'
import sys
src = open('resources/views/tenant/customers/show.blade.php').read()
checks = [('@if','@endif'), ('@foreach','@endforeach'), ('@php','@endphp'), ('@push','@endpush'), ('@forelse','@endforelse')]
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
echo "Deploy:"
echo "  git add -A && git commit -m 'fix: customer detail mobile stats — float month count + visit count for fitness tenants'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== fix complete ==="
