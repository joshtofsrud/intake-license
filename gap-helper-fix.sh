#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Gap helper fix: don't rely on $appt->appointment_date.
#
# Root cause: CalendarController::dayView() selects only the columns the
# desktop grid needs:
#   ['id', 'resource_id', 'customer_first_name', 'customer_last_name',
#    'appointment_time', 'appointment_end_time', 'total_duration_minutes',
#    'status', 'total_cents', 'needs_time_review']
# Notably absent: appointment_date. The grid knows the date from $dateStr;
# the model rows don't need to carry it.
#
# But my $msComputeGap helper called $appt->appointment_date->toDateString(),
# which throws because the property is null. The try/catch silently returned
# null → no gap row.
#
# Fix: pass $dateStr (known by the controller, already in scope in the
# partial) into the helper, instead of pulling it from each appointment.
# The day view by definition only renders appointments for one date, so
# this is exactly equivalent — and works regardless of which fields the
# controller chose to select.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== gap helper fix starting ==="

# 1. Fix the helper to take a $dayStr param and use that.
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/calendar/_mobile-schedule.blade.php')
s = p.read_text()
marker = "GAP-HELPER-USE-DATESTR v1"
if marker in s:
    print("SKIP 1 (helper already fixed)")
else:
    old = '''  // Gap calculation helper (day mode only).
  // Returns [minutesGap, gapStartTimeStr, gapEndTimeStr] or null if no
  // meaningful gap. End-of-prev = appointment_time + duration if end_time
  // not stored. Min threshold: 15 min.
  $msComputeGap = function ($prev, $curr) {
    if (!$prev || !$curr) return null;
    if (!$prev->appointment_time || !$curr->appointment_time) return null;
    try {
      $prevStart = Cb::parse($prev->appointment_date->toDateString() . ' ' . $prev->appointment_time);
      $prevDur   = (int) ($prev->total_duration_minutes ?? 0);
      $prevEnd   = $prev->appointment_end_time
        ? Cb::parse($prev->appointment_date->toDateString() . ' ' . $prev->appointment_end_time)
        : $prevStart->copy()->addMinutes($prevDur);
      $currStart = Cb::parse($curr->appointment_date->toDateString() . ' ' . $curr->appointment_time);
      // CARBON3-DIFF-FIX v1: timestamp math — Carbon 3's diffInMinutes(false)
      // returns negative when the argument is later than $this. Using raw
      // timestamps avoids version-specific sign behaviour.
      $gap = (int) round(($currStart->getTimestamp() - $prevEnd->getTimestamp()) / 60);
      if ($gap < 15) return null;
      return [
        'minutes' => $gap,
        'fromStr' => $prevEnd->format('g:i'),
        'toStr'   => $currStart->format('g:i'),
      ];
    } catch (\\Throwable $e) {
      return null;
    }
  };'''
    new = '''  // Gap calculation helper (day mode only) — GAP-HELPER-USE-DATESTR v1
  // Takes the day's date string instead of pulling from $appt->appointment_date,
  // because the controller's day-view query doesn't hydrate that column.
  // All appointments in a single day view share the same date, so passing
  // $dayStr explicitly is exactly equivalent and avoids the null-field throw.
  // Min threshold: 15 min.
  $msComputeGap = function ($prev, $curr, $dayStr) {
    if (!$prev || !$curr) return null;
    if (!$prev->appointment_time || !$curr->appointment_time) return null;
    if (!$dayStr) return null;
    try {
      $prevStart = Cb::parse($dayStr . ' ' . $prev->appointment_time);
      $prevDur   = (int) ($prev->total_duration_minutes ?? 0);
      $prevEnd   = $prev->appointment_end_time
        ? Cb::parse($dayStr . ' ' . $prev->appointment_end_time)
        : $prevStart->copy()->addMinutes($prevDur);
      $currStart = Cb::parse($dayStr . ' ' . $curr->appointment_time);
      // Timestamp math — version-proof across Carbon 2/3.
      $gap = (int) round(($currStart->getTimestamp() - $prevEnd->getTimestamp()) / 60);
      if ($gap < 15) return null;
      return [
        'minutes' => $gap,
        'fromStr' => $prevEnd->format('g:i'),
        'toStr'   => $currStart->format('g:i'),
      ];
    } catch (\\Throwable $e) {
      return null;
    }
  };'''
    assert s.count(old) == 1, f"helper anchor count={s.count(old)}, expected 1"
    p.write_text(s.replace(old, new))
    print("OK 1 (helper signature updated)")
PY

# 2. Update the one call site in the day-view loop to pass $msAnchorDateStr.
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/calendar/_mobile-schedule.blade.php')
s = p.read_text()
old = '@php $gap = $msComputeGap($msPrevAppt, $appt); @endphp'
new = '@php $gap = $msComputeGap($msPrevAppt, $appt, $msAnchorDateStr); @endphp'
n = s.count(old)
if n == 0:
    print("SKIP 2 (call site already updated)")
else:
    assert n == 1, f"call-site count={n}, expected 1"
    p.write_text(s.replace(old, new))
    print("OK 2 (call site passes dayStr)")
PY

# 3. Remove the debug banner — it's served its purpose.
python3 <<'PY'
from pathlib import Path
import re
p = Path('resources/views/tenant/calendar/_mobile-schedule.blade.php')
s = p.read_text()
if "MSCHED-DEBUG-BANNER" not in s:
    print("SKIP 3 (debug banner already removed)")
else:
    # Banner spans from the marker comment through the closing </div> right
    # before the actual page content. Use a non-greedy regex.
    pattern = re.compile(
        r"\n  \{\{-- MSCHED-DEBUG-BANNER.*?</div>\n",
        re.DOTALL
    )
    s_new, n = pattern.subn('', s, count=1)
    if n != 1:
        print(f"WARN: banner removal regex matched {n} times — manual cleanup needed")
    else:
        p.write_text(s_new)
        print("OK 3 (debug banner removed)")
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

verify        "resources/views/tenant/calendar/_mobile-schedule.blade.php"  "GAP-HELPER-USE-DATESTR v1"          "helper fix marker"
verify        "resources/views/tenant/calendar/_mobile-schedule.blade.php"  "function (\$prev, \$curr, \$dayStr)" "helper new signature"
verify        "resources/views/tenant/calendar/_mobile-schedule.blade.php"  "\$msComputeGap(\$msPrevAppt, \$appt, \$msAnchorDateStr)" "call site passes dayStr"
verify_absent "resources/views/tenant/calendar/_mobile-schedule.blade.php"  "appointment_date->toDateString" "old broken date access gone"
verify_absent "resources/views/tenant/calendar/_mobile-schedule.blade.php"  "MSCHED-DEBUG-BANNER"            "debug banner removed"

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL"
  exit 1
fi

echo ""
echo "✓ all green"
echo ""
echo "Next:"
echo "  git add -A && git commit -m 'fix: mobile schedule gap calc — use known dateStr, model row does not carry appointment_date'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== gap helper fix complete ==="
